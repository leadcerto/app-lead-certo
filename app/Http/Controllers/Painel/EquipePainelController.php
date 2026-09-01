<?php

namespace App\Http\Controllers\Painel;

use App\Http\Controllers\Controller;
use App\Models\Cargo;
use App\Models\FeedbackAgente;
use App\Models\User;
use App\Services\MediaProcessorService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

class EquipePainelController extends Controller
{
    // ── 1. Catálogo de Funções ──────────────────────────────────────────────────

    public function funcoes(Request $request): View
    {
        $cargos = Cargo::with(['agentes', 'cargoPai', 'subordinados'])
            ->orderBy('ordem')
            ->get();

        // Bloco de suporte ativo (ex: Adriana Aviag)
        $blocoSuporte = Cargo::where('visivel_para_clientes', true)
            ->where('ativo', true)
            ->with('agentes')
            ->first();

        $historicosSuporte = collect();
        if ($blocoSuporte) {
            $historicosSuporte = FeedbackAgente::where('cargo_id', $blocoSuporte->id)
                ->where('tenant_id', $request->user()->tenant_id)
                ->orderBy('created_at')
                ->get();
        }

        return view('equipe.funcoes', compact('cargos', 'blocoSuporte', 'historicosSuporte'));
    }

    public function funcoesStore(Request $request): RedirectResponse
    {
        // Regra estrita: apenas Super Admin pode criar funções
        if (! $request->user()->isAdmin()) {
            abort(403, 'Apenas o Super Administrador pode criar funções.');
        }

        $validated = $request->validate([
            'nome'                  => 'required|string|max:100',
            'tipo'                  => 'required|string|max:50',
            'icone'                 => 'nullable|string|max:50',
            'descricao'             => 'required|string|max:2000',
            'descricao_cliente'     => 'nullable|string|max:2000',
            'detalhes_escopo'       => 'nullable|string|max:5000',
            'ferramentas'           => 'nullable|string|max:500',
            'kpis'                  => 'nullable|string|max:500',
            'diretriz_ia'           => 'nullable|string|max:5000',
            'cargo_pai_id'          => 'nullable|integer|exists:cargos,id',
            'ordem'                 => 'nullable|integer|min:1',
            'visivel_para_clientes' => 'nullable|boolean',
        ]);

        Cargo::create([
            'nome'                  => $validated['nome'],
            'tipo'                  => $validated['tipo'],
            'icone'                 => $validated['icone'] ?? '💼',
            'descricao'             => $validated['descricao'],
            'descricao_cliente'     => $validated['descricao_cliente'] ?? null,
            'detalhes_escopo'       => $validated['detalhes_escopo'] ?? null,
            'ferramentas'           => $validated['ferramentas'] ?? null,
            'kpis'                  => $validated['kpis'] ?? null,
            'diretriz_ia'           => $validated['diretriz_ia'] ?? null,
            'cargo_pai_id'          => $validated['cargo_pai_id'] ?? null,
            'ordem'                 => $validated['ordem'] ?? 1,
            'ativo'                 => true,
            'visivel_para_clientes' => $request->boolean('visivel_para_clientes'),
        ]);

        return back()->with('sucesso', 'Função criada com sucesso.');
    }

    public function funcoesUpdate(Request $request, int $id): RedirectResponse
    {
        if (! $request->user()->isAdmin()) {
            abort(403, 'Apenas o Super Administrador pode editar funções.');
        }

        $cargo = Cargo::findOrFail($id);

        $validated = $request->validate([
            'nome'                  => 'required|string|max:100',
            'tipo'                  => 'required|string|max:50',
            'icone'                 => 'nullable|string|max:50',
            'descricao'             => 'required|string|max:2000',
            'descricao_cliente'     => 'nullable|string|max:2000',
            'detalhes_escopo'       => 'nullable|string|max:5000',
            'ferramentas'           => 'nullable|string|max:500',
            'kpis'                  => 'nullable|string|max:500',
            'diretriz_ia'           => 'nullable|string|max:5000',
            'cargo_pai_id'          => 'nullable|integer|exists:cargos,id',
            'ordem'                 => 'nullable|integer|min:1',
            'ativo'                 => 'nullable|boolean',
            'visivel_para_clientes' => 'nullable|boolean',
        ]);

        $cargo->update([
            'nome'                  => $validated['nome'],
            'tipo'                  => $validated['tipo'],
            'icone'                 => $validated['icone'] ?? $cargo->icone,
            'descricao'             => $validated['descricao'],
            'descricao_cliente'     => $validated['descricao_cliente'] ?? null,
            'detalhes_escopo'       => $validated['detalhes_escopo'] ?? null,
            'ferramentas'           => $validated['ferramentas'] ?? null,
            'kpis'                  => $validated['kpis'] ?? null,
            'diretriz_ia'           => $validated['diretriz_ia'] ?? null,
            'cargo_pai_id'          => $validated['cargo_pai_id'] ?? null,
            'ordem'                 => $validated['ordem'] ?? $cargo->ordem,
            'ativo'                 => $request->boolean('ativo', true),
            'visivel_para_clientes' => $request->boolean('visivel_para_clientes'),
        ]);

        return back()->with('sucesso', 'Função atualizada com sucesso.');
    }

