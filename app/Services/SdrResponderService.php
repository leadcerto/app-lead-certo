<?php

namespace App\Services;

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
    public function responder(TicketAtendimento $ticket): ?string
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
        $messages = $this->montarHistorico($persona, $ticket);

        // ── 3. Chamar o OpenRouter ───────────────────────────────────────────
        $tier    = $ticket->etapa_ia === 'etapa_2' ? 'complexo' : 'simples';
        $resposta = $this->openRouter->chat($messages, $tier);

        if (! $resposta) {
            Log::error('SdrResponder: OpenRouter sem resposta', ['ticket_id' => $ticket->id]);
            return null;
        }

        // ── 4. Enviar via WhatsApp com humanização ───────────────────────────
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

        // ── 5. Persistir resposta ────────────────────────────────────────────
        Mensagem::create([
            'ticket_id'  => $ticket->id,
            'tenant_id'  => $ticket->tenant_id,
            'remetente'  => 'bot',
            'tipo'       => 'texto',
            'conteudo'   => $resposta,
            'enviado_em' => now(),
        ]);

        return $resposta;
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

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

    private function montarHistorico(SdrPersona $persona, TicketAtendimento $ticket): array
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

        $messages = [[
            'role'    => 'system',
            'content' => implode("\n\n", array_filter([
                $persona->system_prompt,
                $etapaInstrucao,
                $contextoContato,
                $primeiroContato,
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
