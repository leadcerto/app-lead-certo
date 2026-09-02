<?php

namespace App\Services;

use App\Enums\PapelColunaKanban;
use App\Models\KanbanColuna;
use App\Models\KanbanColunaConfig;
use App\Models\KanbanColunaObjetivo;
use App\Models\Mensagem;
use App\Models\SdrPersona;
use App\Models\TicketAtendimento;
use Illuminate\Support\Facades\Log;

class SdrResponderService
{
    public function __construct(
        private LeadRouterService $router,
        private OpenRouterService $openRouter,
    ) {}

    /**
     * Seleciona persona, gera resposta via OpenRouter, envia com humanização, persiste.
     * Retorna o texto da resposta ou null se falhar.
     */
    public function responder(TicketAtendimento $ticket, bool $origemLigacao = false, ?string $gatilho = null, ?string $orientacaoHumana = null): ?string
    {
        $ticket->loadMissing(['contato', 'persona', 'mensagens', 'tenant', 'canal']);

        // Regra 2/4: enquanto o ticket aguarda orientação humana sobre uma
        // dúvida, o agente não gera nenhuma resposta — nem pra este chamador
        // nem pros outros 3 (FollowupConversas x2, Internal\TicketController).
        // Quem redispara com a orientação (Task 5, via SdrResponderJob) já
        // limpa aguardando_orientacao_em ANTES de chamar responder() de novo,
        // então esse guard nunca bloqueia o redisparo legítimo.
        if ($ticket->aguardando_orientacao_em) {
            Log::info('SdrResponder: ticket aguardando orientação humana, resposta suprimida', ['ticket_id' => $ticket->id]);
            return null;
        }

        // ── 1. Selecionar/confirmar persona ─────────────────────────────────
        $persona = $ticket->persona;
        if (! $persona) {
            $tags    = $this->tagsDoContato($ticket);
            $persona = $this->router->rotear($ticket->tenant_id, $tags);

            if (! $persona) {
                Log::warning('SdrResponder: nenhuma persona encontrada', ['ticket_id' => $ticket->id]);
                return null;
            }

            $ticket->update(['sdr_persona_id' => $persona->id]);
        }

        // ── 2. Montar histórico para o OpenRouter ────────────────────────────
        $messages = $this->montarHistorico($persona, $ticket, $origemLigacao, $gatilho, $orientacaoHumana);

        // ── 3. Chamar o Motor de IA (OpenRouter ou Google Gemini Direto) ─────
        $agenteIa = \App\Models\User::where('tenant_id', $ticket->tenant_id)
            ->where('is_ia', true)
            ->where('ativo', true)
            ->first();

        $tier     = $ticket->etapa_ia === 'etapa_2' ? 'complexo' : 'simples';
        $resposta = null;

        if ($agenteIa && $agenteIa->provedor_ia === 'gemini_direto') {
            $resposta = app(\App\Services\GeminiDirectService::class)->chat(
                messages:  $messages,
                apiKey:    $agenteIa->gemini_api_key,
                modelo:    $agenteIa->gemini_modelo ?: 'gemini-1.5-pro',
                maxTokens: 400,
                origem:    'sdr',
                tenantId:  $ticket->tenant_id,
                agenteId:  $agenteIa->id
            );
        }

        // Se o provedor for OpenRouter ou se o Gemini direto falhar/estiver sem chave
        if ($resposta === null) {
            $resposta = $this->openRouter->chat(
                messages:  $messages,
                tier:      $tier,
                maxTokens: 400,
                origem:    'sdr',
                tenantId:  $ticket->tenant_id,
                agenteId:  $agenteIa?->id
            );
        }

        if (! $resposta) {
            Log::error('SdrResponder: Motor de IA sem resposta', ['ticket_id' => $ticket->id]);
            return null;
        }

        // ── 3.5. Detectar dúvida (Regra 2) ───────────────────────────────────
        // Se o agente decidiu pausar (instrução de autovalidação da Regra 7,
        // ver montarHistorico()), a resposta inteira é só esse token — nenhum
        // outro processamento (movimento de coluna, objetivos, envio) roda.
        if (preg_match('/\[D[UÚ]VIDA\s*:\s*(.+?)\]/isu', $resposta, $matchDuvida)) {
            $resumo = trim($matchDuvida[1]);

            $ticket->update([
                'aguardando_orientacao_em' => now(),
                'mensagem_espera_enviada'  => false,
            ]);

            try {
                app(\App\Services\AlertaInternoService::class)->criar(
                    $ticket->tenant_id,
                    'duvida_ia',
                    'Agente pediu orientação',
                    $resumo,
                    $ticket->id,
                );
            } catch (\Exception $e) {
                Log::warning('SdrResponder: falha ao criar alerta de dúvida', [
                    'ticket_id' => $ticket->id, 'erro' => $e->getMessage(),
                ]);
            }

            Log::info('SdrResponder: pausado aguardando orientação', ['ticket_id' => $ticket->id, 'resumo' => $resumo]);

            return null;
        }

        // ── 3.6. Rejeição de área alucinada (achado real 2026-08-19/20) ──────
        // O modelo respondeu recusando atendimento por área ("atende só aqui no
        // Rio e região") sem nenhuma instrução dizendo isso — confirmado 5x em
        // produção, sempre respondendo pergunta que não tinha nada a ver com área
        // (ex.: "vcs são de onde?"), em endereço que ESTAVA dentro da área real. A
        // instrução de autovalidação (Regra 7, acima) já pede pra nunca inventar
        // informação, mas o modelo não segue de forma confiável — trava de código
        // como rede de segurança, mesmo tratamento do [DUVIDA:]: pausa e alerta,
        // nunca deixa a mentira sair pro lead.
        if (preg_match('/atend(?:e|emos)\s+s[oó]\s+aqui\s+no\s+rio\s+e\s+regi[aã]o/iu', $resposta)) {
            $ticket->update([
                'aguardando_orientacao_em' => now(),
                'mensagem_espera_enviada'  => false,
            ]);

            try {
                app(\App\Services\AlertaInternoService::class)->criar(
                    $ticket->tenant_id,
                    'rejeicao_area_alucinada',
                    'Agente recusou atendimento por área sem instrução pra isso',
                    "Resposta bloqueada antes de enviar: \"{$resposta}\"",
                    $ticket->id,
                );
            } catch (\Exception $e) {
                Log::warning('SdrResponder: falha ao criar alerta de rejeição alucinada', [
                    'ticket_id' => $ticket->id, 'erro' => $e->getMessage(),
                ]);
            }

            Log::warning('SdrResponder: bloqueada rejeição de área alucinada, ticket pausado', [
                'ticket_id' => $ticket->id, 'resposta' => $resposta,
            ]);

            return null;
        }

        // ── 3.7. Handoff prematuro pro orçamento (achado real 2026-08-20) ────
        // O modelo às vezes manda a frase fixa de encerramento (ver Regra de
        // handoff em montarHistorico()) achando que terminou o checklist sem
        // ter terminado de verdade — caso real: ticket com só 1 mensagem do
        // lead, nenhum endereço, e o modelo mandou "Já peguei toda a visão...
        // vou passar pro setor de orçamento" mesmo assim. Mesma rede de
        // segurança do guardrail de área: só deixa passar se os objetivos
        // ativos da coluna atual estiverem REALMENTE marcados como cumpridos
        // (não confia só na palavra do modelo).
        if (preg_match('/j[aá]\s+peguei\s+toda\s+a\s+vis[aã]o|vou\s+passar\s+essa\s+ficha\s+agora\s+pro\s+nosso\s+setor\s+de\s+or[çc]amento/iu', $resposta)) {
            $idsAtivos = KanbanColunaObjetivo::withoutGlobalScopes()
                ->where('tenant_id', $ticket->tenant_id)
                ->where('coluna_kanban', $ticket->coluna_kanban)
                ->where('ativo', true)
                ->pluck('id')
                ->all();

            // Mesmo critério de "Coluna sem nenhum objetivo configurado ...
            // trata como cumprido" já usado em objetivosCumpridosAoEncerrar()
            // (TicketAtendimento) — objetivos são opt-in, não dá pra julgar
            // incompleto o que a coluna nem rastreia.
            $cumpridos      = $ticket->objetivos_cumpridos ?? [];
            $checklistFecha = empty($idsAtivos) || empty(array_diff($idsAtivos, $cumpridos));

            if (! $checklistFecha) {
                $ticket->update([
                    'aguardando_orientacao_em' => now(),
                    'mensagem_espera_enviada'  => false,
                ]);

                try {
                    app(\App\Services\AlertaInternoService::class)->criar(
                        $ticket->tenant_id,
                        'handoff_prematuro',
                        'Agente tentou encerrar o checklist sem ter terminado',
                        "Resposta bloqueada antes de enviar: \"{$resposta}\" — objetivos pendentes: "
                            . implode(', ', array_diff($idsAtivos, $cumpridos)),
                        $ticket->id,
                    );
                } catch (\Exception $e) {
                    Log::warning('SdrResponder: falha ao criar alerta de handoff prematuro', [
                        'ticket_id' => $ticket->id, 'erro' => $e->getMessage(),
                    ]);
                }

                Log::warning('SdrResponder: bloqueado handoff prematuro, ticket pausado', [
                    'ticket_id' => $ticket->id, 'resposta' => $resposta,
                ]);

                return null;
            }
        }

        // ── 4. Detectar token de movimento de coluna e aplicar ──────────────
        // Token = chave da coluna em maiúsculas entre colchetes. Gerado dinamicamente
        // a partir das colunas reais do tenant — se o franqueado renomear uma coluna,
        // o token muda junto (a tela de config mostra o token atual como dica).
        $tenantId = $ticket->tenant_id;
        $chaves   = \App\Models\KanbanColuna::chavesDoTenant($tenantId);

        $moveu = false;
        foreach ($chaves as $chave) {
            $token = '[' . mb_strtoupper($chave) . ']';

            if (str_contains($resposta, $token)) {
                // Achado 1 da revisão final: token redundante da coluna onde o
                // ticket já está (o próprio system prompt oferece qualquer token
                // "em qualquer etapa", inclusive o da atual) não move nada de
                // fato — pula sem marcar $moveu, mas CONTINUA o loop, porque um
                // token de OUTRA coluna pode aparecer na mesma resposta e esse
                // sim precisa mover de verdade.
                if ($chave === $ticket->coluna_kanban) {
                    continue;
                }

                $etapa = KanbanColunaConfig::withoutGlobalScopes()
                    ->where('tenant_id', $tenantId)
                    ->where('coluna_kanban', $chave)
                    ->value('etapa_ia_ao_mover') ?? 'etapa_1';

                $papel   = \App\Models\KanbanColuna::papelDe($tenantId, $chave);
                $updates = $papel === \App\Enums\PapelColunaKanban::Encerramento
                    ? $ticket->dadosParaEncerrar(['etapa_ia' => $etapa], $chave)
                    : ['coluna_kanban' => $chave, 'etapa_ia' => $etapa];
                // objetivos_cumpridos é zerado automaticamente pelo hook do model
                // (TicketAtendimento::updating) sempre que coluna_kanban muda e este
                // update não o define explicitamente — ver Achado 2 da revisão final.

                // Bloco 5 — este é o único ponto do sistema onde a própria IA
                // decide mover a coluna em tempo real (não política automática
                // de outro comando/webhook) — marca 'ia' explicitamente pro
                // guardrail de salto (Regra 13) saber diferenciar os dois casos.
                $ticket->origemMudancaColuna = 'ia';
                $ticket->update($updates);
                Log::info("SdrResponder: → {$chave} via token {$token}", ['ticket_id' => $ticket->id]);
                $moveu = true;
                break;
            }
        }
        $tokens   = array_map(fn (string $chave) => '[' . mb_strtoupper($chave) . ']', $chaves);
        $resposta = trim(str_replace($tokens, '', $resposta));

        // ── 4.5. Detectar tokens de objetivo cumprido e aplicar ─────────────
        // Mesmo padrão dos tokens de movimento acima — o agente reporta na
        // própria resposta quais objetivos do checklist da coluna considera
        // cumpridos. Delegado pro AvancoAutomaticoKanbanService, que também
        // avança a coluna sozinho quando a checklist fecha.
        //
        // Só roda se a seção "4" acima NÃO já moveu o ticket explicitamente
        // ($moveu === false) — se a IA já mandou mover pra outra coluna nesta
        // mesma resposta, os ids do token de objetivo se referem à coluna de
        // ONDE ela veio (que já mudou), não faz sentido tentar marcar contra
        // a nova coluna nem tentar avançar de novo por cima.
        preg_match_all('/\[OBJETIVO_CUMPRIDO:(\d+)\]/', $resposta, $matchesObjetivos);
        if (! empty($matchesObjetivos[1]) && ! $moveu) {
            $ids = array_map('intval', $matchesObjetivos[1]);
            app(AvancoAutomaticoKanbanService::class)->marcarObjetivos($ticket, $ids);
            Log::info('SdrResponder: objetivos marcados como cumpridos', [
                'ticket_id' => $ticket->id, 'ids' => $matchesObjetivos[1],
            ]);

            // Achado 3 da revisão final: marcarObjetivos() recarrega o ticket
            // internamente e pode avançar a coluna numa instância separada —
            // sem o refresh, o $ticket em memória aqui ficaria com a coluna
            // antiga pro resto do método (seção 5 em diante).
            $ticket->refresh();
        }
        $resposta = trim(preg_replace('/\[OBJETIVO_CUMPRIDO:\d+\]/', '', $resposta));

        // ── 4.6. Traduzir pro idioma do lead, se for o caso (item 11 do roteiro) ──
        // A resposta continua sendo gerada em português normalmente — só
        // traduz na hora de enviar. Falha de tradução nunca bloqueia o
        // envio: manda o texto original mesmo, em português, em vez de
        // não mandar nada.
        $respostaParaEnviar = $resposta;
        $idiomaEnviado       = 'pt';
        $respostaPtOriginal  = null;

        if ($ticket->idioma_lead && $ticket->idioma_lead !== 'pt') {
            $traduzida = app(\App\Services\TraducaoService::class)->traduzir($resposta, $ticket->idioma_lead);
            if ($traduzida) {
                $respostaParaEnviar = $traduzida;
                $idiomaEnviado       = $ticket->idioma_lead;
                $respostaPtOriginal  = $resposta;
            }
        }

        // ── 5. Enviar pelo canal certo (Uazapi ou Covercut, resolvido pelo ticket) ──
        $telefone = $ticket->contato?->telefone;
        $canal    = $ticket->canal;

        if ($telefone && $canal) {
            $enviado = $canal->servico()->enviarTexto($canal, $telefone, $respostaParaEnviar);
            if (! $enviado) {
                // Achado Importante 3 da revisão final: um bloqueio determinístico
                // (ex: janela expirada no Covercut) não pode gravar uma Mensagem "bot"
                // no histórico — o lead nunca recebeu, e o FollowupConversas avançaria
                // followup_estagio_enviado achando que a mensagem saiu. Sem persistir e
                // sem mover coluna aqui: melhor a IA tentar de novo no próximo gatilho.
                Log::warning('SdrResponder: envio não confirmado pelo canal, resposta não persistida', [
                    'ticket_id' => $ticket->id, 'canal_id' => $canal->id,
                ]);

                // Bloco 5 — conta falhas seguidas pra dar um teto de tentativas
                // (ver FollowupConversas, que decide quando parar e alertar).
                // Capado em 3: sem isso, chamadas sem teto (ex: Follow-up curto
                // 10min, que roda antes do ciclo de estágios avaliar o ticket)
                // empurravam o contador além de 3 antes do FollowupConversas
                // conseguir detectar a transição exata pra criar o alerta —
                // o alerta nunca disparava na prática (achado da revisão final).
                if ($ticket->tentativas_envio_falhas < 3) {
                    $ticket->increment('tentativas_envio_falhas');
                }

                return null;
            }

            // Bloco 5 — envio confirmado, zera o contador de falhas seguidas.
            if ($ticket->tentativas_envio_falhas > 0) {
                $ticket->update(['tentativas_envio_falhas' => 0]);
            }
        } else {
            Log::warning('SdrResponder: sem canal ou telefone, mensagem não enviada', [
                'ticket_id' => $ticket->id,
                'telefone'  => $telefone,
                'tem_canal' => (bool) $canal,
            ]);
        }

        // ── 6. Persistir resposta ────────────────────────────────────────────
        // `conteudo` é o que foi realmente enviado pelo WhatsApp (traduzido,
        // se for o caso) — `conteudo_pt` guarda o original em português pra
        // quem estiver lendo no Kanban (ver item 11 do roteiro).
        Mensagem::create([
            'ticket_id'   => $ticket->id,
            'tenant_id'   => $ticket->tenant_id,
            'remetente'   => 'bot',
            'tipo'        => 'texto',
            'conteudo'    => $respostaParaEnviar,
            'idioma'      => $idiomaEnviado,
            'conteudo_pt' => $respostaPtOriginal,
            'enviado_em'  => now(),
        ]);

        // ── 7. Rede de segurança ──────────────────────────────────────────────
        // O webhook já restaura a coluna anterior assim que um ticket encerrado
        // é reativado (ver UazapiWebhookController::processarMensagemLead), então
        // isso não deveria mais disparar — mantido como fallback caso o ticket
        // chegue aqui ainda em 'encerrado' por algum outro caminho.
        if (! $moveu && KanbanColuna::papelDe($tenantId, $ticket->coluna_kanban) === PapelColunaKanban::Encerramento) {
            $ticket->update(['status' => 'encerrado']);
            Log::info('SdrResponder: ticket ainda em encerrado sem token de movimento, fechado de volta', ['ticket_id' => $ticket->id]);
        }

        return $resposta;
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function contextoHistoricoCliente(TicketAtendimento $ticket): string
    {
        $anteriores = TicketAtendimento::withoutGlobalScopes()
            ->where('tenant_id', $ticket->tenant_id)
            ->where('contato_id', $ticket->contato_id)
            ->where('id', '!=', $ticket->id)
            ->orderByDesc('aberto_em')
            ->get(['id', 'status', 'tag_desfecho', 'aberto_em']);

        if ($anteriores->isEmpty()) {
            return '[HISTÓRICO DO CLIENTE: Lead novo — primeiro contato com a empresa]';
        }

        $fechados = $anteriores->whereIn('status', ['encerrado', 'fechado', 'concluido'])->count();

        if ($fechados > 0) {
            return "[HISTÓRICO DO CLIENTE: Cliente recorrente — já fez {$fechados} frete(s) conosco. Trate como cliente conhecido. Se o nome já constar no cadastro, não precise perguntar de novo.]";
        }

        $ultimo   = $anteriores->first();
        $diasAtras = $ultimo->aberto_em ? now()->diffInDays($ultimo->aberto_em) : null;
        $periodo   = $diasAtras !== null ? " há {$diasAtras} dia(s)" : '';

        return "[HISTÓRICO DO CLIENTE: Retorno de orçamento — este contato já conversou com a empresa{$periodo} mas não fechou serviço. Pode mencionar sutilmente que já conversaram antes: \"Vi aqui que a gente já teve contato antes...\"]";
    }

    private function tagsDoContato(TicketAtendimento $ticket): array
    {
        $origem = $ticket->contato?->origem ?? '';
        $tags   = [];

        if (str_contains($origem, '_')) {
            $tags[] = explode('_', $origem, 2)[1];
        } elseif ($origem) {
            $tags[] = $origem;
        }

        return $tags;
    }

    private function montarBlocoObjetivos(TicketAtendimento $ticket): ?string
    {
        $objetivos = KanbanColunaObjetivo::withoutGlobalScopes()
            ->where('tenant_id', $ticket->tenant_id)
            ->where('coluna_kanban', $ticket->coluna_kanban)
            ->where('ativo', true)
            ->orderBy('ordem')
            ->get();

        if ($objetivos->isEmpty()) {
            return null;
        }

        $cumpridos = $ticket->objetivos_cumpridos ?? [];

        $linhas = $objetivos->map(function ($objetivo) use ($cumpridos) {
            $feito = in_array($objetivo->id, $cumpridos, true);
            return ($feito ? '✅ ' : '❌ ') . "[id:{$objetivo->id}] " . $objetivo->texto . ($feito ? '' : ': pendente');
        });

        return "=== OBJETIVOS DESTA ETAPA (marque quando cumprir) ===\n"
            . $linhas->implode("\n")
            . "\n\nPra marcar um objetivo como cumprido, inclua no final da sua resposta um token "
            . "[OBJETIVO_CUMPRIDO:<id>] — pode incluir mais de um na mesma resposta, um por linha. "
            . "NUNCA mencione ou explique esses tokens ao lead."
            . "\n===";
    }

    private function montarHistorico(SdrPersona $persona, TicketAtendimento $ticket, bool $origemLigacao = false, ?string $gatilho = null, ?string $orientacaoHumana = null): array
    {
        $etapaInstrucao = match ($ticket->etapa_ia) {
            'etapa_1' => '[ETAPA: qualificação inicial do lead]',
            'etapa_2' => '[ETAPA: aprofundamento e negociação]',
            'handoff' => '[ETAPA: transição para atendente humano]',
            default   => '[ETAPA: qualificação inicial do lead]',
        };

        // Contexto do contato injetado no system prompt
        $nomeContato = $ticket->contato?->nome;
        $nomeConhecido = $nomeContato && $nomeContato !== $ticket->contato?->telefone;
        $contextoContato = $nomeConhecido
            ? "[CONTATO: nome conhecido = {$nomeContato}]"
            : '[CONTATO: nome ainda não identificado — pergunte naturalmente se der oportunidade]';

        // Detecta se é primeiro contato (sem mensagens do bot ainda)
        $jaRespondeu = $ticket->mensagens->contains('remetente', 'bot');
        $primeiroContato = $jaRespondeu ? '' : '[PRIMEIRO CONTATO: apresente-se de forma natural e dê boas-vindas]';

        // Contexto especial: lead que ligou e não foi atendido
        if ($origemLigacao) {
            $mensagemPersonalizada = $ticket->tenant?->secretaria_mensagem_inicial;
            $exemploMensagem = $mensagemPersonalizada
                ? "Use EXATAMENTE esta mensagem de abertura configurada pelo franqueado:\n\"{$mensagemPersonalizada}\""
                : "Exemplo de abertura natural: \"Oi! Vi que você ligou aqui pra gente e não consegui atender na hora. Aqui é o João — tô disponível agora no WhatsApp, pode falar! 😊\"";

            $contextoLigacao = "[CONTEXTO ESPECIAL: Este lead LIGOU para o número da empresa e não foi atendido.\nO sistema detectou a chamada perdida e iniciou contato automaticamente.\nInicie a conversa reconhecendo que viu a ligação perdida, seja natural e acolhedor.\n{$exemploMensagem}\nNÃO mencione bots, sistemas automáticos ou que foi detectado pelo aplicativo.]";
        } else {
            $contextoLigacao = '';
        }

        $iaContexto = '';
        if ($ticket->tenant?->ia_contexto) {
            $iaContexto .= "=== INFORMAÇÕES DO NEGÓCIO ===\n" . $ticket->tenant->ia_contexto . "\n===";
        }
        if ($ticket->tenant?->tabela_precos_texto) {
            $iaContexto .= ($iaContexto ? "\n\n" : '') . "=== TABELA DE PREÇOS ===\n" . $ticket->tenant->tabela_precos_texto . "\n===";
        }

        $kanban = \App\Models\Kanban::withoutGlobalScopes()
            ->where('tenant_id', $ticket->tenant_id)
            ->where('tipo', 'vendas')
            ->first();

        if ($kanban?->conhecimento_geral) {
            $iaContexto .= ($iaContexto ? "\n\n" : '') . "=== CONHECIMENTO GERAL DESTE KANBAN ===\n" . $kanban->conhecimento_geral . "\n===";
        }

        // Base de conhecimento & aprendizado contínuo configurado no Agente IA
        $agenteIa = \App\Models\User::where('tenant_id', $ticket->tenant_id)
            ->where('is_ia', true)
            ->where('ativo', true)
            ->first();

        if ($agenteIa?->base_conhecimento) {
            $iaContexto .= ($iaContexto ? "\n\n" : '') . "=== BASE DE CONHECIMENTO & DIRETRIZES DO AGENTE ({$agenteIa->nome}) ===\n" . $agenteIa->base_conhecimento . "\n===";
        }

        // Contexto específico da coluna atual (ex: em_atendimento)
        $colunaConfig = KanbanColunaConfig::withoutGlobalScopes()
            ->where('tenant_id', $ticket->tenant_id)
            ->where('coluna_kanban', $ticket->coluna_kanban)
            ->first();

        if ($colunaConfig?->ia_contexto) {
            $iaContexto .= ($iaContexto ? "\n\n" : '') . "=== INSTRUÇÕES DESTA ETAPA ===\n" . $colunaConfig->ia_contexto . "\n===";
        }

        // Tokens de movimento disponíveis em qualquer coluna
        $iaContexto .= "\n\n=== TOKENS DE MOVIMENTO (use em qualquer etapa) ===\n"
            . "Inclua EXATAMENTE UM dos tokens abaixo no final da sua resposta para mover o card para a coluna correspondente. "
            . "O sistema executa o movimento automaticamente. NUNCA mencione ou explique os tokens ao lead.\n\n"
            . "Tokens disponíveis:\n"
            . "• [LEAD_NOVO]            → Volta o card para a fila de novos leads.\n"
            . "• [EM_ATENDIMENTO]       → Move para Em Atendimento (lead respondeu e está em conversa ativa).\n"
            . "• [AGUARDANDO_ORCAMENTO] → Move para Aguardando Orçamento (dados coletados, pronto para proposta).\n"
            . "• [AGUARDANDO_LEAD]      → Move para Aguardando Lead (proposta enviada, aguardando retorno).\n"
            . "• [PAGAMENTO]            → Move para Pagamento (orçamento aprovado, aguardando sinal).\n"
            . "• [SERVICO_AGENDADO]     → Move para Serviço Agendado (sinal pago, serviço confirmado).\n"
            . "• [ENCERRADO]            → Encerra o atendimento (lead desistiu, não responde ou pediu para parar).\n\n"
            . "Use apenas quando tiver certeza do estado do lead. Se a conversa não mudou de estado, NÃO inclua nenhum token."
            . "\n===";

        // Explica o marcador "[Atendente humano respondeu]" que aparece no
        // histórico abaixo — sem isso o modelo não teria como saber que aquele
        // turno específico não foi ele mesmo quem escreveu.
        $iaContexto .= "\n\n=== SOBRE O HISTÓRICO DA CONVERSA ===\n"
            . "Mensagens marcadas com \"[Atendente humano respondeu]:\" foram escritas por um atendente "
            . "humano de verdade, não por você. Use-as pra entender como a conversa evoluiu e se alinhar "
            . "com o que já foi combinado ou informado — não repita nem contradiga o que o atendente já disse. "
            . "NUNCA inclua esse marcador nas suas próprias respostas."
            . "\n===";

        // Regra 6 — proibição de eco.
        $iaContexto .= "\n\n=== REGRA DE ESTILO ===\n"
            . "Nunca repita literalmente o que o lead acabou de escrever como se fosse sua "
            . "própria fala. Reformular com valor agregado (confirmar entendimento, avançar "
            . "a conversa) é permitido — cópia pura da mensagem do lead é proibida."
            . "\n===";

        // Regra 5 — não perguntar de novo o que já foi respondido.
        $iaContexto .= "\n\n=== NÃO REPITA PERGUNTAS ===\n"
            . "Antes de pedir qualquer informação ao lead, releia todo o histórico da conversa "
            . "(incluindo transcrições de áudio/imagem) — se já foi dito, use o que já foi "
            . "informado em vez de perguntar de novo. Os itens marcados ✅ no bloco de "
            . "objetivos abaixo (se houver) já foram confirmados — nunca pergunte de novo "
            . "sobre eles."
            . "\n===";

        // Regra de Ouro da Janela de Atendimento (Meta 24h)
        $iaContexto .= "\n\n=== REGRA DE OURO: MANTER O LEAD RESPONDENDO (RENOVAÇÃO DA JANELA 24H) ===\n"
            . "Para manter o atendimento ativo e renovar a janela de atendimento do WhatsApp continuamente, "
            . "toda resposta sua deve OBRIGATORIAMENTE terminar com uma pergunta curta, clara e fácil de responder "
            . "(ex: opções de horário, confirmação de detalhe, preferência do cliente). "
            . "NUNCA termine uma mensagem com declaração passiva que encerre a conversa (como 'estou à disposição' ou 'qualquer dúvida me avise'). "
            . "Sempre convide o cliente a interagir para que ele continue respondendo e a conversa progrida."
            . "\n===";

        // Regra 7 — autovalidação antes de responder (1 chamada só, sem chamada
        // dupla — decisão fechada). Regra 2 é o efeito prático desta validação:
        // é este bloco que ensina o modelo a emitir [DUVIDA:...] quando não tem
        // certeza — a detecção do token acontece em responder(), não aqui.
        $iaContexto .= "\n\n=== VALIDAÇÃO ANTES DE RESPONDER ===\n"
            . "Antes de finalizar sua resposta, confira: (1) ela é relevante pro que o lead "
            . "perguntou agora? (2) está dentro do escopo deste atendimento? (3) não "
            . "contradiz nada dito antes nesta conversa? Se qualquer resposta for não, ou se "
            . "você não tiver certeza ou informação suficiente pra responder com segurança, "
            . "NÃO responda normalmente — em vez disso, responda SOMENTE com o token "
            . "[DUVIDA: <resuma em uma frase o que você não sabe responder>]. NUNCA invente "
            . "informação que não está no seu contexto."
            . "\n===";

        $contextoHistorico = $this->contextoHistoricoCliente($ticket);
        $checklistState    = $this->montarBlocoObjetivos($ticket);

        // Gatilho de follow-up injetado no contexto.
        //
        // Achado real 2026-08-20 (Leonardo): o follow-up de silêncio estava
        // perguntando algo novo/aleatório do checklist (ex.: "algum item fica
        // na origem?") em vez de lembrar especificamente do que já foi pedido
        // e não foi respondido (ex.: endereço) — confuso pro lead, parece que
        // o atendente não prestou atenção. As 3 instruções abaixo agora
        // apontam explicitamente pro bloco de checklist (OBJETIVOS DESTA
        // ETAPA, ver montarBlocoObjetivos()) como fonte da verdade de o que
        // está pendente, em vez de deixar o modelo escolher livremente.
        $lembreteChecklist = "Olhe o bloco OBJETIVOS DESTA ETAPA (se houver) e lembre o lead ESPECIFICAMENTE do item ❌ mais antigo/importante que já foi pedido e não foi respondido — nunca pule pra outro item do checklist que ainda não foi nem perguntado. Se não houver checklist configurado, lembre do que a ÚLTIMA pergunta sua no histórico pedia. Não repita a mensagem anterior palavra por palavra — varie a frase.";

        $contextoGatilho = match ($gatilho) {
            'vacuo_10m' => "[GATILHO: VACUO_10M — O cliente parou de responder há ~10 minutos. Mande uma mensagem curta e natural para reaquecer. Ex: 'Opa, conseguiu ver a questão lá?' ou 'Tô por aqui, pode falar!']",
            'estagio_1' => "[GATILHO: ESTÁGIO 1 DE SILÊNCIO CONFIRMADO — O tempo real de silêncio do lead (contado pelo sistema, não estimado por você) já cruzou o limite do Estágio 1 configurado para esta coluna. Tom: toque suave, empático. {$lembreteChecklist} NÃO use [ENCERRADO] neste estágio.]",
            'estagio_2' => "[GATILHO: ESTÁGIO 2 DE SILÊNCIO CONFIRMADO — O tempo real de silêncio do lead já cruzou o limite do Estágio 2 configurado para esta coluna. Tom: urgência sutil, sem pressionar — pode mencionar que a agenda vai ficando concorrida. {$lembreteChecklist} NÃO use [ENCERRADO] neste estágio.]",
            'estagio_3' => "[GATILHO: ESTÁGIO 3 DE SILÊNCIO CONFIRMADO — O tempo real de silêncio do lead já cruzou o limite do Estágio 3 configurado para esta coluna. Informe que está encerrando por falta de retorno, deixe as portas abertas para o futuro (pode mencionar rapidamente o que ainda faltava, sem cobrar), e inclua [ENCERRADO] ao final. Se o histórico mostrar que o lead já retomou contato recentemente, NÃO encerre — responda normalmente ao que ele disse.]",
            default     => null,
        };

        $contextoOrientacao = $orientacaoHumana
            ? "[ORIENTAÇÃO DO ATENDENTE — use isso pra responder ao lead agora, "
              . "NUNCA mencione que recebeu orientação interna]: {$orientacaoHumana}"
            : null;

        $messages = [[
            'role'    => 'system',
            'content' => implode("\n\n", array_filter([
                $persona->system_prompt,
                $iaContexto,
                $etapaInstrucao,
                $contextoContato,
                $contextoHistorico,
                $checklistState,
                $primeiroContato,
                $contextoLigacao,
                $contextoGatilho,
                $contextoOrientacao,
            ])),
        ]];

        // Últimas 30 mensagens do histórico — exclui ecos de transcrição de
        // áudio (EcoTranscricaoService): são só pro humano ler na conversa do
        // WhatsApp, não algo que o próprio agente "disse". Sem isso, o LLM via
        // no histórico como se ele mesmo tivesse repetido de volta a
        // transcrição do lead, duplicando informação e confundindo o contexto.
        $historico = $ticket->mensagens
            ->reject(fn ($m) => str_starts_with($m->conteudo ?? '', \App\Services\EcoTranscricaoService::PREFIXO))
            ->values()
            ->reverse()->take(30)->reverse();

        foreach ($historico as $mensagem) {
            // 'contato' e 'lead' → 'user' / 'bot' e 'humano' → 'assistant'.
            // A API de chat não tem um papel próprio pra "colega humano" — só
            // system/user/assistant — então a forma de o agente saber que NÃO foi
            // ele quem disse aquilo é marcar no próprio texto. Sem isso (achado
            // 2026-08-05, pedido do Leonardo), o agente tratava resposta do
            // atendente humano como se tivesse sido ele mesmo que respondeu,
            // sem aprender/se orientar pelo que um humano de verdade decidiu
            // dizer naquele momento da conversa.
            $role    = in_array($mensagem->remetente, ['contato', 'lead']) ? 'user' : 'assistant';
            $conteudo = $mensagem->conteudo ?? '';
            if ($mensagem->remetente === 'humano') {
                $conteudo = "[Atendente humano respondeu]: {$conteudo}";
            }
            $messages[] = [
                'role'    => $role,
                'content' => $conteudo,
            ];
        }

        return $messages;
    }
}
