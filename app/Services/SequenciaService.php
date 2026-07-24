<?php

namespace App\Services;

use App\Jobs\SequenciaMensagemJob;
use App\Models\Sequencia;
use App\Models\TicketAtendimento;
use Illuminate\Support\Carbon;

class SequenciaService
{
    public function iniciarParaTicket(TicketAtendimento $ticket): bool
    {
        $sequencias = Sequencia::withoutGlobalScopes()
            ->where('tenant_id', $ticket->tenant_id)
            ->where('coluna_kanban', $ticket->coluna_kanban)
            ->where('ativo', true)
            ->with([
                'mensagens' => fn ($q) => $q->where('ativo', true)->orderBy('ordem')->with([
                    'variacoes' => fn ($q2) => $q2->where('ativa', true),
                ]),
                'sequenciaRepouso.mensagens' => fn ($q) => $q->where('ativo', true)->orderBy('ordem')->with([
                    'variacoes' => fn ($q2) => $q2->where('ativa', true),
                ]),
            ])
            ->get();

        $disparou = false;

        foreach ($sequencias as $sequencia) {
            [$sequenciaEfetiva, $inicioBase] = $this->resolverJanela($sequencia);

            if (! $sequenciaEfetiva) {
                continue; // horário ativo, fora da janela, sem repouso configurado — tratado por resolverJanela via adiamento, então isso não deveria ocorrer; guarda defensiva
            }

            $delayAcumulado = max(0, (int) now()->diffInSeconds($inicioBase, false));

            foreach ($sequenciaEfetiva->mensagens as $msg) {
                $jitter = $msg->delay_jitter_segundos > 0
                    ? random_int(-$msg->delay_jitter_segundos, $msg->delay_jitter_segundos)
                    : 0;
                $delayAcumulado += max(0, $msg->delay_segundos + $jitter);

                $variacao = $msg->variacoes->count() > 0 ? $msg->variacoes->random() : null;
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

    /**
     * Decide qual sequência efetivamente disparar (a principal ou a de repouso)
     * e a partir de que instante começar a contar o delay das mensagens.
     *
     * - horario_ativo = false: sempre a principal, a partir de agora.
     * - dentro da janela [horario_inicio, horario_fim] (fuso America/Sao_Paulo): principal, a partir de agora.
     * - fora da janela, com sequencia_repouso configurada: a de repouso, a partir de agora.
     * - fora da janela, sem repouso: a principal, mas a partir do próximo horario_inicio.
     *
     * @return array{0: Sequencia, 1: Carbon}
     */
    private function resolverJanela(Sequencia $sequencia): array
    {
        if (! $sequencia->horario_ativo || ! $sequencia->horario_inicio || ! $sequencia->horario_fim) {
            return [$sequencia, now()];
        }

        $agora   = now()->timezone('America/Sao_Paulo');
        $inicio  = $agora->copy()->setTimeFromTimeString($sequencia->horario_inicio);
        $fim     = $agora->copy()->setTimeFromTimeString($sequencia->horario_fim);

        if ($agora->between($inicio, $fim)) {
            return [$sequencia, now()];
        }

        if ($sequencia->sequenciaRepouso && $sequencia->sequenciaRepouso->tenant_id === $sequencia->tenant_id) {
            return [$sequencia->sequenciaRepouso, now()];
        }

        $proximoInicio = $agora->lessThan($inicio) ? $inicio : $inicio->addDay();

        return [$sequencia, $proximoInicio];
    }
}
