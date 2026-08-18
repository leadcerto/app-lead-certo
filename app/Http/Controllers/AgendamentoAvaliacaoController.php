<?php

namespace App\Http\Controllers;

use App\Mail\AlertaAvaliadorMail;
use App\Models\AgendamentoAvaliacao;
use App\Models\PerfilGmb;
use App\Services\AgendamentoService;
use App\Services\SorteioTemplateService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rule;

class AgendamentoAvaliacaoController extends Controller
{
    public function __construct(
        protected AgendamentoService $agendamentoService,
        protected SorteioTemplateService $sorteioService,
    ) {}

    /**
     * Visão global dos agendamentos da semana (Admin).
     */
    public function index(Request $request)
    {
        $semana = $request->query('semana')
            ? Carbon::parse($request->query('semana'))
            : Carbon::today();

        $agendamentos = AgendamentoAvaliacao::where('tenant_id', $request->user()->tenant_id)
            ->daSemana($semana)
            ->with(['perfil', 'template.categoria', 'avaliador'])
            ->orderBy('data_agendada')
            ->orderBy('perfil_id')
            ->get();

        // Agrupado por dia — mesmo modelo visual do painel do avaliador.
        $agendamentosPorDia = $agendamentos->groupBy(function ($ag) {
            return $ag->data_agendada->translatedFormat('l, d/m');
        });

        $perfis = PerfilGmb::where('tenant_id', $request->user()->tenant_id)
            ->ativos()
            ->orderBy('nome')
            ->get();

        return view('admin.agendamentos-avaliacao.index', compact(
            'agendamentos', 'agendamentosPorDia', 'perfis', 'semana'
        ));
    }

    /**
     * Formulário de agendamento manual individual.
     */
    public function create(Request $request)
    {
        $perfis = PerfilGmb::where('tenant_id', $request->user()->tenant_id)
            ->ativos()->orderBy('nome')->get();

        return view('admin.agendamentos-avaliacao.create', compact('perfis'));
    }

    /**
     * Salva agendamento individual.
     */
    public function store(Request $request)
    {
        $tenantId = $request->user()->tenant_id;

        $validated = $request->validate([
            'perfil_id' => ['required', Rule::exists('perfis_gmb', 'id')->where('tenant_id', $tenantId)],
            'data_agendada' => 'required|date',
            'template_id' => ['nullable', Rule::exists('templates_avaliacao', 'id')->where('tenant_id', $tenantId)],
            'avaliador_id' => [
                'nullable',
                Rule::exists('users', 'id')->where('tenant_id', $tenantId)->where('perfil', 'avaliador'),
            ],
        ]);

        $perfil = PerfilGmb::where('tenant_id', $tenantId)->findOrFail($validated['perfil_id']);

        try {
            $this->agendamentoService->agendarIndividual(
                $perfil,
                Carbon::parse($validated['data_agendada']),
                $validated['template_id'] ?? null,
                $validated['avaliador_id'] ?? null,
            );

            return redirect()->route('admin.agendamentos-avaliacao.index')
                ->with('sucesso', 'Agendamento criado com sucesso!');
        } catch (\RuntimeException $e) {
            return back()->withErrors(['agendamento' => $e->getMessage()])->withInput();
        }
    }

    /**
     * Tela do Gerador em Lote (Matriz Dias × Perfis).
     */
    public function lote(Request $request)
    {
        $perfis = PerfilGmb::where('tenant_id', $request->user()->tenant_id)
            ->ativos()->orderBy('nome')->get();

        $semana = Carbon::today();

        return view('admin.agendamentos-avaliacao.lote', compact('perfis', 'semana'));
    }

    /**
     * Processa o agendamento em lote.
     */
    public function storeLote(Request $request)
    {
        $validated = $request->validate([
            'matriz' => 'required|array',
            'semana_referencia' => 'required|date',
        ]);

        try {
            $resultado = $this->agendamentoService->agendarEmLote(
                $validated['matriz'],
                Carbon::parse($validated['semana_referencia']),
                $request->user()->tenant_id,
            );

            $msg = "{$resultado['criados']} agendamento(s) criado(s).";
            if (! empty($resultado['avisos'])) {
                $msg .= ' Avisos: '.implode(' | ', $resultado['avisos']);
            }

            return redirect()->route('admin.agendamentos-avaliacao.index')
                ->with('sucesso', $msg);
        } catch (\Throwable $e) {
            return back()->withErrors(['lote' => $e->getMessage()])->withInput();
        }
    }

    /**
     * Altera status de um agendamento (Admin).
     */
    public function alterarStatus(Request $request, AgendamentoAvaliacao $agendamento)
    {
        abort_if($agendamento->tenant_id !== $request->user()->tenant_id, 403);

        $validated = $request->validate([
            'status' => 'required|in:pendente,enviado,concluido',
        ]);

        $agendamento->update([
            'status' => $validated['status'],
            'concluido_em' => $validated['status'] === 'concluido' ? Carbon::now() : null,
        ]);

        return back()->with('sucesso', 'Status atualizado.');
    }

    /**
     * Troca avaliador de um agendamento (Admin).
     */
    public function trocarAvaliador(Request $request, AgendamentoAvaliacao $agendamento)
    {
        abort_if($agendamento->tenant_id !== $request->user()->tenant_id, 403);

        $tenantId = $request->user()->tenant_id;

        $validated = $request->validate([
            'avaliador_id' => [
                'required',
                Rule::exists('users', 'id')
                    ->where('tenant_id', $tenantId)
                    ->where('perfil', 'avaliador'),
            ],
        ]);

        $agendamento->update(['avaliador_id' => $validated['avaliador_id']]);

        return back()->with('sucesso', 'Avaliador alterado.');
    }

    /**
     * Refaz o template de um agendamento via sorteio (Admin).
     */
    public function refazerTemplate(Request $request, AgendamentoAvaliacao $agendamento)
    {
        abort_if($agendamento->tenant_id !== $request->user()->tenant_id, 403);

        $template = $this->sorteioService->sortear(
            $agendamento->perfil,
            $agendamento->data_agendada,
        );

        if (! $template) {
            return back()->withErrors(['template' => 'Nenhum template disponível.']);
        }

        $agendamento->update(['template_id' => $template->id]);

        return back()->with('sucesso', 'Template atualizado para: '.$template->codigo);
    }

    /**
     * Botão "Alertar Avaliadores": envia e-mail manual para todos os
     * avaliadores com tarefas pendentes/enviadas na semana.
     */
    public function alertarAvaliadores(Request $request)
    {
        $tenantId = $request->user()->tenant_id;

        $agendamentosPendentes = AgendamentoAvaliacao::where('tenant_id', $tenantId)
            ->pendentes()
            ->daSemana()
            ->with(['perfil', 'template', 'avaliador'])
            ->get()
            ->groupBy('avaliador_id');

        $enviados = 0;

        foreach ($agendamentosPendentes as $avaliadorId => $tarefas) {
            $avaliador = $tarefas->first()->avaliador;

            if ($avaliador && $avaliador->email) {
                Mail::to($avaliador->email)
                    ->send(new AlertaAvaliadorMail($avaliador, $tarefas));
                $enviados++;
            }
        }

        return back()->with('sucesso', "{$enviados} alerta(s) enviado(s) com sucesso.");
    }
}
