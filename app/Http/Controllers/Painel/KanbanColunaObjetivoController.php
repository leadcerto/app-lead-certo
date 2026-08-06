<?php
// app/Http/Controllers/Painel/KanbanColunaObjetivoController.php
namespace App\Http\Controllers\Painel;

use App\Http\Controllers\Controller;
use App\Models\KanbanColunaObjetivo;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class KanbanColunaObjetivoController extends Controller
{
    public function index(Request $request, string $coluna): JsonResponse
    {
        $objetivos = KanbanColunaObjetivo::where('tenant_id', $request->user()->tenant_id)
            ->where('coluna_kanban', $coluna)
            ->orderBy('ordem')
            ->get(['id', 'texto', 'ordem', 'ativo']);

        return response()->json($objetivos);
    }

    public function store(Request $request, string $coluna): JsonResponse
    {
        $validated = $request->validate(['texto' => 'required|string|max:255']);

        $tenantId = $request->user()->tenant_id;
        $ordem    = (KanbanColunaObjetivo::where('tenant_id', $tenantId)->where('coluna_kanban', $coluna)->max('ordem') ?? 0) + 1;

        $objetivo = KanbanColunaObjetivo::create([
            'tenant_id'     => $tenantId,
            'coluna_kanban' => $coluna,
            'texto'         => $validated['texto'],
            'ordem'         => $ordem,
            'ativo'         => true,
        ]);

        return response()->json($objetivo, 201);
    }

    public function update(Request $request, string $coluna, int $id): JsonResponse
    {
        $objetivo = KanbanColunaObjetivo::where('tenant_id', $request->user()->tenant_id)
            ->where('coluna_kanban', $coluna)
            ->findOrFail($id);

        $validated = $request->validate([
            'texto' => 'sometimes|string|max:255',
            'ativo' => 'sometimes|boolean',
        ]);

        $objetivo->update($validated);

        return response()->json($objetivo->fresh());
    }

    public function destroy(Request $request, string $coluna, int $id): JsonResponse
    {
        $tenantId = $request->user()->tenant_id;

        KanbanColunaObjetivo::where('tenant_id', $tenantId)
            ->where('coluna_kanban', $coluna)
            ->findOrFail($id)
            ->delete();

        KanbanColunaObjetivo::where('tenant_id', $tenantId)
            ->where('coluna_kanban', $coluna)
            ->orderBy('ordem')
            ->get()
            ->each(fn ($o, $i) => $o->update(['ordem' => $i + 1]));

        return response()->json(['ok' => true]);
    }

    public function reordenar(Request $request, string $coluna): JsonResponse
    {
        $dados = $request->validate([
            'ids'   => 'required|array|min:1',
            'ids.*' => 'integer',
        ]);

        $tenantId = $request->user()->tenant_id;

        foreach ($dados['ids'] as $indice => $id) {
            KanbanColunaObjetivo::where('tenant_id', $tenantId)
                ->where('coluna_kanban', $coluna)
                ->where('id', $id)
                ->update(['ordem' => $indice + 1]);
        }

        return response()->json(['reordenado' => true]);
    }
}
