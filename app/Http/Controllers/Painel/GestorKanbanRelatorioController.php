<?php

namespace App\Http\Controllers\Painel;

use App\Http\Controllers\Controller;
use App\Models\GestorKanbanRelatorio;
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
}
