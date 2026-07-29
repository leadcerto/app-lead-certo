<?php

namespace App\Services\Canais;

use App\Models\TicketAtendimento;
use App\Models\WhatsappCanal;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Envia mensagens de texto pelo canal oficial (Meta Cloud API, via Covercut).
 * Nunca dispara proativamente — só responde dentro da janela de conversa (seção 4
 * do design, docs/superpowers/specs/2026-07-27-canal-whatsapp-oficial-covercut-design.md).
 * Sem templates pagos: fora da janela, o envio é bloqueado, sem fallback.
 */
class CovercutChannelService implements CanalWhatsappInterface
{
    public function enviarTexto(WhatsappCanal $canal, string $telefone, string $texto): bool
    {
        $ticket = TicketAtendimento::withoutGlobalScopes()
            ->where('tenant_id', $canal->tenant_id)
            ->where('whatsapp_canal_id', $canal->id)
            ->whereHas('contato', fn ($q) => $q->where('telefone', $telefone))
            ->whereIn('status', ['aberto', 'aguardando'])
            ->latest()
            ->first();

        if ($ticket && $ticket->janela_expira_em && now()->greaterThan($ticket->janela_expira_em)) {
            Log::warning('CovercutChannelService: envio bloqueado, janela de conversa expirada', [
                'canal_id'  => $canal->id,
                'ticket_id' => $ticket->id,
                'expirou_em' => $ticket->janela_expira_em->toIso8601String(),
            ]);
            return false;
        }

        $phoneNumberId = $canal->config['phone_number_id'] ?? null;

        if (! $phoneNumberId) {
            Log::warning('CovercutChannelService: canal sem phone_number_id configurado', ['canal_id' => $canal->id]);
            return false;
        }

        $baseUrl = config('services.covercut.base_url');

        $response = Http::withHeaders([
                'X-API-Key'    => config('services.covercut.api_key'),
                'X-API-Secret' => config('services.covercut.api_secret'),
            ])
            ->post("{$baseUrl}/messages", [
                'from' => $phoneNumberId,
                'to'   => $telefone,
                'type' => 'text',
                'text' => ['body' => $texto],
            ]);

        if (! $response->successful()) {
            Log::warning('CovercutChannelService: falha ao enviar texto', [
                'canal_id' => $canal->id,
                'status'   => $response->status(),
                'body'     => $response->body(),
            ]);
        }

        return $response->successful();
    }
}
