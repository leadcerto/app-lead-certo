<?php

namespace App\Jobs;

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

    public function __construct(
        private int    $ticketId,
        private string $ultimaMensagem = '',
        private bool   $origemLigacao  = false,
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

        // Só responde se o bot ainda é responsável
        if ($ticket->agente_responsavel !== 'bot') {
            Log::info("SdrResponderJob: ticket #{$this->ticketId} já foi assumido por humano, ignorando");
            return;
        }

        $service->responder($ticket, $this->origemLigacao);
    }
}
