<?php

namespace App\Http\Controllers;

use App\Models\AgendamentoAvaliacao;
use App\Models\ContatoAvaliacao;
use Carbon\Carbon;
use Illuminate\Http\Request;

class AvaliadorDashboardController extends Controller
{
    /**
     * Dashboard do Avaliador.
     * Exibe apenas as avaliações do avaliador logado, filtradas pela semana atual.
     *
     * Cada tarefa mostra:
     * - Nome da empresa (PerfilGmb)
     * - Link de referência do perfil no Google Meu Negócio
     * - Texto sugerido pra oferecer ao cliente por telefone
     * - Telefones de clientes reais ainda não contatados pra esse perfil
     *   (lista solta, sem vínculo com a data agendada — ver ContatoAvaliacao)
     * - Botão "Concluir" (confirma que ligou e ofereceu o texto — quem publica
     *   a avaliação, se quiser, é o próprio cliente na conta dele)
     */
    public function index(Request $request)
    {
        $semana = $request->query('semana')
            ? Carbon::parse($request->query('semana'))
            : Carbon::today();

        $agendamentos = AgendamentoAvaliacao::doAvaliador($request->user()->id)
            ->daSemana($semana)
            ->with(['perfil.tenant', 'template.categoria'])
            ->orderBy('data_agendada')
            ->get();

        // Agrupar por dia da semana para exibição
        $tarefasPorDia = $agendamentos->groupBy(function ($agendamento) {
            return $agendamento->data_agendada->translatedFormat('l, d/m');
        });

        // Contatos ainda não ligados, por perfil (só dos perfis que aparecem
        // nas tarefas desta semana) — lista solta, o avaliador escolhe quem
        // ligar, sem vínculo automático com um agendamento específico.
        $contatosPorPerfil = ContatoAvaliacao::whereIn('perfil_id', $agendamentos->pluck('perfil_id')->unique())
            ->naoContatados()
            ->orderBy('created_at')
            ->get()
            ->groupBy('perfil_id');

        // Estatísticas da semana
        $totalSemana   = $agendamentos->count();
        $concluidos    = $agendamentos->where('status', AgendamentoAvaliacao::STATUS_CONCLUIDO)->count();
        $pendentes     = $agendamentos->whereIn('status', [
            AgendamentoAvaliacao::STATUS_PENDENTE,
            AgendamentoAvaliacao::STATUS_ENVIADO,
        ])->count();
        $atrasados     = $agendamentos->filter->estaAtrasado()->count();

        return view('avaliador.dashboard', compact(
            'tarefasPorDia', 'semana', 'contatosPorPerfil',
            'totalSemana', 'concluidos', 'pendentes', 'atrasados'
        ));
    }

    /**
     * Ação "Concluir": marca agendamento como concluído.
     * O avaliador só pode concluir seus próprios agendamentos.
     */
    public function concluir(Request $request, AgendamentoAvaliacao $agendamento)
    {
        // Verificar que o agendamento pertence ao avaliador logado
        if ($agendamento->avaliador_id !== $request->user()->id) {
            abort(403, 'Você não tem permissão para concluir esta avaliação.');
        }

        // Verificar que não está já concluído
        if ($agendamento->status === AgendamentoAvaliacao::STATUS_CONCLUIDO) {
            return back()->with('aviso', 'Esta avaliação já foi concluída.');
        }

        $agendamento->concluir();

        // Identificar se foi concluído com atraso
        $mensagem = 'Avaliação concluída com sucesso! ✅';
        if ($agendamento->concluidoComAtraso()) {
            $mensagem = 'Avaliação concluída (com atraso). ⚠️';
        }

        return back()->with('sucesso', $mensagem);
    }

    /**
     * Marca um telefone da lista de contatos como já ligado. Qualquer
     * avaliador do tenant pode marcar (a lista não é exclusiva de um
     * avaliador específico — é compartilhada por perfil).
     */
    public function concluirContato(Request $request, ContatoAvaliacao $contato)
    {
        abort_if($contato->tenant_id !== $request->user()->tenant_id, 403);

        $contato->marcarContatado();

        return back()->with('sucesso', 'Contato marcado como ligado. ✅');
    }
}
