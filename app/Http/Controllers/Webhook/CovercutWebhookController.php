<?php

namespace App\Http\Controllers\Webhook;

use App\Http\Controllers\Controller;
use App\Jobs\SdrResponderJob;
use App\Models\Contato;
use App\Models\KanbanColuna;
use App\Models\Mensagem;
use App\Models\TicketAtendimento;
use App\Models\VinculoContatoTenant;
use App\Models\WhatsappCanal;
use App\Services\SequenciaService;
use App\Services\TelefoneService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Webhook do canal oficial (Covercut/Meta Cloud API). MVP: só texto — sem mídia,
 * sem botão, sem chamada de voz (fora de escopo, ver seção 8 do design técnico).
 * Deliberadamente autocontido (não reusa UazapiWebhookController) — ver Architecture
 * no cabeçalho do plano.
 */
class CovercutWebhookController extends Controller
{
    public function handle(Request $request): JsonResponse
    {
        $payload = $request->all();

        // A Covercut identifica nosso número de destino em `from_number_id` (campo
        // top-level do payload real) — `to`/`phone_number_id` ficam como fallback
        // tolerante, mas não são o formato documentado.
        $phoneNumberId = $payload['from_number_id'] ?? $payload['to'] ?? $payload['phone_number_id'] ?? null;

        if (! $phoneNumberId) {
            Log::warning('Covercut webhook: payload sem from_number_id/to/phone_number_id identificável');
            abort(400);
        }

        $canal = WhatsappCanal::withoutGlobalScopes()
            ->where('provider', 'covercut')
            ->whereJsonContains('config->phone_number_id', $phoneNumberId)
            ->first();

        if (! $canal) {
            Log::warning('Covercut webhook: nenhum canal encontrado para phone_number_id', ['phone_number_id' => $phoneNumberId]);
            abort(404);
        }

        $assinaturaValida = $this->validarAssinatura($request, $canal);
        if (! $assinaturaValida) {
            Log::warning('Covercut webhook: assinatura inválida', ['canal_id' => $canal->id]);
            abort(401);
        }

        if (($payload['event'] ?? null) !== 'message' || ($payload['direction'] ?? null) !== 'inbound') {
            return response()->json(['ok' => true]); // evento que não é mensagem de entrada — ignora silenciosamente
        }

        $this->processarMensagem($payload, $canal);

        return response()->json(['ok' => true]);
    }

    private function validarAssinatura(Request $request, WhatsappCanal $canal): bool
    {
        $segredo = $canal->config['webhook_secret'] ?? null;
        if (! $segredo) {
            return false;
        }

        $assinaturaRecebida = $request->header('X-BSP-Signature', '');
        $assinaturaCalculada = hash_hmac('sha256', $request->getContent(), $segredo);

        return hash_equals($assinaturaCalculada, $assinaturaRecebida);
    }

    private function processarMensagem(array $payload, WhatsappCanal $canal): void
    {
        $tenant = $canal->tenant;

        $messageId = $payload['message']['id'] ?? null;

        if ($messageId && Mensagem::withoutGlobalScopes()->where('provider_message_id', $messageId)->exists()) {
            Log::debug('Covercut webhook: mensagem duplicada ignorada', ['id' => $messageId]);
            return;
        }

        $telefoneRaw = $payload['contact']['wa_id'] ?? null;
        if (! $telefoneRaw) {
            return;
        }
        $telefone = $this->normalizarTelefone($telefoneRaw);

        // `message.text` chega como STRING simples no payload real da Covercut
        // (ex.: "text": "Ola"), não como objeto `{body: ...}` — o formato Meta
        // Cloud API "cru" seria `text.body`, então a leitura tolera os dois.
        $conteudo = $payload['message']['text']['body'] ?? ($payload['message']['text'] ?? null);
        $pushName = $payload['contact']['name'] ?? null;

        $temReferralAnuncio = isset($payload['message']['referral']) || isset($payload['message']['ctwa_clid']);
        $janelaExpiraEm = $temReferralAnuncio ? now()->addHours(72) : now()->addHours(24);

        $contato = Contato::firstOrCreate(['telefone' => $telefone], ['nome' => $pushName ?: 'Sem Nome', 'origem' => 'whatsapp']);

        VinculoContatoTenant::firstOrCreate(['contato_id' => $contato->id, 'tenant_id' => $tenant->id]);

        $ticket = TicketAtendimento::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->where('contato_id', $contato->id)
            ->whereIn('status', ['aberto', 'aguardando'])
            ->latest()
            ->first();

        $ticketNovo = false;

        if ($ticket) {
            $ticket->update([
                'whatsapp_canal_id'     => $canal->id,
                'janela_expira_em'      => $janelaExpiraEm,
                'janela_origem_anuncio' => $temReferralAnuncio,
            ]);
        } else {
            $persona = $tenant->personas()->where('is_default', true)->where('ativo', true)->first();

            $ticket = TicketAtendimento::create([
                'tenant_id'             => $tenant->id,
                'contato_id'            => $contato->id,
                'whatsapp_canal_id'     => $canal->id,
                'coluna_kanban'         => KanbanColuna::chaveDeEntrada($tenant->id),
                'agente_responsavel'    => 'bot',
                'sdr_persona_id'        => $persona?->id,
                'status'                => 'aberto',
                'origem'                => $temReferralAnuncio ? 'anuncio_meta' : 'whatsapp',
                'aberto_em'             => now(),
                'janela_expira_em'      => $janelaExpiraEm,
                'janela_origem_anuncio' => $temReferralAnuncio,
            ]);
            $ticketNovo = true;
        }

        if ($conteudo) {
            Mensagem::create([
                'ticket_id'            => $ticket->id,
                'tenant_id'            => $tenant->id,
                'remetente'            => 'lead',
                'tipo'                 => 'texto',
                'conteudo'             => $conteudo,
                'provider_message_id'  => $messageId,
                'enviado_em'           => now(),
            ]);
        }

        if ($ticket->followup_estagio_enviado !== 0) {
            $ticket->update(['followup_estagio_enviado' => 0]);
        }

        if ($ticketNovo) {
            app(SequenciaService::class)->iniciarParaTicket($ticket);
        } elseif ($ticket->agente_responsavel === 'bot' && $conteudo) {
            dispatch(new SdrResponderJob($ticket->id, $conteudo, false, false, 0));
        }
    }

    private function normalizarTelefone(string $telefone): string
    {
        $normalizado = app(TelefoneService::class)->normalizar($telefone);
        if ($normalizado) {
            return $normalizado;
        }
        $digits = preg_replace('/\D/', '', $telefone);
        if (strlen($digits) >= 10 && strlen($digits) <= 11) {
            $digits = '55' . $digits;
        }
        return $digits;
    }
}
