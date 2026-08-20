<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AcessoAgente;
use App\Models\Cargo;
use App\Models\FeedbackAgente;
use App\Models\ServicoExecutado;
use App\Models\TicketAtendimento;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Perfil dos agentes da equipe Lead Certo (Adriana, Nathanel, futuros) —
 * pedido do Leonardo em 2026-08-20: identidade, quais cargos cada um ocupa
 * (um agente pode ocupar vários ao mesmo tempo) e o histórico de serviços
 * que já executaram. Todos os agentes da equipe vivem no tenant "Lead
 * Certo" (id=2) — é o mesmo critério usado em outros pontos da sessão.
 *
 * Só admin acessa — é visão interna da própria equipe, não algo que um
 * tenant cliente deveria ver.
 */
class AgenteEquipeController extends Controller
{
    public function index(): View
    {
        $agentes = User::where('tenant_id', 2)
            ->withCount('servicosExecutados')
            ->with('cargos')
            ->orderBy('nome')
            ->get();

        return view('admin.equipe.index', compact('agentes'));
    }

    public function show(int $user): View
    {
        $agente = User::with(['cargos', 'acessos'])->findOrFail($user);

        $servicos = ServicoExecutado::where('user_id', $user)
            ->orderByDesc('executado_em')
            ->paginate(25);

        $cargos = Cargo::where('ativo', true)->orderBy('ordem')->get();

        $resumo = [
            'total'          => ServicoExecutado::where('user_id', $user)->count(),
            'ultimos_7_dias' => ServicoExecutado::where('user_id', $user)->where('executado_em', '>=', now()->subDays(7))->count(),
            'minutos_totais' => ServicoExecutado::where('user_id', $user)->sum('tempo_gasto_minutos'),
        ];

        $feedbacks = FeedbackAgente::where('user_id', $user)->with(['tenant', 'cargo'])->latest()->limit(30)->get();

        // Sugestão 4 (2026-08-20): resumo agregado do feedback, não só a
        // lista crua — total, este mês, e por empresa (sinal de satisfação
        // com algum volume, não só "teve ou não teve").
        $feedbackResumo = [
            'total'      => FeedbackAgente::where('user_id', $user)->count(),
            'este_mes'   => FeedbackAgente::where('user_id', $user)->where('created_at', '>=', now()->startOfMonth())->count(),
            'por_empresa' => FeedbackAgente::where('user_id', $user)
                ->with('tenant:id,nome')
                ->get()
                ->groupBy(fn ($f) => $f->tenant?->nome ?? 'Empresa removida')
                ->map->count()
                ->sortDesc(),
        ];

        // Sugestão 6 (2026-08-20): atividade real no Kanban do próprio
        // tenant do agente (ex.: tickets que a Adriana atendeu como
        // vendedora no Kanban de suporte da Lead Certo) — distinta do log
        // manual de serviços executados.
        $atividadeKanban = TicketAtendimento::withoutGlobalScopes()
            ->where('tenant_id', $agente->tenant_id)
            ->where('vendedor_id', $agente->id)
            ->with('contato:id,nome,telefone')
            ->orderByDesc('updated_at')
            ->limit(10)
            ->get(['id', 'contato_id', 'coluna_kanban', 'status', 'updated_at']);

        return view('admin.equipe.show', compact(
            'agente', 'servicos', 'cargos', 'resumo', 'feedbacks', 'feedbackResumo', 'atividadeKanban'
        ));
    }

    public function acessosStore(Request $request, int $user): RedirectResponse
    {
        User::findOrFail($user);

        $validated = $request->validate([
            'servico'       => 'required|string|max:100',
            'identificador' => 'required|string|max:200',
        ]);

        AcessoAgente::create([
            'user_id'       => $user,
            'servico'       => $validated['servico'],
            'identificador' => $validated['identificador'],
            'ativo'         => true,
        ]);

        return back()->with('sucesso', 'Acesso registrado.');
    }

    public function acessosToggle(int $user, int $acesso): RedirectResponse
    {
        $registro = AcessoAgente::where('user_id', $user)->findOrFail($acesso);
        $registro->update(['ativo' => ! $registro->ativo]);

        return back()->with('sucesso', 'Acesso atualizado.');
    }

