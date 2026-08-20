<?php

namespace App\Http\Controllers\Painel;

use App\Http\Controllers\Controller;
use App\Models\Cargo;
use App\Models\FeedbackAgente;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Redesenho 2026-08-20 (Leonardo): o cliente fala com um SETOR (ex.:
 * "Suporte"), não com uma pessoa por nome — o cargo por trás resolve quem
 * responde. Deliberadamente simples na hora (não passa pela IA de
 * atendimento), mas cria um caso rastreável com análise de viabilidade
 * depois (ver relatorio_analise em FeedbackAgente).
 */
class FeedbackAgenteController extends Controller
{
    public function setores(): View
    {
        $setores = Cargo::where('visivel_para_clientes', true)->where('ativo', true)->orderBy('ordem')->get();

        return view('equipe.setores', compact('setores'));
    }

    public function show(Request $request, int $cargo): View
    {
        $setor = Cargo::where('visivel_para_clientes', true)->findOrFail($cargo);

        $tenantId = $request->user()->tenant_id;

        $historico = FeedbackAgente::where('cargo_id', $setor->id)
            ->where('tenant_id', $tenantId)
            ->orderBy('created_at')
            ->get();

        return view('equipe.conversar', compact('setor', 'historico'));
    }

    public function store(Request $request, int $cargo): RedirectResponse
    {
        $setor = Cargo::where('visivel_para_clientes', true)->findOrFail($cargo);

        $validated = $request->validate(['mensagem' => 'required|string|max:2000']);

        // Quem responde de verdade é quem ocupa o cargo hoje — se tiver
        // mais de um agente no mesmo setor, fica com o primeiro (raro, mas
        // não impede o registro do caso).
        $agente = $setor->agentes()->first();

        FeedbackAgente::create([
            'user_id'       => $agente?->id,
            'cargo_id'      => $setor->id,
            'tenant_id'     => $request->user()->tenant_id,
            'autor_user_id' => $request->user()->id,
            'mensagem'      => $validated['mensagem'],
            'resposta'      => FeedbackAgente::RESPOSTA_PADRAO,
        ]);

        return back()->with('sucesso', 'Mensagem enviada!');
    }
}
