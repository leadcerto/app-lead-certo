<?php

namespace App\Services;

use App\Enums\PapelColunaKanban;
use App\Models\Contato;
use App\Models\KanbanColuna;
use App\Models\KanbanColunaObjetivo;
use App\Models\TicketAtendimento;
use Illuminate\Support\Facades\Cache;

/**
 * Marca objetivos (checklist) de uma coluna como cumpridos e avança o ticket
 * pra próxima coluna do funil quando a checklist fecha. Reusado tanto pelo
 * caminho onde a IA está respondendo (SdrResponderService, via token
 * [OBJETIVO_CUMPRIDO:<id>]) quanto pelo caminho onde um humano conduz a
 * conversa manualmente (AvaliarObjetivosPorMensagemHumanaJob).
 *
 * Colunas com papel Encerramento ou TransferenciaHumana nunca são destino
 * deste avanço automático — chegar nelas exige um sinal explícito (token de
 * movimento da própria IA, ação manual), porque é uma decisão mais forte do
 * que "terminei a checklist local".
 */
class AvancoAutomaticoKanbanService
{
    public function marcarObjetivos(TicketAtendimento $ticket, array $idsObjetivos): void
    {
        Cache::lock($this->chaveTrava($ticket), 10)->block(5, function () use ($ticket, $idsObjetivos) {
            $atual = $this->recarregar($ticket);
            if (! $atual) {
                return;
            }

            $idsAtivos = $this->objetivosAtivos($atual);
            $cumpridos = $atual->objetivos_cumpridos ?? [];
            $mudou     = false;

            foreach ($idsObjetivos as $id) {
                $id = (int) $id;
                if (in_array($id, $idsAtivos, true) && ! in_array($id, $cumpridos, true)) {
                    $cumpridos[] = $id;
                    $mudou       = true;
                }
            }

            if ($mudou) {
                $atual->update(['objetivos_cumpridos' => $cumpridos]);
            }

            // Achado 2 da revisão final: roda incondicionalmente (não só quando
            // $mudou), igual o plano original especificava — se um objetivo já
            // marcado for desativado depois na config (reduzindo a lista de
            // "ativos"), a checklist pode ficar completa "por redução" sem
            // nenhuma marcação nova disparar o avanço. avancarSeCompletoInterno
            // já checa se está tudo completo antes de fazer qualquer coisa, então
            // chamar sempre não muda o comportamento quando não está completo.
            $this->avancarSeCompletoInterno($atual, $idsAtivos);
        });
    }

    /**
     * Achado real 2026-09-21 (ticket #4821, Lucas — e #??? Patrick, mesmo
     * bug se repetindo ao vivo): o gatilho original (IdentificarNomeConversaJob,
     * despachado só quando Contato::semNomeReal() TRANSICIONA de true pra
     * false durante a conversa) não cobre o caso — bem comum — de o contato
     * já ser criado com um nome que parece real desde o início (ex: pushName
     * do WhatsApp). Nesse caso semNomeReal() já é false desde a primeira
     * mensagem, o job nunca é despachado, e o objetivo de nome nunca é
     * marcado — mesmo o nome estando certo o tempo todo.
     *
     * Achado 2 na mesma investigação: mesmo corrigindo só o objetivo de
     * nome, a coluna "Novo" tem OUTRO objetivo ativo por padrão — "Início de
     * conversa" — que depende do mesmo mecanismo frágil de token da IA e
     * NUNCA é marcado por nada no sistema hoje. Mas esse objetivo é
     * trivialmente verdadeiro no exato momento em que este método é chamado
     * (a partir de um hook que só roda quando uma mensagem do lead acabou de
     * ser criada — a própria existência dela já prova "conversa iniciada").
     *
     * Este método checa o estado ATUAL (não uma transição) e é seguro
     * chamar a cada mensagem do lead — idempotente, não requer nenhuma
     * chamada de IA pra nenhum dos dois objetivos.
     */
    public function marcarObjetivosDeterministicosDeLead(TicketAtendimento $ticket): void
    {
        $contato     = Contato::withoutGlobalScopes()->find($ticket->contato_id);
        $temNomeReal = $contato && ! $contato->semNomeReal();

        $objetivosAtivos = KanbanColunaObjetivo::withoutGlobalScopes()
            ->where('tenant_id', $ticket->tenant_id)
            ->where('coluna_kanban', $ticket->coluna_kanban)
            ->where('ativo', true)
            ->get(['id', 'texto']);

        $idsParaMarcar = [];
        foreach ($objetivosAtivos as $objetivo) {
            $texto = mb_strtolower($objetivo->texto);

            if ($temNomeReal && str_contains($texto, 'nome')) {
                $idsParaMarcar[] = $objetivo->id;
            } elseif (str_contains($texto, 'início') || str_contains($texto, 'inicio') || str_contains($texto, 'conversa')) {
                $idsParaMarcar[] = $objetivo->id;
            }
        }

        if ($idsParaMarcar) {
            $this->marcarObjetivos($ticket, $idsParaMarcar);
        }
    }

    public function avancarSeCompleto(TicketAtendimento $ticket): bool
    {
        return Cache::lock($this->chaveTrava($ticket), 10)->block(5, function () use ($ticket) {
            $atual = $this->recarregar($ticket);
            if (! $atual) {
                return false;
            }

            return $this->avancarSeCompletoInterno($atual, $this->objetivosAtivos($atual));
        });
    }

    private function avancarSeCompletoInterno(TicketAtendimento $ticket, array $idsAtivos): bool
    {
        if (empty($idsAtivos)) {
            return false;
        }

        $cumpridos = $ticket->objetivos_cumpridos ?? [];
        foreach ($idsAtivos as $id) {
            if (! in_array($id, $cumpridos, true)) {
                return false;
            }
        }

        $proxima = KanbanColuna::proximaChave($ticket->tenant_id, $ticket->coluna_kanban);
        if (! $proxima) {
            return false;
        }

        $papel = KanbanColuna::papelDe($ticket->tenant_id, $proxima);
        if (in_array($papel, [PapelColunaKanban::Encerramento, PapelColunaKanban::TransferenciaHumana], true)) {
            return false;
        }

        // Mesmo padrão do token de movimento manual da IA (SdrResponderService) —
        // marca origem 'ia' pro guardrail de salto (Regra 13) diferenciar de
        // política automática de sistema (auto-mover por tempo, por exemplo).
        $ticket->origemMudancaColuna = 'ia';
        $ticket->update(['coluna_kanban' => $proxima]);
        // objetivos_cumpridos é zerado automaticamente pelo hook do model
        // (TicketAtendimento::updating) porque este update não o define
        // explicitamente e coluna_kanban está mudando.

        return true;
    }

    private function objetivosAtivos(TicketAtendimento $ticket): array
    {
        return KanbanColunaObjetivo::withoutGlobalScopes()
            ->where('tenant_id', $ticket->tenant_id)
            ->where('coluna_kanban', $ticket->coluna_kanban)
            ->where('ativo', true)
            ->pluck('id')
            ->all();
    }

    private function recarregar(TicketAtendimento $ticket): ?TicketAtendimento
    {
        return TicketAtendimento::withoutGlobalScopes()->find($ticket->id);
    }

    private function chaveTrava(TicketAtendimento $ticket): string
    {
        return "avanco-objetivos:{$ticket->id}";
    }
}
