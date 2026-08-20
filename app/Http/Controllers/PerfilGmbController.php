<?php

namespace App\Http\Controllers;

use App\Models\PerfilGmb;
use Illuminate\Http\Request;

class PerfilGmbController extends Controller
{
    /**
     * Lista todos os perfis GMB do tenant.
     */
    public function index(Request $request)
    {
        $perfis = PerfilGmb::where('tenant_id', $request->user()->tenantAtual())
            ->withCount(['contatos as contatos_pendentes_count' => fn ($q) => $q->naoContatados()])
            ->orderBy('nome')
            ->paginate(20);

        return view('admin.perfis-gmb.index', compact('perfis'));
    }

    /**
     * Formulário de criação de novo perfil.
     */
    public function create()
    {
        return view('admin.perfis-gmb.create');
    }

    /**
     * Salva novo perfil GMB.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'nome' => 'required|string|max:200',
            'city' => 'required|string|max:100',
            'state' => 'required|string|size:2',
            'link_gmb' => 'required|url|max:500',
            'google_location_id' => 'nullable|string|max:60',
            'ativo' => 'boolean',
        ]);

        $validated['tenant_id'] = $request->user()->tenantAtual();
        $validated['ativo'] = $validated['ativo'] ?? true;

        PerfilGmb::create($validated);

        return redirect()->route('admin.perfis-gmb.index')
            ->with('sucesso', 'Perfil GMB criado com sucesso!');
    }

    /**
     * Formulário de edição de perfil existente.
     *
     * Nome do parâmetro precisa casar com o nome gerado pela rota resource
     * ('perfis-gmb' → snake_case 'perfis_gmb') pra o binding implícito do
     * Laravel funcionar — com outro nome (ex.: $perfilGmb) o binding falha
     * silenciosamente e injeta um model vazio em vez do registro real.
     */
    public function edit(Request $request, PerfilGmb $perfisGmb)
    {
        abort_if($perfisGmb->tenant_id !== $request->user()->tenantAtual(), 403);

        return view('admin.perfis-gmb.edit', ['perfil' => $perfisGmb]);
    }

    /**
     * Atualiza perfil GMB existente.
     */
    public function update(Request $request, PerfilGmb $perfisGmb)
    {
        abort_if($perfisGmb->tenant_id !== $request->user()->tenantAtual(), 403);

        $validated = $request->validate([
            'nome' => 'required|string|max:200',
            'city' => 'required|string|max:100',
            'state' => 'required|string|size:2',
            'link_gmb' => 'required|url|max:500',
            'google_location_id' => 'nullable|string|max:60',
            'ativo' => 'boolean',
        ]);

        $validated['ativo'] = $request->has('ativo');

        $perfisGmb->update($validated);

        return redirect()->route('admin.perfis-gmb.index')
            ->with('sucesso', 'Perfil atualizado com sucesso!');
    }

    /**
     * Remove perfil GMB (soft: desativa).
     */
    public function destroy(Request $request, PerfilGmb $perfisGmb)
    {
        abort_if($perfisGmb->tenant_id !== $request->user()->tenantAtual(), 403);

        $perfisGmb->update(['ativo' => false]);

        return redirect()->route('admin.perfis-gmb.index')
            ->with('sucesso', 'Perfil desativado com sucesso.');
    }
}
