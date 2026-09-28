<?php

namespace App\Http\Controllers\Painel;

use App\Http\Controllers\Controller;
use App\Models\GestorKanbanRelatorio;
use App\Models\TicketAtendimento;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;

class GestorKanbanRelatorioController extends Controller
{
    public function view(): View
    {
        return view('kanban.relatorios');
    }

    public function index(): JsonResponse
    {
        $relatorios = GestorKanbanRelatorio::orderByDesc('semana_inicio')->get();

        return response()->json(['data' => $relatorios]);
    }

    public function show(int $id): JsonResponse
    {
        $relatorio = GestorKanbanRelatorio::findOrFail($id);

        return response()->json($relatorio);
    }

    /**
     * Fila de tickets marcados manualmente pra revisão de desenvolvimento
     * (botão "Auditoria" no ticket, pedido do Leonardo 24/09) — não tem
     * nenhuma relação com QaAuditoria (avaliação automática por IA ao
     * encerrar) nem AuditoriaContato (moderação de nome).
     */
    public function auditorias(): JsonResponse
    {
        $tickets = TicketAtendimento::whereNotNull('revisao_dev_solicitada_em')
            ->whereNull('revisao_dev_concluida_em')
            ->with(['contato:id,nome,telefone', 'solicitante:id,nome'])
            ->orderByDesc('revisao_dev_solicitada_em')
            ->get([
                'id', 'contato_id', 'coluna_kanban', 'revisao_dev_nota',
                'revisao_dev_solicitada_em', 'revisao_dev_solicitada_por',
            ]);

        return response()->json(['data' => $tickets]);
    }
}
