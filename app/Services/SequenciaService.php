<?php

namespace App\Services;

use App\Jobs\SequenciaMensagemJob;
use App\Models\Sequencia;
use App\Models\TicketAtendimento;

class SequenciaService
{
    public function iniciarParaTicket(TicketAtendimento $ticket): bool
    {
        $sequencias = Sequencia::withoutGlobalScopes()
            ->where('tenant_id', $ticket->tenant_id)
            ->where('coluna_kanban', $ticket->coluna_kanban)
            ->where('ativo', true)
            ->with(['mensagens' => fn ($q) => $q->where('ativo', true)->orderBy('ordem')])
            ->get();

        $disparou        = false;
        $delayAcumulado  = 0;

        foreach ($sequencias as $sequencia) {
            foreach ($sequencia->mensagens as $msg) {
                $delayAcumulado += $msg->delay_segundos;
                SequenciaMensagemJob::dispatch($ticket->id, $msg->conteudo, $msg->imagem_url, $sequencia->coluna_kanban)
                    ->onQueue('default')
                    ->delay(now()->addSeconds($delayAcumulado));
                $disparou = true;
            }
        }

        return $disparou;
    }
}
