<?php

namespace App\Jobs;

use App\Models\TicketAtendimento;
use App\Services\QaAuditoriaService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ConversationQAJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;
    public int $timeout = 60;

    public function __construct(private int $ticketId) {}

    public function handle(QaAuditoriaService $qa): void
    {
        $ticket = TicketAtendimento::with(['persona', 'mensagens'])->find($this->ticketId);

        if (! $ticket) {
            return;
        }

        $qa->avaliar($ticket);
    }
}
