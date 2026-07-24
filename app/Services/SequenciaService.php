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
            ->with(['mensagens' => fn ($q) => $q->where('ativo', true)->orderBy('ordem')->with([
                'variacoes' => fn ($q2) => $q2->where('ativa', true),
            ])])
            ->get();

        $disparou       = false;
        $delayAcumulado = 0;

        foreach ($sequencias as $sequencia) {
            foreach ($sequencia->mensagens as $msg) {
                $jitter = $msg->delay_jitter_segundos > 0
                    ? random_int(-$msg->delay_jitter_segundos, $msg->delay_jitter_segundos)
                    : 0;
                $delayAcumulado += max(0, $msg->delay_segundos + $jitter);

                $variacao = $msg->variacoes->count() > 0
                    ? $msg->variacoes->random()
                    : null;
                $conteudo = $variacao?->conteudo ?? $msg->conteudo;

                SequenciaMensagemJob::dispatch(
                    $ticket->id,
                    $conteudo,
                    $msg->imagem_url,
                    $sequencia->coluna_kanban,
                    $msg->button_settings ?: null,
                    (bool) $msg->obrigatorio,
                )
                    ->onQueue('default')
                    ->delay(now()->addSeconds($delayAcumulado));
                $disparou = true;
            }
        }

        return $disparou;
    }
}
