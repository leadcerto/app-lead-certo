<?php

namespace App\Http\Controllers\Painel;

use App\Http\Controllers\Controller;
use App\Jobs\ConversationQAJob;
use App\Models\Mensagem;
use App\Models\TicketAtendimento;
use App\Models\VinculoContatoTenant;
use App\Services\SequenciaService;
use App\Services\UazapiService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

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
        $colunas  = ['lead_novo', 'em_atendimento', 'aguardando_orcamento', 'aguardando_lead', 'pagamento', 'servico_agendado', 'encerrado', 'outros'];
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

    public function liberarEAcionarIA(int $ticket): JsonResponse
    {
        $model = TicketAtendimento::findOrFail($ticket);

        $model->update([
            'vendedor_id'        => null,
            'agente_responsavel' => 'bot',
        ]);

        dispatch(new \App\Jobs\SdrResponderJob($ticket, '', false, true));

        return response()->json(['ticket_id' => $ticket, 'liberado' => true, 'ia_acionada' => true]);
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

    public function agendarRetorno(Request $request, int $ticket): JsonResponse
    {
        $request->validate([
            'retorno_em' => ['nullable', 'date'],
        ]);

        $model = TicketAtendimento::findOrFail($ticket);
        $model->update([
            'retorno_agendado_em' => $request->retorno_em ? \Carbon\Carbon::parse($request->retorno_em) : null,
        ]);

        return response()->json([
            'ticket_id'          => $ticket,
            'retorno_agendado_em' => $model->retorno_agendado_em?->toDateString(),
        ]);
    }

    public function mover(Request $request, int $ticket): JsonResponse
    {
        $colunas = ['lead_novo', 'em_atendimento', 'aguardando_orcamento', 'aguardando_lead', 'pagamento', 'servico_agendado', 'encerrado', 'outros'];

        $request->validate([
            'coluna' => ['required', 'string', Rule::in($colunas)],
        ]);

        $model        = TicketAtendimento::findOrFail($ticket);
        $colunaAntes  = $model->coluna_kanban;
        $colunaDepois = $request->coluna;

        $model->update(['coluna_kanban' => $colunaDepois]);

        // Ao entrar em aguardando_lead: dispara sequência de follow-up
        if ($colunaDepois === 'aguardando_lead' && $colunaAntes !== 'aguardando_lead') {
            app(SequenciaService::class)->iniciarParaTicket($model);
        }

        return response()->json(['ticket_id' => $ticket, 'coluna_kanban' => $colunaDepois]);
    }

    public function enviarMidia(Request $request, int $ticket): JsonResponse
    {
        $tipo = $request->input('tipo');

        $request->validate([
            'tipo'    => 'required|in:imagem,audio,documento',
            'caption' => 'nullable|string|max:500',
            'arquivo' => [
                'required', 'file', 'max:32768',
                Rule::when($tipo === 'imagem',    'mimes:jpg,jpeg,png,webp,gif'),
                Rule::when($tipo === 'audio',     'mimes:mp3,ogg,webm,m4a,wav'),
                Rule::when($tipo === 'documento', 'mimes:pdf,doc,docx,xls,xlsx,txt,zip'),
            ],
        ]);

        $model = TicketAtendimento::with(['contato', 'tenant'])->findOrFail($ticket);

        if ($model->agente_responsavel !== 'humano') {
            return response()->json(['message' => 'Assuma o atendimento primeiro.'], 403);
        }

        $arquivo  = $request->file('arquivo');
        $caption  = $request->input('caption', '');
        $telefone = $model->contato->telefone;
        $token    = $model->tenant->uazapi_instance_token;

        $path     = $arquivo->store('kanban-midia', 'public');
        $url      = url('storage/' . $path);
        $filename = $arquivo->getClientOriginalName();

        $enviado = match ($tipo) {
            'imagem'    => $this->uazapi->enviarImagem($token, $telefone, $url, $caption),
            'audio'     => $this->uazapi->enviarAudio($token, $telefone, $url, true),
            'documento' => $this->uazapi->enviarDocumento($token, $telefone, $url, $filename, $caption),
            default     => false,
        };

        if (! $enviado) {
            Storage::disk('public')->delete($path);
            return response()->json(['message' => 'Falha ao enviar pelo WhatsApp.'], 502);
        }

        $mensagem = Mensagem::create([
            'ticket_id'  => $ticket,
            'tenant_id'  => $model->tenant_id,
            'remetente'  => 'humano',
            'tipo'       => $tipo,
            'conteudo'   => $caption ?: ($tipo === 'audio' ? '[Áudio]' : $filename),
            'midia_url'  => $url,
            'enviado_em' => now(),
        ]);

        return response()->json(['mensagem_id' => $mensagem->id, 'enviado' => true], 201);
    }

    public function moverParaOutros(Request $request, int $ticket): JsonResponse
    {
        $model = TicketAtendimento::findOrFail($ticket);

        $model->update([
            'coluna_kanban'      => 'outros',
            'agente_responsavel' => 'humano',
            'vendedor_id'        => $request->user()->id,
        ]);

        return response()->json(['ticket_id' => $ticket, 'coluna_kanban' => 'outros']);
    }
}
