<?php

namespace App\Http\Controllers\Painel;

use App\Http\Controllers\Controller;
use App\Models\RespostaPronta;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RespostaProntaController extends Controller
{
    public function view(): View
    {
        return view('configuracoes.respostas-prontas');
    }

    public function index(): JsonResponse
    {
        $tenantId = auth()->user()->tenant_id;

        $respostas = RespostaPronta::doTenant($tenantId)
            ->orderBy('codigo_curto')
            ->get(['id', 'codigo_curto', 'conteudo', 'ativo']);

        return response()->json(['data' => $respostas]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'codigo_curto' => 'required|string|max:60|alpha_dash',
            'conteudo'     => 'required|string|max:2000',
        ]);

        $tenantId = auth()->user()->tenant_id;

        $existe = RespostaPronta::doTenant($tenantId)
            ->where('codigo_curto', $data['codigo_curto'])
            ->exists();

        if ($existe) {
            return response()->json(['message' => 'Já existe uma resposta com esse código.'], 422);
        }

        $resposta = RespostaPronta::create([
            'tenant_id'    => $tenantId,
            'codigo_curto' => strtolower($data['codigo_curto']),
            'conteudo'     => $data['conteudo'],
            'ativo'        => true,
            'created_by'   => auth()->id(),
        ]);

        return response()->json($resposta, 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'codigo_curto' => 'sometimes|string|max:60|alpha_dash',
            'conteudo'     => 'sometimes|string|max:2000',
            'ativo'        => 'sometimes|boolean',
        ]);

        $tenantId = auth()->user()->tenant_id;
        $resposta = RespostaPronta::doTenant($tenantId)->findOrFail($id);

        if (isset($data['codigo_curto'])) {
            $data['codigo_curto'] = strtolower($data['codigo_curto']);
        }

        $resposta->update($data);

        return response()->json($resposta);
    }

    public function destroy(int $id): JsonResponse
    {
        $tenantId = auth()->user()->tenant_id;
        RespostaPronta::doTenant($tenantId)->findOrFail($id)->delete();

        return response()->json(['deleted' => true]);
    }

    public function buscar(Request $request): JsonResponse
    {
        $q        = $request->query('q', '');
        $tenantId = auth()->user()->tenant_id;

        $respostas = RespostaPronta::doTenant($tenantId)
            ->ativo()
            ->where(function ($query) use ($q) {
                $query->where('codigo_curto', 'like', "%{$q}%")
                      ->orWhere('conteudo', 'like', "%{$q}%");
            })
            ->orderBy('codigo_curto')
            ->limit(8)
            ->get(['id', 'codigo_curto', 'conteudo']);

        return response()->json(['data' => $respostas]);
    }
}
