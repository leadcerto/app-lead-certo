<?php

namespace App\Http\Controllers\Painel;

use App\Http\Controllers\Controller;
use App\Models\SpintaxVariavel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SpintaxVariavelController extends Controller
{
    /** Lista todas as variáveis (defaults + customizadas) com opções. */
    public function index(Request $request): JsonResponse
    {
        $tenantId = $request->user()->tenant_id;
        $saved    = SpintaxVariavel::where('tenant_id', $tenantId)->get()->keyBy('nome');

        $result = [];

        foreach (SpintaxVariavel::$defaults as $nome => $default) {
            $variavel = $saved->get($nome);
            $result[] = [
                'nome'   => $nome,
                'label'  => $default['label'],
                'opcoes' => $variavel ? $variavel->opcoes : $default['opcoes'],
                'padrao' => $default['opcoes'],
                'custom' => false,
            ];
        }

        foreach ($saved as $nome => $variavel) {
            if (! isset(SpintaxVariavel::$defaults[$nome])) {
                $result[] = [
                    'nome'   => $nome,
                    'label'  => $variavel->label,
                    'opcoes' => $variavel->opcoes,
                    'padrao' => null,
                    'custom' => true,
                ];
            }
        }

        return response()->json($result);
    }

    /** Retorna listas como arrays para sorteio client-side (card do Kanban). */
    public function listar(Request $request): JsonResponse
    {
        $tenantId = $request->user()->tenant_id;
        $todas    = SpintaxVariavel::getTodasParaTenant($tenantId);

        return response()->json($todas);
    }

    /** Cria uma variável customizada. */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'nome'   => ['required', 'string', 'max:50', 'regex:/^[a-z][a-z0-9_]*$/'],
            'label'  => 'required|string|max:100',
            'opcoes' => 'required|string|max:10000',
        ]);

        if (isset(SpintaxVariavel::$defaults[$validated['nome']])) {
            return response()->json(['message' => 'Este nome já é usado por uma variável padrão.'], 422);
        }

        $exists = SpintaxVariavel::where('tenant_id', $request->user()->tenant_id)
            ->where('nome', $validated['nome'])
            ->exists();

        if ($exists) {
            return response()->json(['message' => 'Já existe uma variável com este nome.'], 422);
        }

        SpintaxVariavel::create([
            'tenant_id' => $request->user()->tenant_id,
            'nome'      => $validated['nome'],
            'label'     => $validated['label'],
            'opcoes'    => $validated['opcoes'],
        ]);

        return response()->json(['ok' => true], 201);
    }

    /** Salva as opções de uma variável (default ou customizada). */
    public function update(Request $request, string $nome): JsonResponse
    {
        $validated = $request->validate([
            'opcoes' => 'required|string|max:10000',
            'label'  => 'nullable|string|max:100',
        ]);

        $label = isset(SpintaxVariavel::$defaults[$nome])
            ? SpintaxVariavel::$defaults[$nome]['label']
            : ($validated['label'] ?? $nome);

        SpintaxVariavel::updateOrCreate(
            ['tenant_id' => $request->user()->tenant_id, 'nome' => $nome],
            ['label' => $label, 'opcoes' => $validated['opcoes']],
        );

        return response()->json(['ok' => true]);
    }

    /** Exclui uma variável customizada (defaults não podem ser excluídas). */
    public function destroy(Request $request, string $nome): JsonResponse
    {
        if (isset(SpintaxVariavel::$defaults[$nome])) {
            return response()->json(['message' => 'Variáveis padrão não podem ser excluídas. Use "Restaurar padrão".'], 422);
        }

        SpintaxVariavel::where('tenant_id', $request->user()->tenant_id)
            ->where('nome', $nome)
            ->delete();

        return response()->json(['ok' => true]);
    }
}
