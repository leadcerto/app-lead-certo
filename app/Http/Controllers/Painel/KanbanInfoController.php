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
            'nome'               => $kanban?->nome ?? '',
            'conhecimento_geral' => $kanban?->conhecimento_geral ?? '',
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        // Achado real 2026-08-20 (Leonardo, tenant Lead Certo/Adriana): não dava
        // pra renomear o Kanban ("Vendas" não fazia sentido pra um Kanban de
        // suporte). 'nome' é opcional aqui pelo mesmo motivo de conhecimento_geral
        // já ser — cada campo da tela salva independente, mandar só um nunca pode
        // apagar o outro (mesmo bug que já foi corrigido nessa tela antes, quando
        // virou 8 cards independentes).
        $validated = $request->validate([
            'nome'               => 'sometimes|required|string|max:100',
            'conhecimento_geral' => 'nullable|string|max:20000',
        ]);

        $kanban = Kanban::where('tenant_id', $request->user()->tenant_id)
            ->where('tipo', 'vendas')
            ->firstOrFail();

        if (array_key_exists('nome', $validated)) {
            $kanban->nome = $validated['nome'];
        }
        if ($request->has('conhecimento_geral')) {
            $kanban->conhecimento_geral = $validated['conhecimento_geral'] ?? null;
        }
        $kanban->save();

        return response()->json(['ok' => true]);
    }
}
