<?php

namespace App\Services;

use App\Models\KanbanColunaConfig;
use App\Models\Mensagem;
use App\Models\SdrPersona;
use App\Models\TicketAtendimento;
use Illuminate\Support\Facades\Log;

class SdrResponderService
{
    public function __construct(
        private LeadRouterService  $router,
        private OpenRouterService  $openRouter,
        private HumanizacaoService $humanizacao,
    ) {}

    /**
     * Seleciona persona, gera resposta via OpenRouter, envia com humanização, persiste.
     * Retorna o texto da resposta ou null se falhar.
     */
    public function responder(TicketAtendimento $ticket, bool $origemLigacao = false, ?string $gatilho = null): ?string
    {
        $ticket->loadMissing(['contato', 'persona', 'mensagens', 'tenant']);

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
        $messages = $this->montarHistorico($persona, $ticket, $origemLigacao, $gatilho);

        // ── 3. Chamar o OpenRouter ───────────────────────────────────────────
        $tier    = $ticket->etapa_ia === 'etapa_2' ? 'complexo' : 'simples';
        $resposta = $this->openRouter->chat($messages, $tier);

        if (! $resposta) {
            Log::error('SdrResponder: OpenRouter sem resposta', ['ticket_id' => $ticket->id]);
            return null;
        }

        // ── 4. Detectar token de movimento de coluna e aplicar ──────────────
        // Token = nome da coluna em maiúsculas entre colchetes.
        // A IA pode mover o lead para qualquer coluna a partir de qualquer etapa.
        $tokenColunas = [
            '[LEAD_NOVO]'            => ['coluna' => 'lead_novo',            'etapa' => 'etapa_1'],
            '[EM_ATENDIMENTO]'       => ['coluna' => 'em_atendimento',       'etapa' => 'etapa_1'],
            '[AGUARDANDO_ORCAMENTO]' => ['coluna' => 'aguardando_orcamento', 'etapa' => 'handoff'],
            '[AGUARDANDO_LEAD]'      => ['coluna' => 'aguardando_lead',      'etapa' => 'etapa_1'],
            '[PAGAMENTO]'            => ['coluna' => 'pagamento',            'etapa' => 'etapa_1'],
            '[SERVICO_AGENDADO]'     => ['coluna' => 'servico_agendado',     'etapa' => 'handoff'],
            '[ENCERRADO]'            => ['coluna' => 'encerrado',            'etapa' => 'handoff'],
        ];

        $moveu = false;
        foreach ($tokenColunas as $token => $cfg) {
            if (str_contains($resposta, $token)) {
                $updates = ['coluna_kanban' => $cfg['coluna'], 'etapa_ia' => $cfg['etapa']];
                if ($cfg['coluna'] === 'encerrado') {
                    $updates['status'] = 'encerrado';
                }
                $ticket->update($updates);
                Log::info("SdrResponder: → {$cfg['coluna']} via token {$token}", ['ticket_id' => $ticket->id]);
                $moveu = true;
                break;
            }
        }
        $resposta = trim(str_replace(array_keys($tokenColunas), '', $resposta));

        // ── 5. Enviar via WhatsApp com humanização ───────────────────────────
        $tenant   = $ticket->tenant;
        $telefone = $ticket->contato?->telefone;

        if ($telefone && $tenant?->uazapi_instance_token) {
            $this->humanizacao->processar(
                $tenant->uazapi_instance_token,
                $telefone,
                $resposta
            );
        } else {
            Log::warning('SdrResponder: sem token ou telefone, mensagem não enviada', [
                'ticket_id' => $ticket->id,
                'telefone'  => $telefone,
                'tem_token' => (bool) $tenant?->uazapi_instance_token,
            ]);
        }

        // ── 6. Persistir resposta ────────────────────────────────────────────
        Mensagem::create([
            'ticket_id'  => $ticket->id,
            'tenant_id'  => $ticket->tenant_id,
            'remetente'  => 'bot',
            'tipo'       => 'texto',
            'conteudo'   => $resposta,
            'enviado_em' => now(),
        ]);

        // ── 7. Se o Guardião respondeu sem mover de coluna, fechar ticket de volta ──
        // Ticket encerrado foi reativado temporariamente no webhook para o Guardião avaliar.
        // Se nenhum token de movimento foi emitido, fecha o ticket de volta.
        if (! $moveu && $ticket->coluna_kanban === 'encerrado') {
            $ticket->update(['status' => 'encerrado']);
            Log::info('SdrResponder: Guardião sem token de movimento, ticket fechado de volta', ['ticket_id' => $ticket->id]);
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

    private function derivarChecklist(TicketAtendimento $ticket): string
    {
        $mensagensLead = $ticket->mensagens
            ->where('remetente', 'lead')
            ->pluck('conteudo')
            ->filter()
            ->implode(' ');

        $ok  = fn(string $s) => "✅ {$s}";
        $nok = fn(string $s) => "❌ {$s}: pendente";

        $items = [];

        // Endereços (salvos no ticket após handoff ou por n8n)
        $items[] = $ticket->endereco_saida
            ? $ok("Endereço de embarque: {$ticket->endereco_saida}")
            : (preg_match('/\b(rua|av\.|avenida|estrada|travessa|praça)\b/i', $mensagensLead)
                ? "⚠️ Endereço de embarque: mencionado parcialmente — confirmar"
                : $nok("Endereço de embarque"));

        $items[] = $ticket->endereco_destino
            ? $ok("Endereço de destino: {$ticket->endereco_destino}")
            : $nok("Endereço de destino");

        // Lista de itens
        $items[] = $ticket->lista_itens
            ? $ok("Lista de itens: coletada")
            : (preg_match('/\b(geladeira|sofá|cama|mesa|armário|guarda.roupa|fogão|máquina|tv|freezer|buffet)\b/i', $mensagensLead)
                ? "⚠️ Lista de itens: parcialmente mencionada — auditar com fotos"
                : $nok("Lista de itens"));

        // Data
        $temData = preg_match('/\b\d{1,2}[\/\-]\d{1,2}|\b(segunda|terça|quarta|quinta|sexta|sábado|domingo|amanhã|hoje|semana que vem)\b/i', $mensagensLead);
        $items[] = $temData ? "⚠️ Data: mencionada — confirmar dia e horário exatos" : $nok("Data e horário");

        // Escadas (detectar menção)
        $temEscada = preg_match('/\b(escada|lance|andar|elevador|sem elevador|com elevador)\b/i', $mensagensLead);
        $items[] = $temEscada ? "⚠️ Escadas: mencionado — confirmar lances reais" : $nok("Escadas (lances reais)");

        // Serviços extras
        $temExtra = preg_match('/\b(desmont|mont|embala|plástico|papelão|caixa)\b/i', $mensagensLead);
        $items[] = $temExtra ? "⚠️ Serviços extras: mencionados — detalhar" : $nok("Desmontagem / Embalagem");

        return "[STATUS_CHECKLIST]\n" . implode("\n", $items);
    }

    private function montarHistorico(SdrPersona $persona, TicketAtendimento $ticket, bool $origemLigacao = false, ?string $gatilho = null): array
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

        $contextoHistorico = $this->contextoHistoricoCliente($ticket);
        $checklistState    = $this->derivarChecklist($ticket);

        // Gatilho de follow-up injetado no contexto
        $contextoGatilho = match ($gatilho) {
            'vacuo_10m' => "[GATILHO: VACUO_10M — O cliente parou de responder há ~10 minutos. Mande uma mensagem curta e natural para reaquecer. Ex: 'Opa, conseguiu ver a questão lá?' ou 'Tô por aqui, pode falar!']",
            'vacuo_12h' => "[GATILHO: VACUO_12H — Passaram mais de 12 horas sem resposta. Em horário comercial, reengaje de forma leve: 'E aí, vamos seguir com o agendamento?' Se o cliente disser que já fechou com outro ou desistiu, apenas agradeça e encerre.]",
            default     => null,
        };

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
            ])),
        ]];

        // Últimas 30 mensagens do histórico
        $historico = $ticket->mensagens->reverse()->take(30)->reverse();

        foreach ($historico as $mensagem) {
            // 'contato' e 'lead' → 'user' / 'bot' e 'agente' → 'assistant'
            $role       = in_array($mensagem->remetente, ['contato', 'lead']) ? 'user' : 'assistant';
            $messages[] = [
                'role'    => $role,
                'content' => $mensagem->conteudo ?? '',
            ];
        }

        return $messages;
    }
}