    public function update(Request $request, int $user): RedirectResponse
    {
        $agente = User::findOrFail($user);

        $validated = $request->validate([
            'whatsapp'   => 'nullable|string|max:30',
            'avatar_url' => 'nullable|url|max:500',
        ]);

        $agente->update($validated);

        return back()->with('sucesso', 'Perfil atualizado.');
    }

    public function sincronizarCargos(Request $request, int $user): RedirectResponse
    {
        $agente = User::findOrFail($user);

        $validated = $request->validate([
            'cargo_ids'   => 'array',
            'cargo_ids.*' => 'integer|exists:cargos,id',
        ]);

        $agente->cargos()->sync($validated['cargo_ids'] ?? []);

        return back()->with('sucesso', 'Cargos atualizados.');
    }

    public function registrarServico(Request $request, int $user): RedirectResponse
    {
        User::findOrFail($user);

        $validated = $request->validate([
            'descricao'            => 'required|string|max:500',
            'motivo'               => 'nullable|string|max:500',
            'grau_dificuldade'     => 'required|in:baixo,medio,alto',
            'tempo_gasto_minutos'  => 'nullable|integer|min:0|max:1440',
            'executado_em'         => 'nullable|date',
        ]);

        ServicoExecutado::create([
            'user_id'             => $user,
            'descricao'           => $validated['descricao'],
            'motivo'              => $validated['motivo'] ?? null,
            'grau_dificuldade'    => $validated['grau_dificuldade'],
            'tempo_gasto_minutos' => $validated['tempo_gasto_minutos'] ?? null,
            'executado_em'        => $validated['executado_em'] ?? now(),
        ]);

        return back()->with('sucesso', 'Serviço registrado.');
    }

    // ── Cargos (catálogo da estrutura organizacional) ──────────────────────

    public function cargosIndex(): View
    {
        $cargos = Cargo::withCount('agentes')->with('cargoPai')->orderBy('ordem')->get();

        // Sugestão 5 (2026-08-20): agrupa por hierarquia (cargo_pai_id) pra
        // a view renderizar os subordinados recuados embaixo do cargo pai,
        // em vez de uma lista plana sem relação visual entre eles.
        $topo         = $cargos->whereNull('cargo_pai_id')->values();
        $subordinados = $cargos->whereNotNull('cargo_pai_id')->groupBy('cargo_pai_id');

        return view('admin.equipe.cargos', compact('cargos', 'topo', 'subordinados'));
    }

    public function cargosStore(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'nome'              => 'required|string|max:100',
            'descricao'         => 'required|string|max:2000',
            'descricao_cliente' => 'nullable|string|max:2000',
            'cargo_pai_id'      => 'nullable|integer|exists:cargos,id',
            'ordem'             => 'nullable|integer|min:1',
        ]);

        Cargo::create([
            'nome'                   => $validated['nome'],
            'descricao'              => $validated['descricao'],
            'descricao_cliente'      => $validated['descricao_cliente'] ?? null,
            'cargo_pai_id'           => $validated['cargo_pai_id'] ?? null,
            'ordem'                  => $validated['ordem'] ?? 1,
            'ativo'                  => true,
            'visivel_para_clientes'  => $request->boolean('visivel_para_clientes'),
        ]);

        return back()->with('sucesso', 'Cargo criado.');
    }

    public function cargosToggleVisivel(int $cargo): RedirectResponse
    {
        $registro = Cargo::findOrFail($cargo);
        $registro->update(['visivel_para_clientes' => ! $registro->visivel_para_clientes]);

        return back()->with('sucesso', 'Visibilidade pro cliente atualizada.');
    }

    // ── Feedback dos clientes: análise de viabilidade ──────────────────────

    public function feedbackAnalise(Request $request, int $feedback): RedirectResponse
    {
        $registro = FeedbackAgente::findOrFail($feedback);

        $validated = $request->validate([
            'status'                          => 'required|in:pendente,em_analise,concluido',
            'relatorio_analise'               => 'nullable|string|max:5000',
            'implementacao_faz_sentido'       => 'nullable|boolean',
            'tempo_estimado_execucao'         => 'nullable|string|max:100',
            'empresas_beneficiadas_estimado'  => 'nullable|integer|min:0',
        ]);

        $registro->update($validated);

        return back()->with('sucesso', 'Análise registrada.');
    }
}
