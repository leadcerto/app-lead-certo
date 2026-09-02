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
use App\Services\AudioConversorService;
use App\Services\EcoTranscricaoService;
use App\Services\MediaProcessorService;
use App\Services\SequenciaService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class KanbanController extends Controller
{
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
                $pendenteNome = $v->campos_pendentes_auditoria['nome'] ?? null;
                $ticket->contato->nome_local        = $pendenteNome['sugerido'] ?? null;
                $ticket->contato->auditoria_pendente = (bool) $pendenteNome;
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
                $pendenteNome = $vinculo->campos_pendentes_auditoria['nome'] ?? null;
                $model->contato->nome_local        = $pendenteNome['sugerido'] ?? null;
                $model->contato->auditoria_pendente = (bool) $pendenteNome;
            }
        }

        // Achado real 2026-08-20 (Leonardo): o painel "Aguardando orientação"
        // só mostrava um campo vazio, sem dizer qual foi a dúvida real do
        // agente — impossível responder direito sem saber o que ele não
        // soube. Anexa aqui o alerta que causou a pausa (qualquer um dos 3
        // tipos que pausam: dúvida explícita [DUVIDA:], rejeição de área
        // alucinada, handoff prematuro), pro front mostrar o texto de verdade.
        if ($model->aguardando_orientacao_em) {
            $model->alerta_pendente = \App\Models\AlertaInterno::where('ticket_id', $model->id)
                ->whereIn('tipo', ['duvida_ia', 'rejeicao_area_alucinada', 'handoff_prematuro'])
                ->whereNull('respondido_em')
                ->latest('created_at')
                ->first(['id', 'tipo', 'titulo', 'conteudo', 'created_at']);
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
        // Se já tiver outro vendedor e o usuário logado não for Dono nem o próprio vendedor, avisa conflito
        if ($model->vendedor_id && $model->vendedor_id !== $usuario->id && ! $usuario->isDono()) {
            $nomeVendedor = $model->vendedor?->nome ?? 'outro atendente';
            return response()->json([
                'message' => 'Já assumido por ' . $nomeVendedor . '.',
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

        try {
            $model = TicketAtendimento::with(['contato', 'tenant', 'canal', 'vendedor'])->findOrFail($ticket);

            if ($conflito = $this->assumirAutomaticamente($model, $request->user())) {
                return $conflito;
            }

            $telefone = $model->contato?->telefone;
            if (! $telefone) {
                return response()->json(['message' => 'Contato sem número de telefone cadastrado.'], 422);
            }

            $canal = $this->resolverCanal($model);

            if (! $canal) {
                return response()->json(['message' => 'Nenhum canal de WhatsApp conectado no momento.'], 502);
            }

            // Item 11 do roteiro (2026-08-20): traduz o texto do atendente pro
            // idioma do lead antes de enviar — "eu falo com você em português e
            // você traduz pra ele" (Leonardo). Falha de tradução nunca bloqueia
            // o envio, manda o texto original em português mesmo.
            $textoParaEnviar = $request->conteudo;
            $idiomaEnviado   = 'pt';
            $conteudoPt      = null;

            if ($model->idioma_lead && $model->idioma_lead !== 'pt') {
                try {
                    $traduzido = app(\App\Services\TraducaoService::class)->traduzir($request->conteudo, $model->idioma_lead);
                    if ($traduzido) {
                        $textoParaEnviar = $traduzido;
                        $idiomaEnviado   = $model->idioma_lead;
                        $conteudoPt      = $request->conteudo;
                    }
                } catch (\Throwable $e) {
                    Log::warning('KanbanController: falha na tradução da mensagem', ['erro' => $e->getMessage()]);
                }
            }

            $enviado = $canal->servico()->enviarTextoDireto($canal, $telefone, $textoParaEnviar);

            if (! $enviado) {
                return response()->json(['message' => 'Falha ao enviar pelo WhatsApp. Verifique a conexão do canal.'], 502);
            }

            $mensagem = Mensagem::create([
                'ticket_id'   => $ticket,
                'tenant_id'   => $model->tenant_id,
                'remetente'   => 'humano',
                'tipo'        => 'texto',
                'conteudo'    => $textoParaEnviar,
                'idioma'      => $idiomaEnviado,
                'conteudo_pt' => $conteudoPt,
                'enviado_em'  => now(),
            ]);

            return response()->json(['mensagem_id' => $mensagem->id, 'enviado' => true], 201);
        } catch (\Throwable $e) {
            Log::error('KanbanController: erro ao enviar mensagem no ticket', [
                'ticket_id' => $ticket,
                'erro'      => $e->getMessage(),
                'trace'     => $e->getTraceAsString(),
            ]);

            return response()->json(['message' => 'Erro interno ao processar envio: ' . $e->getMessage()], 500);
        }
    }

    public function encerrar(Request $request, int $ticket): JsonResponse
    {
        $request->validate(['tag_desfecho' => 'required|string|max:100']);

        $model = TicketAtendimento::findOrFail($ticket);

        // Regra 13 (Bloco 5) — terceiro e último endpoint de movimentação
        // manual do sistema (os outros dois, mover()/moverParaOutros(), já
        // marcam desde o Bloco 4).
        $model->origemMudancaColuna = 'humano';
        $model->update($model->dadosParaEncerrar([
            'tag_desfecho'         => $request->tag_desfecho,
            'encerrado_em'         => now(),
            'followup_agendado_em' => $request->followup_em ?? null,
        ]));

        ConversationQAJob::dispatch($model->id);
        GerarResumoTicketJob::dispatch($model->id)->delay(now()->addSeconds(5));

        return response()->json(['ticket_id' => $ticket, 'encerrado' => true]);
    }

    // liberar()/liberarEAcionarIA() (botões "Devolver"/"Devolver + IA")
    // removidos em 2026-08-20 — decisão do Leonardo: essa devolução manual
    // era redundante com o mecanismo automático que já existe
    // (ReassumirAgente, `conversas:reassumir-agente`, agendado no
    // scheduler) — o agente já para de agir quando um humano assume, e
    // volta sozinho depois do timeout configurado por coluna, analisando o
    // contexto atual da conversa inteira. Não tinha nenhum teste cobrindo
    // esses 2 endpoints (confirmado antes de remover).

    /**
     * Regra 2 (Bloco 3): o humano orienta uma dúvida do agente por aqui — não
     * pelo chat normal (que assumiria a conversa inteira). Limpa o estado de
     * espera, registra a resposta no alerta correspondente, e redispara o
     * agente com a orientação injetada só nessa chamada — o agente continua
     * no controle da conversa.
     */
    public function orientar(Request $request, int $ticket): JsonResponse
    {
        $request->merge(['orientacao' => trim((string) $request->input('orientacao'))]);
        $request->validate(['orientacao' => 'required|string|min:1|max:2000']);

        $model = TicketAtendimento::findOrFail($ticket);

        // Ao orientar a IA, passa o controle para o bot e remove qualquer pausa
        $model->update([
            'agente_responsavel'       => 'bot',
            'aguardando_orientacao_em' => null,
            'mensagem_espera_enviada'  => false,
        ]);

        // Fecha alerta pendente ou registra nova orientação no histórico de alertas
        $alerta = \App\Models\AlertaInterno::where('tenant_id', $model->tenant_id)
            ->where('ticket_id', $ticket)
            ->whereIn('tipo', ['duvida_ia', 'rejeicao_area_alucinada', 'handoff_prematuro', 'orientacao_humana'])
            ->whereNull('resposta')
            ->latest('id')
            ->first();

        if ($alerta) {
            $alerta->update([
                'resposta'      => $request->orientacao,
                'respondido_em' => now(),
            ]);
        } else {
            \App\Models\AlertaInterno::create([
                'tenant_id'     => $model->tenant_id,
                'tipo'          => 'orientacao_humana',
                'titulo'        => 'Orientação do Atendente',
                'conteudo'      => 'Instrução para condução e aprendizado da IA',
                'ticket_id'     => $ticket,
                'resposta'      => $request->orientacao,
                'respondido_em' => now(),
            ]);
        }

        dispatch(new \App\Jobs\SdrResponderJob($ticket, '', false, true, 0, $request->orientacao));

        return response()->json(['ok' => true]);
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

        // Regra 13 (Bloco 4) — este é um dos dois únicos endpoints de
        // movimentação manual do sistema (drag-and-drop do board).
        $model->origemMudancaColuna = 'humano';
        $model->update($updates);

        // Ao entrar em aguardando_lead: dispara sequência de follow-up
        if ($colunaDepois === 'aguardando_lead' && $colunaAntes !== 'aguardando_lead') {
            app(SequenciaService::class)->iniciarParaTicket($model);
        }

        return response()->json(['ticket_id' => $ticket, 'coluna_kanban' => $colunaDepois]);
    }

    // Formatos de áudio que a Meta Cloud API aceita pra mensagens (aac/amr/mpeg/mp4/ogg,
    // ver docs/superpowers/specs/2026-07-31-erro-formato-audio-canal-oficial-design.md) —
    // subconjunto do que a validação de upload abaixo aceita (mp3,ogg,webm,m4a,wav).
    // webm/wav são aceitos pelo upload mas rejeitados pela Meta — daí a checagem extra
    // só pro canal Covercut logo abaixo.
    private const AUDIO_EXTENSOES_ACEITAS_COVERCUT = ['mp3', 'ogg', 'm4a'];

    public function enviarMidia(Request $request, int $ticket): JsonResponse
    {
        $tipo  = $request->input('tipo');
        $model = TicketAtendimento::with(['contato', 'tenant', 'canal'])->findOrFail($ticket);
        $canal = $this->resolverCanal($model);

        $ehCovercut  = $canal?->provider === 'covercut';
        $extensao    = strtolower((string) $request->file('arquivo')?->getClientOriginalExtension());
        $ehFigurinha = $tipo === 'imagem' && $extensao === 'webp';

        // Limites reais de tamanho da Meta Cloud API — imagem 5MB, sticker
        // (webp) 500KB, áudio 16MB, documento 100MB. A doc da Covercut não
        // detalha isso (consultado em 2026-08-14), mas ela é um pass-through
        // da API oficial da Meta, então o limite da própria plataforma vale
        // independente do provedor intermediário — não é suposição por
        // analogia. Sem isso, o upload passava direto na validação e só
        // falhava na chamada real à API, virando o 502 genérico "Falha ao
        // enviar pelo WhatsApp" sem explicar o motivo real pro atendente.
        // Uazapi (WhatsApp Web, canal não-oficial) mantém o limite antigo de
        // 32MB — não tem essa restrição de plataforma, sem mudança aqui.
        $maxKb = match (true) {
            ! $ehCovercut         => 32768,
            $ehFigurinha          => 500,
            $tipo === 'imagem'    => 5120,
            $tipo === 'audio'     => 16384,
            $tipo === 'documento' => 102400,
            default               => 32768,
        };

        // GIF nunca é aceito como imagem pela Meta Cloud API (só jpeg/png; um
        // .webp aqui é roteado como figurinha, não imagem — ver $ehFigurinha
        // acima). Uazapi (WhatsApp Web) continua aceitando gif normalmente.
        $mimesImagem = $ehCovercut ? 'mimes:jpg,jpeg,png,webp' : 'mimes:jpg,jpeg,png,webp,gif';

        $request->validate([
            'tipo'    => 'required|in:imagem,audio,documento',
            'caption' => 'nullable|string|max:500',
            'arquivo' => [
                'required', 'file', "max:{$maxKb}",
                Rule::when($tipo === 'imagem',    $mimesImagem),
                Rule::when($tipo === 'audio',     'mimes:mp3,ogg,webm,m4a,wav'),
                Rule::when($tipo === 'documento', 'mimes:pdf,doc,docx,xls,xlsx,txt,zip'),
            ],
        ]);

        if ($conflito = $this->assumirAutomaticamente($model, $request->user())) {
            return $conflito;
        }

        $arquivo  = $request->file('arquivo');
        $caption  = $request->input('caption', '');
        $telefone = $model->contato->telefone;

        if (! $canal) {
            return response()->json(['message' => 'Nenhum canal de WhatsApp vinculado a este atendimento.'], 502);
        }

        // Item revertido em 2026-08-21 (ver docs/superpowers/specs/2026-07-31-erro-formato-audio-canal-oficial-design.md
        // pro histórico da decisão original): áudio gravado no microfone do
        // painel sai em .webm, que a Meta Cloud API não aceita. Antes só
        // avisava o atendente; agora converte pra .ogg (opus) via ffmpeg
        // antes de mandar. Se a conversão falhar (ffmpeg ausente/erro), cai
        // de volta na mensagem de erro clara — nunca manda o arquivo
        // incompatível, nunca quebra com erro genérico.
        $mimeParaTranscricao = $arquivo->getMimeType();

        if ($tipo === 'audio' && $ehCovercut) {
            $extensaoAudio = $arquivo->guessExtension() ?: strtolower($arquivo->getClientOriginalExtension());
            if (! in_array($extensaoAudio, self::AUDIO_EXTENSOES_ACEITAS_COVERCUT, true)) {
                $convertido = app(AudioConversorService::class)->paraOgg($arquivo->getRealPath());

                if (! $convertido) {
                    return response()->json([
                        'message' => "O canal Oficial (WhatsApp Business) não aceita áudio nesse formato (.{$extensaoAudio}). Anexe um arquivo de áudio nos formatos .mp3, .ogg ou .m4a.",
                    ], 422);
                }

                $path = Storage::disk('public')->putFileAs(
                    'kanban-midia', new \Illuminate\Http\File($convertido), Str::random(40) . '.ogg'
                );
                @unlink($convertido);
                $mimeParaTranscricao = 'audio/ogg';
            }
        }

        $path     ??= $arquivo->store('kanban-midia', 'public');
        $url      = url('storage/' . $path);
        $filename = $arquivo->getClientOriginalName();

        $servico = $canal->servico();

        $enviado = match (true) {
            $ehFigurinha          => $servico->enviarSticker($canal, $telefone, $url),
            $tipo === 'imagem'    => $servico->enviarImagem($canal, $telefone, $url, $caption),
            $tipo === 'audio'     => $servico->enviarAudio($canal, $telefone, $url, true),
            $tipo === 'documento' => $servico->enviarDocumento($canal, $telefone, $url, $filename, $caption),
            default               => false,
        };

        if (! $enviado) {
            Storage::disk('public')->delete($path);
            return response()->json(['message' => 'Falha ao enviar pelo WhatsApp.'], 502);
        }

        // Transcreve o áudio gravado/anexado direto no painel — sem isso ficava só
        // com o placeholder "[Áudio]", diferente do áudio do lead e do atendente
        // via WhatsApp Web, que já são transcritos de verdade.
        $transcricaoBruta = null;
        if ($tipo === 'audio') {
            try {
                $transcricaoAtiva = \App\Models\KanbanColunaConfig::withoutGlobalScopes()
                    ->where('tenant_id', $model->tenant_id)
                    ->where('coluna_kanban', $model->coluna_kanban)
                    ->value('transcricao_ativa') ?? true;

                $transcricaoBruta = app(MediaProcessorService::class)->transcreverArquivo(
                    Storage::disk('public')->get($path),
                    $mimeParaTranscricao,
                    $transcricaoAtiva
                );
            } catch (\Throwable $e) {
                Log::warning('KanbanController: falha ao transcrever áudio enviado pelo painel', [
                    'ticket_id' => $ticket,
                    'erro'      => $e->getMessage(),
                ]);
            }
        }

        $conteudoAudio = $transcricaoBruta ? "[Áudio transcrito: {$transcricaoBruta}]" : '[Áudio]';

        $mensagem = Mensagem::create([
            'ticket_id'  => $ticket,
            'tenant_id'  => $model->tenant_id,
            'remetente'  => 'humano',
            'tipo'       => $tipo,
            'conteudo'   => $caption ?: ($tipo === 'audio' ? $conteudoAudio : $filename),
            'midia_url'  => $url,
            'enviado_em' => now(),
        ]);

        // Ecoa a transcrição como mensagem de texto separada na conversa, igual
        // ao áudio do lead e do atendente via WhatsApp Web — mesma cobertura,
        // ver EcoTranscricaoService.
        if ($transcricaoBruta) {
            app(EcoTranscricaoService::class)->enviar($canal, $model, $telefone, $transcricaoBruta, $model->nomePersonaDisplay());
        }

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

        // Regra 13 (Bloco 4) — segundo dos dois endpoints de movimentação manual.
        $model->origemMudancaColuna = 'humano';
        $model->update([
            'coluna_kanban'      => $colunaOutros,
            'agente_responsavel' => 'humano',
            'vendedor_id'        => $request->user()->id,
        ]);

        return response()->json(['ticket_id' => $ticket, 'coluna_kanban' => $colunaOutros]);
    }

    private function resolverCanal(TicketAtendimento $model): ?WhatsappCanal
    {
        if ($model->canal && $model->canal->status === 'connected') {
            return $model->canal;
        }

        $kanban = \App\Models\Kanban::where('tenant_id', $model->tenant_id)->where('tipo', 'vendas')->first();
        $canal  = null;

        if ($kanban) {
            $canal = app(\App\Services\SelecaoCanalWhatsappService::class)->naoOficialAleatorioParaKanban($kanban);
        }

        if (! $canal) {
            $canal = \App\Models\WhatsappCanal::where('tenant_id', $model->tenant_id)
                ->where('status', 'connected')
                ->first();
        }

        if ($canal && $model->whatsapp_canal_id !== $canal->id) {
            $model->updateQuietly(['whatsapp_canal_id' => $canal->id]);
            $model->setRelation('canal', $canal);
        }

        return $canal ?: $model->canal;
    }
}
