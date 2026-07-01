<?php

namespace App\Services;

use App\Models\Mensagem;
use App\Models\SdrPersona;
use App\Models\TicketAtendimento;
use Illuminate\Support\Facades\Log;

class SdrResponderService
{
    public function __construct(
        private LeadRouterService $router,
        private OpenRouterService $openRouter,
        private UazapiService     $uazapi,
    ) {}

    /**
     * Seleciona persona, gera resposta via OpenRouter, envia via Uazapi, persiste como Mensagem.
     * Retorna o texto da resposta ou null se falhar.
     */
    public function responder(TicketAtendimento $ticket): ?string
    {
        $ticket->load(['contato', 'persona', 'mensagens']);

        // ── 1. Selecionar/confirmar persona ─────────────────────────────────
        $persona = $ticket->persona;
        if (! $persona) {
            $tags    = $this->tagsDoContato($ticket);
            $persona = $this->router->rotear($ticket->tenant_id, $tags);

            if (! $persona) {
                Log::warning('SdrResponder: nenhuma persona encontrada para o ticket', ['ticket_id' => $ticket->id]);
                return null;
            }

            $ticket->update(['sdr_persona_id' => $persona->id]);
        }

        // ── 2. Montar histórico para o OpenRouter ────────────────────────────
        $messages = $this->montarHistorico($persona, $ticket);

        // ── 3. Chamar o OpenRouter ────────────────────────────────────────────
        $tier    = $ticket->etapa_ia === 'etapa_2' ? 'complexo' : 'simples';
        $resposta = $this->openRouter->chat($messages, $tier);

        if (! $resposta) {
            Log::error('SdrResponder: OpenRouter não retornou resposta', ['ticket_id' => $ticket->id]);
            return null;
        }

        // ── 4. Enviar pelo WhatsApp ───────────────────────────────────────────
        $telefone = $ticket->contato?->telefone;
        if ($telefone) {
            $this->uazapi->enviarMensagem($telefone, $resposta);
        }

        // ── 5. Persistir resposta ─────────────────────────────────────────────
        Mensagem::create([
            'ticket_id' => $ticket->id,
            'tenant_id' => $ticket->tenant_id,
            'remetente' => 'bot',
            'tipo'      => 'texto',
            'conteudo'  => $resposta,
        ]);

        return $resposta;
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function tagsDoContato(TicketAtendimento $ticket): array
    {
        $origem = $ticket->contato?->origem ?? '';

        // Transforma 'minerador_instagram' → 'instagram', 'whatsapp' → 'whatsapp', etc.
        $tags = [];
        if (str_contains($origem, '_')) {
            $tags[] = explode('_', $origem, 2)[1];
        } elseif ($origem) {
            $tags[] = $origem;
        }

        return $tags;
    }

    private function montarHistorico(SdrPersona $persona, TicketAtendimento $ticket): array
    {
        // Instrução de etapa injetada ao system prompt para guiar o modelo
        $etapaInstrucao = match ($ticket->etapa_ia) {
            'etapa_1' => '[ETAPA ATUAL: etapa_1 — qualificação inicial do lead]',
            'etapa_2' => '[ETAPA ATUAL: etapa_2 — aprofundamento e negociação]',
            'handoff' => '[ETAPA ATUAL: handoff — transição para atendente humano]',
            default   => '',
        };

        $messages = [[
            'role'    => 'system',
            'content' => $persona->system_prompt . "\n\n" . $etapaInstrucao,
        ]];

        // Últimas 30 mensagens para não estourar contexto
        $historico = $ticket->mensagens->takeLast(30);

        foreach ($historico as $mensagem) {
            $role = $mensagem->remetente === 'lead' ? 'user' : 'assistant';
            $messages[] = [
                'role'    => $role,
                'content' => $mensagem->conteudo ?? '',
            ];
        }

        return $messages;
    }
}
