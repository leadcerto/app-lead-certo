<?php

namespace App\Http\Controllers\Painel;

use App\Http\Controllers\Controller;
use App\Models\SequenciaMensagem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SequenciaController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $mensagens = SequenciaMensagem::where('tenant_id', $request->user()->tenant_id)
            ->orderBy('ordem')
            ->get(['id', 'ordem', 'conteudo', 'delay_minutos', 'ativo']);

        return response()->json($mensagens);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'conteudo'       => 'required|string|max:1000',
            'delay_minutos'  => 'required|integer|min:0|max:10080',
        ]);

        $tenantId = $request->user()->tenant_id;

        $ordem = SequenciaMensagem::where('tenant_id', $tenantId)->max('ordem') + 1;

        $msg = SequenciaMensagem::create([
            'tenant_id'     => $tenantId,
            'ordem'         => $ordem,
            'conteudo'      => $validated['conteudo'],
            'delay_minutos' => $validated['delay_minutos'],
            'ativo'         => true,
        ]);

        return response()->json($msg, 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $msg = SequenciaMensagem::where('id', $id)
            ->where('tenant_id', $request->user()->tenant_id)
            ->firstOrFail();

        $validated = $request->validate([
            'conteudo'      => 'sometimes|string|max:1000',
            'delay_minutos' => 'sometimes|integer|min:0|max:10080',
            'ativo'         => 'sometimes|boolean',
            'ordem'         => 'sometimes|integer|min:1',
        ]);

        $msg->update($validated);

        return response()->json($msg);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        SequenciaMensagem::where('id', $id)
            ->where('tenant_id', $request->user()->tenant_id)
            ->firstOrFail()
            ->delete();

        // Reordena
        SequenciaMensagem::where('tenant_id', $request->user()->tenant_id)
            ->orderBy('ordem')
            ->get()
            ->each(fn ($m, $i) => $m->update(['ordem' => $i + 1]));

        return response()->json(['ok' => true]);
    }
}
