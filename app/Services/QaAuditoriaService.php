<?php

namespace App\Services;

use App\Models\QaAuditoria;
use App\Models\TicketAtendimento;
use Illuminate\Support\Facades\Log;

class QaAuditoriaService
{
    private const THRESHOLD_REVISAO = 85;

    public function __construct(private OpenRouterService $openRouter) {}

    /**
     * Avalia a qualidade do atendimento de um ticket encerrado.
     * Escreve uma linha em qa_auditorias com confidence_score e, se < threshold,
     * marca requer_revisao_humana = true para aparecer no Painel do Auditor.
     */
    public function avaliar(TicketAtendimento $ticket): void
    {
        $ticket->load(['persona', 'mensagens']);

        $persona = $ticket->persona;
        if (! $persona) {
            return; // ticket sem persona SDR não tem SDR para auditar
        }

        $conversa = $this->formatarConversa($ticket);
        if (empty($conversa)) {
            return;
        }

        $prompt = $this->montarPromptJuiz($persona->system_prompt, $conversa);
        $raw    = $this->openRouter->chat($prompt, 'simples', 600);

        if (! $raw) {
            Log::warning('QaAuditoriaService: sem resposta do juiz', ['ticket_id' => $ticket->id]);
            return;
        }

        $avaliacao = $this->parsearAvaliacao($raw);

        $score = $avaliacao['confidence_score'] ?? 0;

        QaAuditoria::create([
            'ticket_id'             => $ticket->id,
            'sdr_persona_id'        => $persona->id,
            'confidence_score'      => $score,
            'sugestoes_melhoria'    => $avaliacao['sugestoes'] ?? null,
            'requer_revisao_humana' => $score < self::THRESHOLD_REVISAO,
            'status'                => 'aguardando',
            'payload_avaliacao'     => $avaliacao,
            'criado_em'             => now(),
        ]);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function formatarConversa(TicketAtendimento $ticket): string
    {
        return $ticket->mensagens
            ->map(fn ($m) => '[' . strtoupper($m->remetente) . ']: ' . $m->conteudo)
            ->implode("\n");
    }

    private function montarPromptJuiz(string $systemPromptPersona, string $conversa): array
    {
        return [
            [
                'role'    => 'system',
                'content' => 'Você é um auditor de qualidade de atendimento ao cliente. Avalie a conversa abaixo e responda APENAS em JSON válido, sem markdown, com esta estrutura:
{
  "confidence_score": <0 a 100>,
  "sugestoes": "<frase curta com melhorias ou null>",
  "pontos_positivos": "<frase curta ou null>",
  "pontos_negativos": "<frase curta ou null>"
}

Critérios de pontuação:
- Aderência ao persona e tom definido (weight 30%)
- Qualidade da qualificação do lead (weight 30%)
- Clareza e brevidade das respostas (weight 20%)
- Transição correta para humano quando necessário (weight 20%)

PERSONA DO SDR AVALIADO:
' . $systemPromptPersona,
            ],
            [
                'role'    => 'user',
                'content' => "Avalie esta conversa:\n\n" . $conversa,
            ],
        ];
    }

    private function parsearAvaliacao(string $raw): array
    {
        // Remove possível markdown code block caso o modelo desobedeça
        $json = preg_replace('/^```(?:json)?\s*/m', '', $raw);
        $json = preg_replace('/\s*```$/m', '', $json);

        $decoded = json_decode(trim($json), true);

        if (json_last_error() !== JSON_ERROR_NONE || ! isset($decoded['confidence_score'])) {
            Log::warning('QaAuditoria: JSON inválido retornado pelo juiz', ['raw' => $raw]);
            return ['confidence_score' => 0, 'sugestoes' => 'Erro ao parsear resposta do juiz.'];
        }

        return $decoded;
    }
}
