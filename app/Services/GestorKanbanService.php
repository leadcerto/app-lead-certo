<?php

namespace App\Services;

use App\Models\KanbanColunaHistorico;
use App\Models\Tenant;
use App\Models\TicketAtendimento;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class GestorKanbanService
{
    public function coletarNumerosColuna(Tenant $tenant, string $coluna, Carbon $inicio, Carbon $fim): array
    {
        $entradas = KanbanColunaHistorico::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->where('coluna', $coluna)
            ->whereBetween('entrou_em', [$inicio, $fim])
            ->count();

        $avancos = KanbanColunaHistorico::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->where('coluna_anterior', $coluna)
            ->whereBetween('entrou_em', [$inicio, $fim])
            ->count();

        $travados = $this->travadosNaColuna($tenant, $coluna, $inicio);

        $tagDesfechoBreakdown = [];
        if ($coluna === 'encerrado') {
            $tagDesfechoBreakdown = TicketAtendimento::withoutGlobalScopes()
                ->where('tenant_id', $tenant->id)
                ->where('coluna_kanban', 'encerrado')
                ->whereBetween('encerrado_em', [$inicio, $fim])
                ->whereNotNull('tag_desfecho')
                ->selectRaw('tag_desfecho, count(*) as total')
                ->groupBy('tag_desfecho')
                ->pluck('total', 'tag_desfecho')
                ->toArray();
        }

        return [
            'entradas'               => $entradas,
            'avancos'                => $avancos,
            'travados'               => $travados,
            'tag_desfecho_breakdown' => $tagDesfechoBreakdown,
        ];
    }

    public function amostrarConversasColuna(Tenant $tenant, string $coluna, Carbon $inicio, Carbon $fim, int $limite = 15): Collection
    {
        $travados = TicketAtendimento::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->where('coluna_kanban', $coluna)
            ->with('mensagens')
            ->orderBy('updated_at', 'asc')
            ->limit($limite)
            ->get();

        if ($coluna !== 'encerrado') {
            return $travados;
        }

        $fechados = TicketAtendimento::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->where('coluna_kanban', 'encerrado')
            ->whereBetween('encerrado_em', [$inicio, $fim])
            ->with('mensagens')
            ->orderByDesc('encerrado_em')
            ->limit($limite)
            ->get();

        return $travados->merge($fechados)->unique('id')->take($limite)->values();
    }

    public function formatarConversa(TicketAtendimento $ticket): string
    {
        if ($ticket->coluna_kanban === 'encerrado' && $ticket->resumo_ia) {
            return "[Resumo] {$ticket->resumo_ia}";
        }

        return $ticket->mensagens
            ->filter(fn ($m) => $m->conteudo && $m->conteudo !== '')
            ->map(fn ($m) => match ($m->remetente) {
                'lead'   => 'CLIENTE: ' . $m->conteudo,
                'bot'    => 'BOT: ' . $m->conteudo,
                'humano' => 'ATENDENTE: ' . $m->conteudo,
                default  => strtoupper($m->remetente) . ': ' . $m->conteudo,
            })
            ->implode("\n");
    }

    /**
     * Tickets que estão atualmente na coluna e entraram nela antes do início
     * da semana analisada — ou seja, já estavam parados ali a semana inteira.
     */
    private function travadosNaColuna(Tenant $tenant, string $coluna, Carbon $inicioSemana): int
    {
        $ticketIds = TicketAtendimento::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->where('coluna_kanban', $coluna)
            ->pluck('id');

        if ($ticketIds->isEmpty()) {
            return 0;
        }

        return KanbanColunaHistorico::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->whereIn('ticket_id', $ticketIds)
            ->selectRaw('ticket_id, MAX(entrou_em) as ultima_entrada')
            ->groupBy('ticket_id')
            ->havingRaw('MAX(entrou_em) < ?', [$inicioSemana])
            ->get()
            ->count();
    }
}
