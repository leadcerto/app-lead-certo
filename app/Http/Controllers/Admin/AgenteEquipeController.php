<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Cargo;
use App\Models\FeedbackAgente;
use App\Models\ServicoExecutado;
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
        $agente = User::with(['cargos'])->findOrFail($user);

        $servicos = ServicoExecutado::where('user_id', $user)
            ->orderByDesc('executado_em')
            ->paginate(25);

        $cargos = Cargo::where('ativo', true)->orderBy('ordem')->get();

        $resumo = [
            'total'          => ServicoExecutado::where('user_id', $user)->count(),
            'ultimos_7_dias' => ServicoExecutado::where('user_id', $user)->where('executado_em', '>=', now()->subDays(7))->count(),
            'minutos_totais' => ServicoExecutado::where('user_id', $user)->sum('tempo_gasto_minutos'),
        ];

        $feedbacks = FeedbackAgente::where('user_id', $user)->with('tenant')->latest()->limit(30)->get();

        return view('admin.equipe.show', compact('agente', 'servicos', 'cargos', 'resumo', 'feedbacks'));
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

        return view('admin.equipe.cargos', compact('cargos'));
    }

    public function cargosStore(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'nome'         => 'required|string|max:100',
            'descricao'    => 'required|string|max:2000',
            'cargo_pai_id' => 'nullable|integer|exists:cargos,id',
            'ordem'        => 'nullable|integer|min:1',
        ]);

        Cargo::create([
            'nome'         => $validated['nome'],
            'descricao'    => $validated['descricao'],
            'cargo_pai_id' => $validated['cargo_pai_id'] ?? null,
            'ordem'        => $validated['ordem'] ?? 1,
            'ativo'        => true,
        ]);

        return back()->with('sucesso', 'Cargo criado.');
    }
}
