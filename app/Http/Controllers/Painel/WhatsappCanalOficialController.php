<?php

namespace App\Http\Controllers\Painel;

use App\Http\Controllers\Controller;
use App\Models\Kanban;
use App\Models\WhatsappCanal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class WhatsappCanalOficialController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $canais = WhatsappCanal::where('tenant_id', $request->user()->tenant_id)
            ->where('tipo', 'oficial')
            ->orderBy('id')
            ->get(['id', 'status', 'phone', 'connected_since', 'config']);

        // phone_number_id não é segredo, mas o resto de config (webhook_secret) sim —
        // devolve só o phone_number_id pro frontend saber mostrar, nunca o segredo.
        $canais->transform(function ($canal) {
            $canal->phone_number_id = $canal->config['phone_number_id'] ?? null;
            unset($canal->config);
            return $canal;
        });

        return response()->json($canais);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'phone_number_id' => 'required|string|max:100',
            'telefone'        => 'required|string|max:20',
            'apelido'         => 'nullable|string|max:100',
        ]);

        $tenantId = $request->user()->tenant_id;

        // Achado Crítico 2 da revisão final: as credenciais da Covercut são globais
        // (um phone_number_id só pode estar registrado com UM webhook_secret por vez
        // do lado da Covercut), então a checagem de duplicidade tem que ser GLOBAL
        // entre todos os tenants — nunca escopada por tenant_id. Sem isso, outro
        // tenant podia "adotar" o número de outro franqueado, rotacionando o
        // webhook_secret na Covercut e derrubando o canal original (sequestro).
        $jaExiste = WhatsappCanal::withoutGlobalScopes()
            ->where('provider', 'covercut')
            ->whereJsonContains('config->phone_number_id', $validated['phone_number_id'])
            ->exists();

        if ($jaExiste) {
            return response()->json(['message' => 'Este número já está em uso por outra conta.'], 422);
        }

        $webhookUrl = config('app.url') . '/api/webhook/covercut';
        $baseUrl    = config('services.covercut.base_url');

        try {
            $response = Http::withHeaders([
                    'X-API-Key'    => config('services.covercut.api_key'),
                    'X-API-Secret' => config('services.covercut.api_secret'),
                ])
                ->post("{$baseUrl}/numbers/webhook", [
                    'from'        => $validated['phone_number_id'],
                    'webhook_url' => $webhookUrl,
                    'enabled'     => true,
                ]);
        } catch (\Throwable $e) {
            // Http::post lança ConnectionException em falhas de rede (DNS, timeout, TLS,
            // conexão recusada) — mesmo tratamento de CovercutChannelService::enviarTexto().
            Log::warning('WhatsappCanalOficialController: exceção ao registrar webhook na Covercut', [
                'phone_number_id' => $validated['phone_number_id'],
                'erro'            => $e->getMessage(),
            ]);

            return response()->json(['message' => 'Erro ao registrar o webhook na Covercut. Confira o phone_number_id.'], 502);
        }

        if (! $response->successful()) {
            return response()->json(['message' => 'Erro ao registrar o webhook na Covercut. Confira o phone_number_id.'], 502);
        }

        $webhookSecret = $response->json('webhook_secret');

        // Achado Importante 4 da revisão final: se a Covercut responder 200 sem
        // webhook_secret, o canal ficava criado com segredo null pra sempre — toda
        // assinatura de webhook inbound falharia (validarAssinatura() retorna false
        // quando $segredo é null), um canal morto sem retorno possível. Barra ANTES
        // de criar a linha.
        if (empty($webhookSecret)) {
            Log::warning('WhatsappCanalOficialController: Covercut respondeu sem webhook_secret', [
                'phone_number_id' => $validated['phone_number_id'],
            ]);

            return response()->json(['message' => 'Erro ao registrar o webhook na Covercut. Confira o phone_number_id.'], 502);
        }

        $canal = WhatsappCanal::create([
            'tenant_id' => $tenantId,
            'tipo'      => 'oficial',
            'provider'  => 'covercut',
            'status'    => 'connected',
            'phone'     => $validated['telefone'],
            'connected_since' => now(),
            'config'    => [
                'phone_number_id' => $validated['phone_number_id'],
                'webhook_secret'  => $webhookSecret,
                'apelido'         => $validated['apelido'] ?? null,
            ],
        ]);

        // Mesmo padrão já usado para número não-oficial (WhatsappCanalController::store):
        // vincula a todos os Kanbans do tenant, pra rotear mensagem inbound sem passo manual.
        $kanbanIds = Kanban::where('tenant_id', $tenantId)->pluck('id');
        $canal->kanbans()->syncWithoutDetaching($kanbanIds);

        return response()->json(['id' => $canal->id, 'status' => $canal->status], 201);
    }

    public function destroy(WhatsappCanal $canal): JsonResponse
    {
        abort_if($canal->tenant_id !== auth()->user()->tenant_id, 404);
        abort_if($canal->tipo !== 'oficial', 404);

        $baseUrl = config('services.covercut.base_url');
        $phoneNumberId = $canal->config['phone_number_id'] ?? null;

        if ($phoneNumberId) {
            try {
                Http::withHeaders([
                        'X-API-Key'    => config('services.covercut.api_key'),
                        'X-API-Secret' => config('services.covercut.api_secret'),
                    ])
                    ->post("{$baseUrl}/numbers/webhook", ['from' => $phoneNumberId, 'action' => 'delete']);
            } catch (\Throwable $e) {
                // Best-effort: a remoção do canal local não pode ficar refém da Covercut
                // estar fora do ar. Loga e segue para excluir a linha local mesmo assim.
                Log::warning('WhatsappCanalOficialController: exceção ao desregistrar webhook na Covercut', [
                    'canal_id'        => $canal->id,
                    'phone_number_id' => $phoneNumberId,
                    'erro'            => $e->getMessage(),
                ]);
            }
        }

        $canal->delete();

        return response()->json(['excluido' => true]);
    }
}
