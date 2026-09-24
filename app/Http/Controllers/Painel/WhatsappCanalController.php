<?php

namespace App\Http\Controllers\Painel;

use App\Http\Controllers\Controller;
use App\Jobs\SincronizarAgendaWhatsAppJob;
use App\Models\Kanban;
use App\Models\WhatsappCanal;
use App\Services\MessengerProprioService;
use App\Services\UazapiService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Fase 4 do plano do canal WhatsApp Messenger próprio (23/09) — provider-aware:
 * store() decide o provider pelo app pedido ('messenger' => messenger_proprio,
 * senão uazapi, igual sempre foi); status()/qrcode()/destroy() resolvem o
 * cliente certo por $canal->provider. Segue o mesmo padrão "um método por
 * provider" já usado em MediaProcessorService, em vez de esconder as duas
 * APIs atrás de uma interface genérica — MessengerProprioService usa sessionId
 * (não token por instância) e não tem QR/status/delete com a mesma forma da
 * Uazapi, então uma abstração comum só adicionaria indireção sem reduzir
 * duplicação real.
 */
class WhatsappCanalController extends Controller
{
    public function __construct(
        private UazapiService $uazapi,
        private MessengerProprioService $messenger,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $query = WhatsappCanal::where('tenant_id', $request->user()->tenant_id)
            ->where('tipo', 'nao_oficial');

        // Achado real 2026-08-19: WhatsApp Business e WhatsApp Messenger são apps
        // diferentes por trás da mesma conexão não-oficial (Uazapi/Baileys) — sem
        // filtrar por app, a tela de Configurações não sabe separar os dois blocos.
        // Parâmetro opcional: quem não passa (ex. integrações antigas) continua
        // vendo todos os não-oficiais juntos, sem quebrar nada existente.
        if ($request->filled('app')) {
            $query->where('app', $request->query('app'));
        }

        $canais = $query->orderBy('id')->get(['id', 'status', 'phone', 'connected_since', 'app']);

        return response()->json($canais);
    }

    public function store(Request $request): JsonResponse
    {
        $tenantId = $request->user()->tenant_id;

        // Default 'business' preserva o comportamento de antes desta mudança
        // (todo canal não-oficial existente era, na prática, WhatsApp Business).
        $app = $request->input('app', 'business');

        $canal = $app === 'messenger'
            ? $this->criarCanalMessengerProprio($tenantId)
            : $this->criarCanalUazapi($tenantId, $app);

        if (! $canal) {
            return response()->json(['message' => 'Erro ao criar instância WhatsApp. Tente novamente.'], 500);
        }

        // Vincula o canal recém-criado a TODOS os Kanbans do tenant — decisão
        // do produto: um número novo já entra disponível pra prospecção, em vez de
        // ficar invisível até alguém visitar /kanban/config e vincular manualmente
        // (mesmo padrão da migration de backfill do Task 3).
        $kanbanIds = Kanban::where('tenant_id', $tenantId)->pluck('id');
        $canal->kanbans()->syncWithoutDetaching($kanbanIds);

        return response()->json(['id' => $canal->id, 'status' => $canal->status], 201);
    }

    private function criarCanalUazapi(int $tenantId, string $app): ?WhatsappCanal
    {
        $nome   = 'tenant-' . $tenantId . '-' . Str::random(6);
        $result = $this->uazapi->criarInstancia($nome);

        if (! $result || ! $result['token']) {
            return null;
        }

        $webhookToken = Str::random(48);

        $canal = WhatsappCanal::create([
            'tenant_id'     => $tenantId,
            'tipo'          => 'nao_oficial',
            'provider'      => 'uazapi',
            'app'           => $app,
            // Número recém-conectado é sempre dia zero de aquecimento — mesmo se
            // o chip físico já for antigo, o WhatsApp julga pela atividade dentro
            // do app, não pela idade do SIM. Perfil default 'protegido' (mais
            // conservador); quem for número de prospecção troca depois.
            'aquecimento_iniciado_em' => now(),
            'status'        => 'connecting',
            'webhook_token' => $webhookToken,
            'config'        => [
                'instance_name'  => $result['name'],
                'instance_token' => $result['token'],
            ],
        ]);

        $webhookUrl = config('app.url') . '/api/webhook/uazapi/' . $webhookToken;
        $this->uazapi->configurarWebhook($result['token'], $webhookUrl, ['messages', 'connection']);

        return $canal;
    }

    private function criarCanalMessengerProprio(int $tenantId): ?WhatsappCanal
    {
        $sessionId    = 'tenant-' . $tenantId . '-' . Str::random(6);
        $webhookToken = Str::random(48);
        $webhookUrl   = config('app.url') . '/api/webhook/messenger-proprio/' . $webhookToken;

        if (! $this->messenger->criarSessao($sessionId, $webhookUrl)) {
            return null;
        }

        return WhatsappCanal::create([
            'tenant_id'     => $tenantId,
            'tipo'          => 'nao_oficial',
            'provider'      => 'messenger_proprio',
            'app'           => 'messenger',
            'aquecimento_iniciado_em' => now(),
            'status'        => 'connecting',
            'webhook_token' => $webhookToken,
            'config'        => ['session_id' => $sessionId],
        ]);
    }

