<?php
// app/Http/Controllers/Painel/KanbanInfoController.php
namespace App\Http\Controllers\Painel;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Painel\Concerns\ResolveKanbanDoRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class KanbanInfoController extends Controller
{
    use ResolveKanbanDoRequest;

    public function show(Request $request): JsonResponse
    {
        $kanban = $this->resolverKanban($request);

        return response()->json([
            'nome'                    => $kanban?->nome ?? '',
            'nome_curto'              => $kanban?->nome_curto ?? '',
            'conhecimento_geral'      => $kanban?->conhecimento_geral ?? '',
            'forcar_engajamento_meta' => $kanban?->forcar_engajamento_meta ?? true,
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
            'nome'                    => 'sometimes|required|string|max:100',
            'nome_curto'              => 'sometimes|required|string|max:20|regex:/^\S+$/',
            'conhecimento_geral'      => 'nullable|string|max:20000',
            'forcar_engajamento_meta' => 'sometimes|boolean',
        ]);

        $kanban = $this->resolverKanban($request);

        if (array_key_exists('nome', $validated)) {
            $kanban->nome = $validated['nome'];
        }
        if (array_key_exists('nome_curto', $validated)) {
            $kanban->nome_curto = $validated['nome_curto'];
        }
        if ($request->has('conhecimento_geral')) {
            $kanban->conhecimento_geral = $validated['conhecimento_geral'] ?? null;
        }
        if (array_key_exists('forcar_engajamento_meta', $validated)) {
            $kanban->forcar_engajamento_meta = $validated['forcar_engajamento_meta'];
        }
        $kanban->save();

        return response()->json(['ok' => true]);
    }
}