    // ── 2. Agentes de IA ────────────────────────────────────────────────────────

    public function agentesIa(Request $request): View
    {
        $agentes = User::where(function ($q) {
                $q->where('is_ia', true)
                  ->orWhere('tenant_id', 2);
            })
            ->with('cargos')
            ->orderBy('nome')
            ->get();

        $cargos = Cargo::where('ativo', true)->orderBy('ordem')->get();

        return view('equipe.agentes-ia', compact('agentes', 'cargos'));
    }

    public function agentesIaStore(Request $request): RedirectResponse
    {
        if (! $request->user()->isDono()) {
            abort(403, 'Apenas o Super Administrador / Dono pode cadastrar Agentes de IA.');
        }

        $validated = $request->validate([
            'nome'              => 'required|string|max:150',
            'email'             => 'required|email|max:200|unique:users,email',
            'whatsapp'          => 'nullable|string|max:25',
            'avatar_url'        => 'nullable|string|max:500',
            'provedor_ia'       => 'nullable|string|in:openrouter,gemini_direto',
            'gemini_email'      => 'nullable|string|max:255',
            'gemini_api_key'    => 'nullable|string|max:500',
            'gemini_modelo'     => 'nullable|string|max:100',
            'gemini_instrucoes' => 'nullable|string|max:5000',
            'cargos'            => 'nullable|array',
            'cargos.*'          => 'integer|exists:cargos,id',
        ]);

        $agente = User::create([
            'tenant_id'         => 2, // Equipe Lead Certo
            'nome'              => $validated['nome'],
            'email'             => $validated['email'],
            'password'          => Hash::make('LeadCerto@IA#' . rand(1000, 9999)),
            'perfil'            => 'diretor_marketing',
            'whatsapp'          => $validated['whatsapp'] ?? null,
            'avatar_url'        => $validated['avatar_url'] ?? null,
            'is_ia'             => true,
            'provedor_ia'       => $validated['provedor_ia'] ?? 'openrouter',
            'gemini_email'      => $validated['gemini_email'] ?? null,
            'gemini_api_key'    => $validated['gemini_api_key'] ?? null,
            'gemini_modelo'     => $validated['gemini_modelo'] ?? 'gemini-1.5-pro',
            'gemini_instrucoes' => $validated['gemini_instrucoes'] ?? null,
            'ativo'             => true,
        ]);

        if (! empty($validated['cargos'])) {
            $agente->cargos()->sync($validated['cargos']);
        }

        return back()->with('sucesso', 'Agente de IA cadastrado com sucesso.');
    }

    public function agentesIaUpdate(Request $request, int $id): RedirectResponse
    {
        if (! $request->user()->isDono()) {
            abort(403, 'Apenas o Super Administrador / Dono pode editar Agentes de IA.');
        }

        $agente = User::findOrFail($id);

        $validated = $request->validate([
            'nome'              => 'required|string|max:150',
            'email'             => ['required', 'email', 'max:200', Rule::unique('users', 'email')->ignore($agente->id)],
            'whatsapp'          => 'nullable|string|max:25',
            'avatar_url'        => 'nullable|string|max:500',
            'provedor_ia'       => 'nullable|string|in:openrouter,gemini_direto',
            'gemini_email'      => 'nullable|string|max:255',
            'gemini_api_key'    => 'nullable|string|max:500',
            'gemini_modelo'     => 'nullable|string|max:100',
            'gemini_instrucoes' => 'nullable|string|max:5000',
            'ativo'             => 'nullable|boolean',
            'cargos'            => 'nullable|array',
            'cargos.*'          => 'integer|exists:cargos,id',
        ]);

        $agente->update([
            'nome'              => $validated['nome'],
            'email'             => $validated['email'],
            'whatsapp'          => $validated['whatsapp'] ?? null,
            'avatar_url'        => $validated['avatar_url'] ?? null,
            'provedor_ia'       => $validated['provedor_ia'] ?? $agente->provedor_ia ?? 'openrouter',
            'gemini_email'      => $validated['gemini_email'] ?? null,
            'gemini_api_key'    => $validated['gemini_api_key'] ?? $agente->gemini_api_key,
            'gemini_modelo'     => $validated['gemini_modelo'] ?? $agente->gemini_modelo ?? 'gemini-1.5-pro',
            'gemini_instrucoes' => $validated['gemini_instrucoes'] ?? null,
            'ativo'             => $request->boolean('ativo', true),
            'is_ia'             => true,
        ]);

        $agente->cargos()->sync($validated['cargos'] ?? []);

        return back()->with('sucesso', 'Agente de IA atualizado com sucesso.');
    }

