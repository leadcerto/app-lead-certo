<?php

namespace App\Services;

use App\Models\Mensagem;
use App\Models\TicketAtendimento;
use App\Models\WhatsappCanal;
use Illuminate\Support\Facades\Log;

/**
 * Ecoa a transcrição de um áudio como mensagem de texto na própria conversa do
 * WhatsApp (não só como nota interna do card) — usado nos três pontos onde
 * áudio é processado: webhook Uazapi (lead e atendente via WhatsApp Web),
 * webhook Covercut (lead e atendente via Coexistence) e upload manual do
 * atendente pelo painel (KanbanController::enviarMidia). Extraído pra um
 * lugar só justamente pra evitar o tipo de divergência que já causou bug
 * antes (ver [[feedback_paridade_canais_whatsapp]] na memória do projeto).
 */
class EcoTranscricaoService
{
    public function enviar(WhatsappCanal $canal, TicketAtendimento $ticket, string $telefone, string $transcricao, string $remetenteLabel): void
    {
        $texto = "[Segue a transcrição do áudio enviado pelo {$remetenteLabel}]\n\n{$transcricao}";

        $enviado = $canal->servico()->enviarTextoDireto($canal, $telefone, $texto);

        if (! $enviado) {
            Log::warning('EcoTranscricaoService: falha ao enviar eco de transcrição de áudio pro WhatsApp', [
                'ticket_id' => $ticket->id,
                'telefone'  => $telefone,
            ]);
            return;
        }

        Mensagem::create([
            'ticket_id'  => $ticket->id,
            'tenant_id'  => $ticket->tenant_id,
            'remetente'  => 'bot',
            'tipo'       => 'texto',
            'conteudo'   => $texto,
            'enviado_em' => now(),
        ]);
    }
}
