<?php

namespace App\Http\Controllers\Painel;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\AgentSkill;

class AgentSkillController extends Controller
{
    public function index()
    {
        $tenantId = auth()->user()->tenant_id;
        
        $skills = AgentSkill::where(function($q) use ($tenantId) {
                $q->whereNull('tenant_id')
                  ->orWhere('tenant_id', $tenantId);
            })
            ->orderByRaw("FIELD(origem, 'autoral', 'lead_certo', 'comprada')")
            ->orderBy('nome')
            ->get();

        return view('configuracoes.skills.index', compact('skills'));
    }

    public function store(Request $request)
    {
        $request->validate([
            'nome' => 'required|string|max:255',
            'titulo' => 'required|string|max:255',
            'descricao_curta' => 'required|string|max:255',
            'descricao_completa' => 'nullable|string',
            'instrucoes_base' => 'nullable|string',
        ]);

        // Verifica unicidade
        if (AgentSkill::where('tenant_id', auth()->user()->tenant_id)->where('nome', $request->nome)->exists()) {
            return back()->with('error', 'Já existe uma skill com este identificador (nome).');
        }

        AgentSkill::create([
            'tenant_id' => auth()->user()->tenant_id,
            'origem' => 'autoral',
            'nome' => $request->nome,
            'titulo' => $request->titulo,
            'descricao_curta' => $request->descricao_curta,
            'descricao_completa' => $request->descricao_completa,
            'instrucoes_base' => $request->instrucoes_base,
            'ativa' => true,
        ]);

        return back()->with('success', 'Skill Autoral criada com sucesso!');
    }

    public function update(Request $request, $id)
    {
        $skill = AgentSkill::where('tenant_id', auth()->user()->tenant_id)->findOrFail($id);

        $request->validate([
            'nome' => 'required|string|max:255',
            'titulo' => 'required|string|max:255',
            'descricao_curta' => 'required|string|max:255',
            'descricao_completa' => 'nullable|string',
            'instrucoes_base' => 'nullable|string',
            'ativa' => 'nullable|boolean'
        ]);

        if (AgentSkill::where('tenant_id', auth()->user()->tenant_id)->where('nome', $request->nome)->where('id', '!=', $id)->exists()) {
            return back()->with('error', 'Já existe uma skill com este identificador (nome).');
        }

        $skill->update([
            'nome' => $request->nome,
            'titulo' => $request->titulo,
            'descricao_curta' => $request->descricao_curta,
            'descricao_completa' => $request->descricao_completa,
            'instrucoes_base' => $request->instrucoes_base,
            'ativa' => $request->has('ativa') ? $request->ativa : $skill->ativa,
        ]);

        return back()->with('success', 'Skill atualizada com sucesso!');
    }

    public function duplicate($id)
    {
        $tenantId = auth()->user()->tenant_id;
        
        $skillBase = AgentSkill::where(function($q) use ($tenantId) {
                $q->whereNull('tenant_id')
                  ->orWhere('tenant_id', $tenantId);
            })->findOrFail($id);

        $novoNome = $skillBase->nome . '-copy-' . time();

        AgentSkill::create([
            'tenant_id' => $tenantId,
            'origem' => 'autoral',
            'nome' => $novoNome,
            'titulo' => $skillBase->titulo . ' (Cópia)',
            'descricao_curta' => $skillBase->descricao_curta,
            'descricao_completa' => $skillBase->descricao_completa,
            'instrucoes_base' => $skillBase->instrucoes_base,
            'ativa' => true,
        ]);

        return back()->with('success', 'Skill copiada para suas Autorais! Agora você pode editá-la.');
    }

    public function destroy($id)
    {
        $skill = AgentSkill::where('tenant_id', auth()->user()->tenant_id)
            ->where('origem', 'autoral')
            ->findOrFail($id);

        $skill->delete();

        return back()->with('success', 'Skill autoral removida.');
    }
}