    // ── 3. Agentes Humanos ──────────────────────────────────────────────────────

    public function humanos(Request $request): View
    {
        $tenantId = $request->user()->tenant_id;

        // Lista usuários humanos (is_ia = false e não são agentes do tenant 2 da Lead Certo)
        $query = User::where('is_ia', false);

        // Se não for admin global, restringe ao tenant atual
        if (! $request->user()->isDono()) {
            $query->where('tenant_id', $tenantId);
        } else {
            $query->where('tenant_id', '!=', 2);
        }

        $usuarios = $query->with('cargos')->orderBy('nome')->get();

        // Organização por Níveis Hierárquicos
        $grupos = [
            'donos' => [
                'titulo'    => '👑 Donos & Franqueadores',
                'descricao' => 'Controle total da operação, configurações estratégicas e faturamento.',
                'usuarios'  => $usuarios->whereIn('perfil', ['dono', 'admin'])->values(),
            ],
            'diretores' => [
                'titulo'    => '🎖️ Diretores',
                'descricao' => 'Liderança executiva, aprovações e diretrizes de crescimento.',
                'usuarios'  => $usuarios->whereIn('perfil', ['diretor', 'diretor_marketing'])->values(),
            ],
            'gerentes' => [
                'titulo'    => '👔 Gerentes',
                'descricao' => 'Supervisão direta das equipes, acompanhamento de metas e suporte operacional.',
                'usuarios'  => $usuarios->where('perfil', 'gerente')->values(),
            ],
            'coordenadores' => [
                'titulo'    => '🧭 Coordenadores',
                'descricao' => 'Coordenação de processos, distribuição de tarefas e apoio tático.',
                'usuarios'  => $usuarios->whereIn('perfil', ['coordenador', 'gestor'])->values(),
            ],
            'vendedores' => [
                'titulo'    => '💼 Vendedores',
                'descricao' => 'Condução ativa do atendimento no Kanban, negociação e fechamento de vendas.',
                'usuarios'  => $usuarios->whereIn('perfil', ['vendedor', 'pos_venda'])->values(),
            ],
            'sdrs' => [
                'titulo'    => '🎯 SDRs Prospectores',
                'descricao' => 'Qualificação de novos leads, abordagem de contatos e alimentação do funil comercial.',
                'usuarios'  => $usuarios->whereIn('perfil', ['sdr', 'growth_manager', 'auditor', 'avaliador'])->values(),
            ],
        ];

        $totalMembros = $usuarios->count();

        return view('equipe.humanos', compact('grupos', 'totalMembros'));
    }

    // ── 4. Relatório de Uso de IA ───────────────────────────────────────────────

    public function relatorioIa(Request $request): View
    {
        $tenantId = $request->user()->tenantAtual();

        $baseQuery = DB::table('ia_usages')
            ->leftJoin('users', 'ia_usages.agente_id', '=', 'users.id');

        if (! $request->user()->isDono()) {
            $baseQuery->where('ia_usages.tenant_id', $tenantId);
        }

        // Totais gerais
        $totalRequisicoes  = (clone $baseQuery)->count();
        $totalTokensInput  = (clone $baseQuery)->sum('tokens_input');
        $totalTokensOutput = (clone $baseQuery)->sum('tokens_output');
        $mediaLatenciaMs   = round((clone $baseQuery)->avg('latencia_ms') ?: 0);

        // Agrupado por Provedor (OpenRouter vs Gemini Direto)
        $porProvedor = (clone $baseQuery)
            ->select('ia_usages.provedor', DB::raw('count(*) as total'), DB::raw('sum(tokens_input + tokens_output) as tokens'))
            ->groupBy('ia_usages.provedor')
            ->get();

        // Agrupado por Agente / Usuário
        $porAgente = (clone $baseQuery)
            ->select(
                DB::raw('COALESCE(users.nome, "Sistema Lead Certo") as agente_nome'),
                DB::raw('COALESCE(ia_usages.provedor, "openrouter") as provedor'),
                DB::raw('count(*) as total_chamadas'),
                DB::raw('sum(tokens_input) as input_tokens'),
                DB::raw('sum(tokens_output) as output_tokens'),
                DB::raw('avg(latencia_ms) as avg_latencia')
            )
            ->groupBy('users.nome', 'ia_usages.provedor')
            ->orderByDesc('total_chamadas')
            ->get();

        // Últimas 50 requisições
        $ultimosUsos = (clone $baseQuery)
            ->select(
                'ia_usages.*',
                'users.nome as agente_nome',
                'users.avatar_url as agente_avatar'
            )
            ->orderByDesc('ia_usages.id')
            ->limit(50)
            ->get();

        return view('equipe.relatorio-ia', compact(
            'totalRequisicoes',
            'totalTokensInput',
            'totalTokensOutput',
            'mediaLatenciaMs',
            'porProvedor',
            'porAgente',
            'ultimosUsos'
        ));
    }
}
