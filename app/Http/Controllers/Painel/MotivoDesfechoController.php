<?php

namespace App\Http\Controllers\Painel;

use App\Http\Controllers\Controller;
use App\Models\MotivoDesfecho;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class MotivoDesfechoController extends Controller
{
    public function view(): View
    {
        return view('kanban.motivos-desfecho');
    }

    public function index(Request $request): JsonResponse
    {
        $motivos = MotivoDesfecho::where('tenant_id', $request->user()->tenant_id)
            ->orderBy('ordem')
            ->get();

        return response()->json(['data' => $motivos]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'label'   => 'required|string|max:150',
            'e_venda' => 'sometimes|boolean',
        ]);

        $tenantId = $request->user()->tenant_id;
        $chave    = Str::slug($validated['label'], '_');

        if (MotivoDesfecho::where('tenant_id', $tenantId)->where('chave', $chave)->exists()) {
            return response()->json(['message' => 'Já existe um motivo parecido com esse nome.'], 422);
        }

        $proximaOrdem = (int) MotivoDesfecho::where('tenant_id', $tenantId)->max('ordem') + 1;

        $motivo = MotivoDesfecho::create([
            'tenant_id' => $tenantId,
            'chave'     => $chave,
            'label'     => $validated['label'],
            'e_venda'   => $validated['e_venda'] ?? false,
            'ordem'     => $proximaOrdem,
        ]);

        return response()->json($motivo, 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $motivo = MotivoDesfecho::where('tenant_id', $request->user()->tenant_id)->findOrFail($id);

        $validated = $request->validate([
            'label'   => 'sometimes|string|max:150',
            'e_venda' => 'sometimes|boolean',
            'ordem'   => 'sometimes|integer|min:0',
        ]);

        $motivo->update($validated);

        return response()->json($motivo);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $motivo = MotivoDesfecho::where('tenant_id', $request->user()->tenant_id)->findOrFail($id);
        $motivo->delete();

        return response()->json(['ok' => true]);
    }
}
