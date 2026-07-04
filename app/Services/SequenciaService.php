<?php

namespace App\Services;

use App\Jobs\SequenciaMensagemJob;
use App\Models\SequenciaMensagem;
use App\Models\TicketAtendimento;

class SequenciaService
{
    public function iniciarParaTicket(TicketAtendimento $ticket): void
    {
        $mensagens = SequenciaMensagem::where('tenant_id', $ticket->tenant_id)
            ->where('ativo', true)
            ->orderBy('ordem')
            ->get();

        foreach ($mensagens as $msg) {
            SequenciaMensagemJob::dispatch($ticket->id, $msg->conteudo)
                ->onQueue('default')
                ->delay(now()->addMinutes($msg->delay_minutos));
        }
    }
}
