<?php

namespace App\Http\Controllers\Painel;

use App\Http\Controllers\Controller;
use App\Jobs\ConversationQAJob;
use App\Models\Mensagem;
use App\Models\TicketAtendimento;
use App\Models\VinculoContatoTenant;
use App\Services\UazapiService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class KanbanController extends Controller
{
    public function __construct(
        private UazapiService $uazapi,
    ) {}

    public function view(): View
    {
        return view('kanban.index');
    }

    public function index(Request $request): JsonResponse
    {
        $colunas  = ['lead_novo', 'em_atendimento', 'aguardando_orcamento', 'aguardando_lead', 'encerrado'];
        $tenantId = $request->user()->tenant_id;

        $tickets = TicketAtendimento::with(['contato', 'vendedor'])
            ->withCount(['mensagens as count_midias' => fn ($q) => $q->where('tipo', '!=', 'texto')])
            ->get();

        // Enriquecer contatos com o nome local do parceiro (nome_sugerido pendente de auditoria)
        $contatoIds = $tickets->pluck('contato_id')->filter()->unique();
        $vinculos   = VinculoContatoTenant::whereIn('contato_id', $contatoIds)
            ->where('tenant_id', $tenantId)
            ->get()
            ->keyBy('contato_id');

        $tickets->each(function ($ticket) use ($vinculos) {
            if ($ticket->contato && $vinculos->has($ticket->contato_id)) {
                $v = $vinculos[$ticket->contato_id];
                $ticket->contato->nome_local        = $v->nome_sugerido;
                $ticket->contato->auditoria_pendente = $v->auditoria_pendente;
            }
        });

        $resultado = [];
        foreach ($colunas as $coluna) {
            $resultado[$coluna] = $tickets->groupBy('coluna_kanban')->get($coluna, collect())->values();
        }

        return response()->json($resultado);
    }

    public function assumir(Request $request, int $ticket): JsonResponse
    {
        $model = TicketAtendimento::findOrFail($ticket);

        if ($model->vendedor_id && $model->vendedor_id !== $request->user()->id) {
            return response()->json([
                'message' => 'Já assumido por ' . $model->vendedor->nome . '.',
            ], 409);
        }

        $model->update([
            'vendedor_id'        => $request->user()->id,
            'agente_responsavel' => 'humano',
            'coluna_kanban'      => 'em_atendimento',
        ]);

        return response()->json(['ticket_id' => $ticket, 'assumido' => true]);
    }

    public function mensagens(int $ticket): JsonResponse
    {
        TicketAtendimento::findOrFail($ticket);

        $mensagens = Mensagem::where('ticket_id', $ticket)
            ->orderBy('enviado_em')
            ->get();

        return response()->json($mensagens);
    }

    public function enviarMensagem(Request $request, int $ticket): JsonResponse
    {
        $request->validate(['conteudo' => 'required|string|min:1']);

        $model = TicketAtendimento::findOrFail($ticket);

        if ($model->agente_responsavel !== 'humano') {
            return response()->json([
                'message' => 'A IA está no controle deste atendimento. Assuma o atendimento primeiro.',
            ], 403);
        }

        $telefone = $model->contato->telefone;
        $this->uazapi->enviarMensagem($telefone, $request->conteudo);

        $mensagem = Mensagem::create([
            'ticket_id'  => $ticket,
            'tenant_id'  => $model->tenant_id,
            'remetente'  => 'humano',
            'tipo'       => 'texto',
            'conteudo'   => $request->conteudo,
        ]);

        return response()->json(['mensagem_id' => $mensagem->id, 'enviado' => true], 201);
    }

    public function encerrar(Request $request, int $ticket): JsonResponse
    {
        $request->validate(['tag_desfecho' => 'required|string|max:100']);

        $model = TicketAtendimento::findOrFail($ticket);

        $model->update([
            'status'               => 'encerrado',
            'tag_desfecho'         => $request->tag_desfecho,
            'coluna_kanban'        => 'encerrado',
            'encerrado_em'         => now(),
            'followup_agendado_em' => $request->followup_em ?? null,
        ]);

        ConversationQAJob::dispatch($model->id);

        return response()->json(['ticket_id' => $ticket, 'encerrado' => true]);
    }

    public function liberar(int $ticket): JsonResponse
    {
        $model = TicketAtendimento::findOrFail($ticket);

        $model->update([
            'vendedor_id'        => null,
            'agente_responsavel' => 'bot',
        ]);

        return response()->json(['ticket_id' => $ticket, 'liberado' => true]);
    }

    public function marcarPendente(int $ticket): JsonResponse
    {
        $model = TicketAtendimento::findOrFail($ticket);
        $model->update(['status' => 'pendente']);

        return response()->json(['ticket_id' => $ticket, 'status' => 'pendente']);
    }

    public function resolver(Request $request, int $ticket): JsonResponse
    {
        $model = TicketAtendimento::findOrFail($ticket);

        $model->update([
            'status'        => 'resolvido',
            'tag_desfecho'  => 'resolvido',
            'coluna_kanban' => 'encerrado',
            'encerrado_em'  => now(),
        ]);

        ConversationQAJob::dispatch($model->id);

        return response()->json(['ticket_id' => $ticket, 'status' => 'resolvido']);
    }
}
