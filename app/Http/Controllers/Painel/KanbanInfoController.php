<?php
// app/Http/Controllers/Painel/KanbanInfoController.php
namespace App\Http\Controllers\Painel;

use App\Http\Controllers\Controller;
use App\Models\Kanban;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class KanbanInfoController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $kanban = Kanban::where('tenant_id', $request->user()->tenant_id)
            ->where('tipo', 'vendas')
            ->first();

        return response()->json([
            'conhecimento_geral' => $kanban?->conhecimento_geral ?? '',
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'conhecimento_geral' => 'nullable|string|max:20000',
        ]);

        $kanban = Kanban::where('tenant_id', $request->user()->tenant_id)
            ->where('tipo', 'vendas')
            ->firstOrFail();

        $kanban->update(['conhecimento_geral' => $validated['conhecimento_geral'] ?? null]);

        return response()->json(['ok' => true]);
    }
}
