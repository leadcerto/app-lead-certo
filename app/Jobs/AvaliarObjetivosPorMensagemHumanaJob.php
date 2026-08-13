<?php

namespace App\Jobs;

use App\Models\KanbanColunaObjetivo;
use App\Models\Mensagem;
use App\Models\TicketAtendimento;
use App\Services\AvancoAutomaticoKanbanService;
use App\Services\OpenRouterService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Estende a marcação de objetivos (ver AvancoAutomaticoKanbanService) pro
 * caminho onde é um humano quem conduz a conversa, não a IA — hoje a
 * marcação só acontecia via token [OBJETIVO_CUMPRIDO:<id>] gerado pela IA
 * respondendo. Despachado pelo hook único em Mensagem::booted() sempre que
 * uma mensagem de humano é criada num ticket com checklist ainda pendente.
 */
class AvaliarObjetivosPorMensagemHumanaJob implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $ticketId) {}

    public function handle(OpenRouterService $openRouter, AvancoAutomaticoKanbanService $avanco): void
    {
        $ticket = TicketAtendimento::withoutGlobalScopes()->find($this->ticketId);
        if (! $ticket) {
            return;
        }

        $jaCumpridos = $ticket->objetivos_cumpridos ?? [];

        $pendentes = KanbanColunaObjetivo::withoutGlobalScopes()
            ->where('tenant_id', $ticket->tenant_id)
            ->where('coluna_kanban', $ticket->coluna_kanban)
            ->where('ativo', true)
            ->get()
            ->reject(fn (KanbanColunaObjetivo $o) => in_array($o->id, $jaCumpridos, true));

        if ($pendentes->isEmpty()) {
            return;
        }

        $historico = Mensagem::withoutGlobalScopes()
            ->where('ticket_id', $ticket->id)
            ->orderByDesc('id')
            ->limit(20)
            ->get()
            ->reverse()
            ->map(fn (Mensagem $m) => "[{$m->remetente}] {$m->conteudo}")
            ->implode("\n");

        $listaObjetivos = $pendentes->map(fn (KanbanColunaObjetivo $o) => "{$o->id}: {$o->texto}")->implode("\n");

        $resposta = $openRouter->chat([
            ['role' => 'system', 'content' =>
                "Você analisa uma conversa de atendimento de frete/mudança e decide quais itens de uma "
                . "checklist já foram resolvidos pelo que já foi dito — mesmo que quem esteja conduzindo a "
                . "conversa seja um atendente humano, não você. Responda SOMENTE com os ids numéricos dos "
                . "itens já cumpridos, um por linha, sem nenhum texto extra. Se nenhum item foi cumprido "
                . "ainda, responda exatamente a palavra NENHUM.\n\nItens da checklist (id: descrição):\n{$listaObjetivos}"],
            ['role' => 'user', 'content' => $historico],
        ], 'simples', 100, 'avaliar_objetivos_mensagem_humana', $ticket->tenant_id);

        if (! $resposta || trim(mb_strtoupper($resposta)) === 'NENHUM') {
            return;
        }

        preg_match_all('/\d+/', $resposta, $matches);
        $ids = array_map('intval', $matches[0]);

        if (empty($ids)) {
            Log::debug('AvaliarObjetivosPorMensagemHumanaJob: resposta da IA sem ids reconhecíveis', [
                'ticket_id' => $ticket->id, 'resposta' => $resposta,
            ]);
            return;
        }

        $avanco->marcarObjetivos($ticket, $ids);
    }
}