    public function status(WhatsappCanal $canal): JsonResponse
    {
        abort_if($canal->tenant_id !== auth()->user()->tenant_id, 404);

        if ($canal->provider === 'messenger_proprio') {
            $data      = $this->messenger->status($canal->sessionIdMessengerProprio() ?? '');
            $connected = $data['conectado'] ?? false;
            $phone     = $data['phone'] ?? null;
        } else {
            $data      = $this->uazapi->status($canal->tokenUazapi());
            $connected = $data['status']['connected'] ?? false;
            $phone     = $data['status']['phone'] ?? null;
        }

        if ($connected && $canal->status !== 'connected') {
            $canal->update([
                'status'          => 'connected',
                'phone'           => $phone,
                'connected_since' => now(),
            ]);

            SincronizarAgendaWhatsAppJob::dispatch($canal->id)->delay(now()->addSeconds(10));
        }

        return response()->json([
            'status'          => $connected ? 'connected' : 'disconnected',
            'phone'           => $canal->fresh()->phone,
            'connected_since' => $canal->connected_since,
        ]);
    }

    public function qrcode(WhatsappCanal $canal): JsonResponse
    {
        abort_if($canal->tenant_id !== auth()->user()->tenant_id, 404);

        if ($canal->provider === 'messenger_proprio') {
            $sessionId  = $canal->sessionIdMessengerProprio() ?? '';
            $statusData = $this->messenger->status($sessionId);
            $conectado  = $statusData['conectado'] ?? false;
            $qr         = $conectado ? null : $this->messenger->conectar($sessionId);
        } else {
            $statusData = $this->uazapi->status($canal->tokenUazapi());
            $conectado  = $statusData['status']['connected'] ?? false;
            $qr         = $conectado ? null : $this->uazapi->conectar($canal->tokenUazapi());
        }

        if ($conectado) {
            return response()->json(['message' => 'WhatsApp já está conectado.'], 409);
        }

        if (! $qr) {
            return response()->json([
                'message' => 'QR Code ainda não disponível. Aguarde alguns segundos e tente novamente.',
            ], 503);
        }

        $qr = preg_replace('/^data:image\/[^;]+;base64,/', '', $qr);

        return response()->json(['qrcode' => $qr]);
    }

    public function destroy(WhatsappCanal $canal): JsonResponse
    {
        abort_if($canal->tenant_id !== auth()->user()->tenant_id, 404);

        // Achado real 2026-08-20: o retorno de deletarInstancia() nunca era
        // checado — se a exclusão na Uazapi falhasse (ou o token já estivesse
        // inválido), o registro local sumia mesmo assim e a instância ficava
        // órfã do lado de lá, sem token salvo em lugar nenhum pra apagar depois.
        // Isso já tinha acontecido pelo menos 2x antes (test-buttons, tenant-1 —
        // o Frete Rio antigo) e bateu o limite de instâncias da conta. Agora,
        // se a Uazapi falhar, o registro local NÃO é apagado — fica visível pro
        // franqueado tentar de novo, em vez de sumir e virar órfão silencioso.
        // Mesma regra aplicada ao Messenger próprio: sessão órfã no Node é bem
        // menos grave (não conta pra nenhum limite de conta externo), mas o
        // padrão de nunca perder o registro local antes de confirmar a exclusão
        // remota é mantido igual pelos dois.
        if ($canal->provider === 'messenger_proprio') {
            $sessionId = $canal->sessionIdMessengerProprio();

            if ($sessionId && ! $this->messenger->deletarSessao($sessionId)) {
                \Illuminate\Support\Facades\Log::warning('WhatsappCanalController: falha ao remover sessão no Messenger próprio, canal local mantido', [
                    'canal_id' => $canal->id, 'tenant_id' => $canal->tenant_id,
                ]);

                return response()->json(['message' => 'Não foi possível remover a sessão no WhatsApp Messenger. Tente novamente em instantes.'], 500);
            }
        } else {
            $token = $canal->tokenUazapi();

            if ($token && ! $this->uazapi->deletarInstancia($token)) {
                \Illuminate\Support\Facades\Log::warning('WhatsappCanalController: falha ao remover instância na Uazapi, canal local mantido', [
                    'canal_id' => $canal->id, 'tenant_id' => $canal->tenant_id,
                ]);

                return response()->json(['message' => 'Não foi possível remover o número na Uazapi. Tente novamente em instantes.'], 500);
            }
        }

        $canal->delete();

        return response()->json(['excluido' => true]);
    }
}
