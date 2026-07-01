<?php

namespace App\Http\Controllers\Painel;

use App\Http\Controllers\Controller;
use App\Models\Contato;
use App\Models\NotaContato;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotaContatoController extends Controller
{
    public function index(int $contatoId): JsonResponse
    {
        $tenantId = auth()->user()->tenant_id;

        $notas = NotaContato::with('user:id,nome')
            ->where('contato_id', $contatoId)
            ->where('tenant_id', $tenantId)
            ->orderByDesc('created_at')
            ->get(['id', 'user_id', 'texto', 'created_at'])
            ->map(fn ($n) => [
                'id'         => $n->id,
                'texto'      => $n->texto,
                'autor'      => $n->user?->nome ?? 'Sistema',
                'created_at' => $n->created_at,
            ]);

        return response()->json(['data' => $notas]);
    }

    public function store(Request $request, int $contatoId): JsonResponse
    {
        $data = $request->validate([
            'texto' => 'required|string|max:1000',
        ]);

        $tenantId = auth()->user()->tenant_id;

        $nota = NotaContato::create([
            'contato_id' => $contatoId,
            'tenant_id'  => $tenantId,
            'user_id'    => auth()->id(),
            'texto'      => $data['texto'],
        ]);

        return response()->json(['id' => $nota->id], 201);
    }

    public function destroy(int $id): JsonResponse
    {
        $tenantId = auth()->user()->tenant_id;

        NotaContato::where('id', $id)
            ->where('tenant_id', $tenantId)
            ->firstOrFail()
            ->delete();

        return response()->json(['deleted' => true]);
    }
}
