<?php

namespace App\Jobs;

use App\Models\KanbanColunaConfig;
use App\Models\Mensagem;
use App\Models\TicketAtendimento;
use App\Services\SdrResponderService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class SdrResponderJob implements ShouldQueue
{
    use Queueable;

    public int $tries   = 2;
    public int $timeout = 90; // LLM + humanização podem demorar

    const DEBOUNCE_SEGUNDOS = 45; // padrão quando não há config no banco

    public function __construct(
        private int    $ticketId,
        private string $ultimaMensagem  = '',
        private bool   $origemLigacao   = false,
        private bool   $imediato        = false,
        private int    $debounceSegundos = self::DEBOUNCE_SEGUNDOS,
    ) {}

    public function handle(SdrResponderService $service): void
    {
        $ticket = TicketAtendimento::withoutGlobalScopes()
            ->with(['contato', 'persona', 'mensagens', 'tenant'])
            ->find($this->ticketId);

        if (! $ticket) {
            Log::warning("SdrResponderJob: ticket #{$this->ticketId} não encontrado");
            return;
        }

        // Debounce: se o lead enviou outra mensagem dentro do janela, este job é obsoleto
        if (! $this->imediato) {
            $ultimaMensagemEm = Mensagem::withoutGlobalScopes()
                ->where('ticket_id', $this->ticketId)
                ->where('remetente', 'lead')
                ->orderByDesc('enviado_em')
                ->value('enviado_em');

            if ($ultimaMensagemEm && now()->diffInSeconds($ultimaMensagemEm) < $this->debounceSegundos) {
                Log::info("SdrResponderJob: debounce — lead digitando, job cancelado. ticket #{$this->ticketId}");
                return;
            }
        }

        // Só responde se o bot ainda é responsável
        if ($ticket->agente_responsavel !== 'bot') {
            Log::info("SdrResponderJob: ticket #{$this->ticketId} já foi assumido por humano, ignorando");
            return;
        }

        // Verifica ia_ativo na config da coluna atual do ticket
        $colunaConfig = KanbanColunaConfig::withoutGlobalScopes()
            ->where('tenant_id', $ticket->tenant_id)
            ->where('coluna_kanban', $ticket->coluna_kanban)
            ->first();

        if (! $colunaConfig?->ia_ativo) {
            Log::info("SdrResponderJob: IA não ativa para coluna {$ticket->coluna_kanban} do ticket #{$ticket->id}");
            return;
        }

        $service->responder($ticket, $this->origemLigacao);
    }
}
