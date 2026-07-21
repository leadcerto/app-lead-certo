<?php

namespace App\Http\Controllers\Painel;

use App\Enums\PapelColunaKanban;
use App\Http\Controllers\Controller;
use App\Models\Kanban;
use App\Models\KanbanColuna;
use App\Models\TicketAtendimento;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class KanbanColunaController extends Controller
{
    public function papeis(): JsonResponse
    {
        return response()->json(collect(PapelColunaKanban::cases())->map(fn (PapelColunaKanban $papel) => [
            'value'           => $papel->value,
            'label'           => $papel->label(),
            'descricao'       => $papel->descricao(),
            'objetivo_exemplo' => $papel->objetivoExemplo(),
            'prompt_exemplo'  => $papel->promptExemplo(),
        ])->values());
    }

    public function index(Request $request): JsonResponse
    {
        $tenantId = $request->user()->tenant_id;

        $colunas = KanbanColuna::where('tenant_id', $tenantId)->orderBy('ordem')->get();

        return response()->json($colunas->map(fn (KanbanColuna $c) => [
            'id'    => $c->id,
            'chave' => $c->chave,
            'label' => $c->label,
            'emoji' => $c->emoji,
            'papel' => $c->papel->value,
            'ordem' => $c->ordem,
            'token' => '[' . mb_strtoupper($c->chave) . ']',
        ]));
    }

    public function store(Request $request): JsonResponse
    {
        $dados = $request->validate([
            'label' => 'required|string|max:60',
            'emoji' => 'nullable|string|max:10',
            'papel' => ['required', Rule::enum(PapelColunaKanban::class)],
        ]);

        $tenantId = $request->user()->tenant_id;
        $kanban   = Kanban::where('tenant_id', $tenantId)->where('tipo', 'vendas')->firstOrFail();

        if ($dados['papel'] === PapelColunaKanban::Entrada->value
            && KanbanColuna::where('kanban_id', $kanban->id)->where('papel', PapelColunaKanban::Entrada->value)->exists()) {
            return response()->json(['message' => 'Já existe uma coluna de Entrada — só pode haver 1 por Kanban.'], 422);
        }

        $chaveBase = Str::slug($dados['label'], '_');
        $chave     = $chaveBase;
        $sufixo    = 1;
        while (KanbanColuna::where('kanban_id', $kanban->id)->where('chave', $chave)->exists()) {
            $chave = "{$chaveBase}_" . (++$sufixo);
        }

        $proximaOrdem = (KanbanColuna::where('kanban_id', $kanban->id)->max('ordem') ?? 0) + 1;

        $coluna = KanbanColuna::create([
            'tenant_id' => $tenantId,
            'kanban_id' => $kanban->id,
            'chave'     => $chave,
            'label'     => $dados['label'],
            'emoji'     => $dados['emoji'] ?? null,
            'papel'     => $dados['papel'],
            'ordem'     => $proximaOrdem,
        ]);

        return response()->json($coluna, 201);
    }

    public function update(Request $request, int $coluna): JsonResponse
    {
        $dados = $request->validate([
            'label' => 'required|string|max:60',
            'emoji' => 'nullable|string|max:10',
            'papel' => ['required', Rule::enum(PapelColunaKanban::class)],
        ]);

        $tenantId  = $request->user()->tenant_id;
        $colunaObj = KanbanColuna::where('tenant_id', $tenantId)->findOrFail($coluna);

        if ($dados['papel'] === PapelColunaKanban::Entrada->value
            && $colunaObj->papel !== PapelColunaKanban::Entrada
            && KanbanColuna::where('kanban_id', $colunaObj->kanban_id)->where('papel', PapelColunaKanban::Entrada->value)->exists()) {
            return response()->json(['message' => 'Já existe uma coluna de Entrada — só pode haver 1 por Kanban.'], 422);
        }

        $colunaObj->update($dados);

        return response()->json($colunaObj->fresh());
    }

    public function destroy(Request $request, int $coluna): JsonResponse
    {
        $tenantId  = $request->user()->tenant_id;
        $colunaObj = KanbanColuna::where('tenant_id', $tenantId)->findOrFail($coluna);

        $ticketsNaColuna = TicketAtendimento::where('coluna_kanban', $colunaObj->chave)->count();

        if ($ticketsNaColuna > 0) {
            return response()->json([
                'message' => "Não é possível excluir: {$ticketsNaColuna} ticket(s) ainda estão nesta coluna. Mova-os antes de excluir.",
            ], 422);
        }

        $colunaObj->delete();

        return response()->json(['excluida' => true]);
    }

    public function reordenar(Request $request): JsonResponse
    {
        $dados = $request->validate([
            'ids'   => 'required|array|min:1',
            'ids.*' => 'integer',
        ]);

        $tenantId = $request->user()->tenant_id;

        foreach ($dados['ids'] as $indice => $id) {
            KanbanColuna::where('tenant_id', $tenantId)->where('id', $id)->update(['ordem' => $indice + 1]);
        }

        return response()->json(['reordenado' => true]);
    }
}
