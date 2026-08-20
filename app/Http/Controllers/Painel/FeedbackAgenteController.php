<?php

namespace App\Http\Controllers\Painel;

use App\Http\Controllers\Controller;
use App\Models\FeedbackAgente;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Pedido do Leonardo 2026-08-20: campo onde a empresa logada fala direto
 * com um agente (Adriana, Nathanel...) — deliberadamente simples, não passa
 * pela IA de atendimento. Objetivo: feedback, satisfação, problemas,
 * soluções e próximos passos, discutidos depois em reunião de equipe.
 */
class FeedbackAgenteController extends Controller
{
    public function show(Request $request, int $user): View
    {
        // Só agentes da equipe Lead Certo (tenant "casa" = Lead Certo,
        // id=2) podem receber feedback por aqui — não é um canal genérico
        // pra qualquer usuário do sistema.
        $agente = User::where('tenant_id', 2)->findOrFail($user);

        $tenantId = $request->user()->tenant_id;

        $historico = FeedbackAgente::where('user_id', $agente->id)
            ->where('tenant_id', $tenantId)
            ->orderBy('created_at')
            ->get();

        return view('equipe.conversar', compact('agente', 'historico'));
    }

    public function store(Request $request, int $user): RedirectResponse
    {
        $agente = User::where('tenant_id', 2)->findOrFail($user);

        $validated = $request->validate(['mensagem' => 'required|string|max:2000']);

        FeedbackAgente::create([
            'user_id'       => $agente->id,
            'tenant_id'     => $request->user()->tenant_id,
            'autor_user_id' => $request->user()->id,
            'mensagem'      => $validated['mensagem'],
            'resposta'      => FeedbackAgente::RESPOSTA_PADRAO,
        ]);

        return back()->with('sucesso', 'Mensagem enviada!');
    }
}
