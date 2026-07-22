<?php

namespace App\Http\Controllers\Painel;

use App\Enums\PapelColunaKanban;
use App\Http\Controllers\Controller;
use App\Jobs\ConversationQAJob;
use App\Jobs\GerarResumoTicketJob;
use App\Models\KanbanColuna;
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

    /**
     * Cada coluna do Kanban rola verticalmente dentro da própria altura fixa,
     * então não precisa de "carregar mais" — só um teto de segurança pra não
     * transferir uma coluna inteira caso "encerrado" cresça muito ao longo dos anos.
     */
    private const LIMITE_COLUNA = 500;

    public function index(Request $request): JsonResponse
    {
        $tenantId = $request->user()->tenant_id;
        $colunas  = \App\Models\KanbanColuna::chavesDoTenant($tenantId);

        $todosTickets = collect();
        $totais       = [];

        // Último remetente da conversa — usado pra saber se o lead respondeu
        // e ainda ninguém (humano) voltou pra ele.
        $ultimoRemetenteSub = Mensagem::select('remetente')
            ->whereColumn('ticket_id', 'tickets_atendimento.id')
            ->orderByDesc('id')
            ->limit(1);

        // Horário da última mensagem — usado como desempate dentro de cada
        // grupo de prioridade, pra quem está com conversa ativa agora (em
        // qualquer direção, inclusive quando o humano responde direto pelo
        // WhatsApp Web) sempre aparecer mais acima do que um card parado.
        $ultimaMensagemEmSub = Mensagem::select('enviado_em')
            ->whereColumn('ticket_id', 'tickets_atendimento.id')
            ->orderByDesc('id')
            ->limit(1);

        // Prioridade de exibição dentro da coluna: 0 = lead esperando resposta
        // humana, 1 = tem retorno agendado ou etiqueta Pendente, 2 = resto.
        // Escrito como SQL bruto com tenant_id literal (inteiro confiável, vem
        // do usuário autenticado) pra não misturar bindings do TenantScope do
        // Mensagem com os do addSelect abaixo.
        $tenantIdInt   = (int) $tenantId;
        $prioridadeRaw = "
            CASE
                WHEN tickets_atendimento.agente_responsavel = 'humano' AND (
                    SELECT remetente FROM mensagens
                    WHERE mensagens.ticket_id = tickets_atendimento.id
                    AND mensagens.tenant_id = {$tenantIdInt}
                    ORDER BY mensagens.id DESC LIMIT 1
                ) = 'lead' AND (
                    tickets_atendimento.visualizado_em IS NULL OR tickets_atendimento.visualizado_em < (
                        SELECT enviado_em FROM mensagens
                        WHERE mensagens.ticket_id = tickets_atendimento.id
                        AND mensagens.tenant_id = {$tenantIdInt}
                        ORDER BY mensagens.id DESC LIMIT 1
                    )
                ) THEN 0
                WHEN tickets_atendimento.retorno_agendado_em IS NOT NULL OR tickets_atendimento.pendente_desde IS NOT NULL THEN 1
                ELSE 2
            END
        ";

        foreach ($colunas as $coluna) {
            $query = TicketAtendimento::where('coluna_kanban', $coluna);

            $totais[$coluna] = (clone $query)->count();

            $ticketsColuna = $query->with(['contato', 'vendedor'])
                ->withCount(['mensagens as count_midias' => fn ($q) => $q->where('tipo', '!=', 'texto')])
                ->addSelect([
                    'ultimo_remetente'   => $ultimoRemetenteSub,
                    'ultima_mensagem_em' => $ultimaMensagemEmSub,
                ])
                ->orderByRaw($prioridadeRaw)
                ->orderByDesc('ultima_mensagem_em')
                ->limit(self::LIMITE_COLUNA)
                ->get();

            $ticketsColuna->each(function ($ticket) {
                // ultima_mensagem_em vem de um addSelect bruto — não é auto-cast pra
                // Carbon como os campos declarados em $casts, por isso o parse manual.
                $jaVisualizadoDepoisDaUltima = $ticket->visualizado_em && $ticket->ultima_mensagem_em
                    && $ticket->visualizado_em->gte(\Illuminate\Support\Carbon::parse($ticket->ultima_mensagem_em));

                $ticket->precisa_resposta = $ticket->agente_responsavel === 'humano'
                    && $ticket->ultimo_remetente === 'lead'
                    && ! $jaVisualizadoDepoisDaUltima;
            });

            $todosTickets = $todosTickets->concat($ticketsColuna);
        }

        // Enriquecer contatos com o nome local do parceiro (nome_sugerido pendente de auditoria)
        $contatoIds = $todosTickets->pluck('contato_id')->filter()->unique();
        $vinculos   = VinculoContatoTenant::whereIn('contato_id', $contatoIds)
            ->where('tenant_id', $tenantId)
            ->get()
            ->keyBy('contato_id');

        $todosTickets->each(function ($ticket) use ($vinculos) {
            if ($ticket->contato && $vinculos->has($ticket->contato_id)) {
                $v = $vinculos[$ticket->contato_id];
                $ticket->contato->nome_local        = $v->nome_sugerido;
                $ticket->contato->auditoria_pendente = $v->auditoria_pendente;
            }
        });

        $agrupado  = $todosTickets->groupBy('coluna_kanban');
        $resultado = [];
        foreach ($colunas as $coluna) {
            $resultado[$coluna] = [
                'tickets' => $agrupado->get($coluna, collect())->values(),
                'total'   => $totais[$coluna],
            ];
        }

        // Metadado das colunas do tenant (label/emoji/papel) pro frontend parar
        // de hardcodar a lista fixa — as chaves de $resultado acima continuam
        // como estão hoje, isso só adiciona uma chave nova ao lado delas.
        $resultado['colunas'] = \App\Models\KanbanColuna::query()
            ->whereIn('chave', $colunas)
            ->orderBy('ordem')
            ->get(['chave', 'label', 'emoji', 'papel'])
            ->map(fn ($c) => [
                'chave' => $c->chave,
                'label' => $c->label,
                'emoji' => $c->emoji,
                'papel' => $c->papel->value,
            ]);

        return response()->json($resultado);
    }

    /**
     * Estado atual de um único ticket, buscado direto pelo ID — usado pra
     * ressincronizar o card aberto no modal. Diferente do index(), não
     * depende de o ticket estar dentro do recorte de LIMITE_COLUNA por
     * coluna: um ticket "encerrado" antigo, empurrado pra fora da fatia
     * carregada no board, nunca seria encontrado pelo polling e o modal
     * ficava travado mostrando a coluna de antes pra sempre.
     */
    public function show(Request $request, int $ticket): JsonResponse
    {
        $model = TicketAtendimento::with('contato')->findOrFail($ticket);

        if ($model->contato) {
            $vinculo = VinculoContatoTenant::where('contato_id', $model->contato_id)
                ->where('tenant_id', $request->user()->tenant_id)
                ->first();

            if ($vinculo) {
                $model->contato->nome_local        = $vinculo->nome_sugerido;
                $model->contato->auditoria_pendente = $vinculo->auditoria_pendente;
            }
        }

        return response()->json($model);
    }

    public function assumir(Request $request, int $ticket): JsonResponse
    {
        $model = TicketAtendimento::findOrFail($ticket);

        if ($conflito = $this->assumirAutomaticamente($model, $request->user())) {
            return $conflito;
        }

        return response()->json(['ticket_id' => $ticket, 'assumido' => true]);
    }

    /**
     * Garante que o usuário atual está no controle do ticket antes de mandar
     * mensagem/mídia — assume sozinho se o ticket ainda estiver com a IA ou
     * sem dono, sem precisar de um clique separado em "Assumir" antes de poder
     * digitar. Só bloqueia se outra pessoa já tiver assumido.
     */
    private function assumirAutomaticamente(TicketAtendimento $model, $usuario): ?JsonResponse
    {
        if ($model->vendedor_id && $model->vendedor_id !== $usuario->id) {
            return response()->json([
                'message' => 'Já assumido por ' . $model->vendedor->nome . '.',
            ], 409);
        }

        if ($model->agente_responsavel !== 'humano' || $model->vendedor_id !== $usuario->id) {
            $model->update([
                'vendedor_id'        => $usuario->id,
                'agente_responsavel' => 'humano',
            ]);
        }

        return null;
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

        $model = TicketAtendimento::with(['contato', 'tenant'])->findOrFail($ticket);

        if ($conflito = $this->assumirAutomaticamente($model, $request->user())) {
            return $conflito;
        }

        $telefone = $model->contato->telefone;
        $token    = $model->tenant->uazapi_instance_token;

        if (! $token) {
            return response()->json(['message' => 'Instância do WhatsApp não configurada para este tenant.'], 502);
        }

        $enviado = $this->uazapi->enviarTexto($token, $telefone, $request->conteudo);

        if (! $enviado) {
            return response()->json(['message' => 'Falha ao enviar pelo WhatsApp.'], 502);
        }

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

        $model->update($model->dadosParaEncerrar([
            'tag_desfecho'         => $request->tag_desfecho,
            'encerrado_em'         => now(),
            'followup_agendado_em' => $request->followup_em ?? null,
        ]));

        ConversationQAJob::dispatch($model->id);
        GerarResumoTicketJob::dispatch($model->id)->delay(now()->addSeconds(5));

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

    /**
     * "Pendente" é uma etiqueta independente (não mexe em status nem coluna) —
     * sinaliza "tenho uma pergunta em aberto com o lead, aguardando resposta".
     * Clicar de novo desmarca (alterna).
     */
    /**
     * Marca que o usuário abriu/leu o ticket agora — usado pra tirar o destaque
     * azul mesmo sem responder. Chamado sempre que o card é aberto no painel.
     */
    public function visualizar(int $ticket): JsonResponse
    {
        $model = TicketAtendimento::findOrFail($ticket);
        $model->update(['visualizado_em' => now()]);

        return response()->json(['ok' => true]);
    }

    public function marcarPendente(int $ticket): JsonResponse
    {
        $model = TicketAtendimento::findOrFail($ticket);
        $model->update(['pendente_desde' => $model->pendente_desde ? null : now()]);

        return response()->json(['ticket_id' => $ticket, 'pendente_desde' => $model->pendente_desde]);
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
        $tenantId = $request->user()->tenant_id;
        $colunas  = \App\Models\KanbanColuna::chavesDoTenant($tenantId);

        $request->validate([
            'coluna' => ['required', 'string', Rule::in($colunas)],
        ]);

        $model        = TicketAtendimento::findOrFail($ticket);
        $colunaAntes  = $model->coluna_kanban;
        $colunaDepois = $request->coluna;

        $updates = ['coluna_kanban' => $colunaDepois];

        // Reabre o status se estava encerrado e foi movido manualmente pra fora
        // do Encerrado — sem isso a coluna muda mas o ticket continua com
        // status 'encerrado' por baixo, escondendo a caixa de mensagem inteira.
        if (KanbanColuna::papelDe($tenantId, $colunaAntes) === PapelColunaKanban::Encerramento
            && KanbanColuna::papelDe($tenantId, $colunaDepois) !== PapelColunaKanban::Encerramento) {
            $updates['status'] = 'aberto';
        }

        $model->update($updates);

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

        if ($conflito = $this->assumirAutomaticamente($model, $request->user())) {
            return $conflito;
        }

        $arquivo  = $request->file('arquivo');
        $caption  = $request->input('caption', '');
        $telefone = $model->contato->telefone;
        $token    = $model->tenant->uazapi_instance_token;

        $path     = $arquivo->store('kanban-midia', 'public');
        $url      = url('storage/' . $path);
        $filename = $arquivo->getClientOriginalName();

        $ehFigurinha = $tipo === 'imagem' && strtolower($arquivo->getClientOriginalExtension()) === 'webp';

        $enviado = match (true) {
            $ehFigurinha          => $this->uazapi->enviarSticker($token, $telefone, $url),
            $tipo === 'imagem'    => $this->uazapi->enviarImagem($token, $telefone, $url, $caption),
            $tipo === 'audio'     => $this->uazapi->enviarAudio($token, $telefone, $url, true),
            $tipo === 'documento' => $this->uazapi->enviarDocumento($token, $telefone, $url, $filename, $caption),
            default               => false,
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
        $tenantId     = $request->user()->tenant_id;
        $colunaOutros = \App\Models\KanbanColuna::primeiraChaveComPapel($tenantId, \App\Enums\PapelColunaKanban::TransferenciaHumana);

        if (! $colunaOutros) {
            return response()->json(['message' => 'Nenhuma coluna de Transferência Humana configurada.'], 422);
        }

        $model = TicketAtendimento::findOrFail($ticket);

        $model->update([
            'coluna_kanban'      => $colunaOutros,
            'agente_responsavel' => 'humano',
            'vendedor_id'        => $request->user()->id,
        ]);

        return response()->json(['ticket_id' => $ticket, 'coluna_kanban' => $colunaOutros]);
    }
}
