<?php

namespace App\Services;

use App\Enums\PapelColunaKanban;
use App\Models\KanbanColuna;
use App\Models\TicketAtendimento;
use Illuminate\Support\Facades\Log;

/**
 * Decide se um ticket encerrado deve ser reativado quando o lead volta a
 * escrever, em vez de abrir um ticket novo — extraído do
 * UazapiWebhookController (2026-08-12) pra ser reusado pelo CovercutWebhookController,
 * que não tinha essa lógica (achado real: lead do canal Oficial que voltava a
 * falar após o atendimento encerrado sempre ganhava um ticket novo, nunca
 * reabria o anterior — regra fundamental de paridade entre canais, ver CLAUDE.md).
 */
class TicketReaberturaService
{
    public function buscarTicketEncerrado(int $tenantId, int $contatoId): ?TicketAtendimento
    {
        return TicketAtendimento::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('contato_id', $contatoId)
            ->whereIn('coluna_kanban', KanbanColuna::chavesComPapel($tenantId, PapelColunaKanban::Encerramento))
            ->latest()
            ->first();
    }

    /**
     * Reativa o ticket se a mensagem justificar (ver deveReabrir()). Retorna
     * true se reativou, false se manteve encerrado — quem chama decide o que
     * fazer com o ticket em cada caso (ex: atualizar campos extras do canal).
     */
    public function reabrirSeNecessario(TicketAtendimento $ticketEncerrado, int $canalId, ?string $mensagem): bool
    {
        if (! $this->deveReabrir($mensagem)) {
            Log::info("TicketReaberturaService: ticket #{$ticketEncerrado->id} recebeu mensagem mas continua encerrado (parece despedida/agradecimento)");
            return false;
        }

        // Volta pra coluna em que estava antes de encerrar — independente de
        // quem encerrou (humano, silêncio automático ou a própria IA).
        $colunaRestaurada = $ticketEncerrado->coluna_antes_encerrar ?: 'em_atendimento';

        $ticketEncerrado->update([
            'status'                => 'aberto',
            'agente_responsavel'    => 'bot',
            'coluna_kanban'         => $colunaRestaurada,
            'coluna_antes_encerrar' => null,
            // Reflete qual número reativou o atendimento — o ticket continua
            // único por lead, mas o canal aponta pro mais recente.
            'whatsapp_canal_id'     => $canalId,
        ]);

        Log::info("TicketReaberturaService: ticket #{$ticketEncerrado->id} reativado, voltou pra coluna '{$colunaRestaurada}'");

        return true;
    }

    /**
     * Decide se uma mensagem nova pra um ticket encerrado deve reabrir o
     * atendimento. Despedidas/agradecimentos ("obrigado", "já consegui",
     * "tchau") não devem reabrir; qualquer informação útil de verdade deve.
     * Em caso de dúvida ou falha da IA, opta por reabrir — perder uma venda
     * por não reabrir é pior do que reabrir um agradecimento por engano.
     */
    private function deveReabrir(?string $mensagem): bool
    {
        if (! $mensagem || trim($mensagem) === '') {
            return true;
        }

        $resposta = app(OpenRouterService::class)->chat([
            ['role' => 'system', 'content' =>
                'Você analisa mensagens de um cliente cujo atendimento de frete/mudança JÁ FOI ENCERRADO. '
                . 'Decida se essa nova mensagem precisa REABRIR o atendimento (nova dúvida, informação útil '
                . 'pro serviço, reclamação, pedido de continuidade) ou se é só uma despedida/agradecimento que '
                . 'NÃO precisa reabrir (ex: "obrigado", "já consegui", "tchau", "ok", emoji de agradecimento). '
                . 'Responda com exatamente uma palavra: REABRIR ou MANTER.'],
            ['role' => 'user', 'content' => $mensagem],
        ], 'simples', 10, 'reabertura_ticket_encerrado');

        if (! $resposta) {
            return true;
        }

        return ! str_contains(mb_strtoupper($resposta), 'MANTER');
    }
}
