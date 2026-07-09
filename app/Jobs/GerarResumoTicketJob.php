<?php

namespace App\Jobs;

use App\Models\TicketAtendimento;
use App\Services\OpenRouterService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class GerarResumoTicketJob implements ShouldQueue
{
    use Queueable;

    public int $tries   = 2;
    public int $timeout = 60;

    public function __construct(private int $ticketId) {}

    public function handle(OpenRouterService $openRouter): void
    {
        $ticket = TicketAtendimento::withoutGlobalScopes()
            ->with('mensagens')
            ->find($this->ticketId);

        if (! $ticket) {
            return;
        }

        $mensagens = $ticket->mensagens
            ->filter(fn ($m) => $m->conteudo && $m->conteudo !== '')
            ->map(fn ($m) => match ($m->remetente) {
                'lead'   => 'CLIENTE: ' . $m->conteudo,
                'bot'    => 'BOT: '     . $m->conteudo,
                'humano' => 'ATENDENTE: ' . $m->conteudo,
                default  => strtoupper($m->remetente) . ': ' . $m->conteudo,
            })
            ->implode("\n");

        if (empty(trim($mensagens))) {
            return;
        }

        $messages = [
            [
                'role'    => 'system',
                'content' => 'Você resume conversas de vendas no WhatsApp em 2-4 linhas, em português. Destaque: interesse do cliente, objeções levantadas, desfecho (vendeu/não vendeu/pendente) e próximo passo se houver. Sem introdução, direto ao ponto.',
            ],
            [
                'role'    => 'user',
                'content' => "Resuma esta conversa:\n\n{$mensagens}",
            ],
        ];

        $resumo = $openRouter->chat($messages, 'simples', 300, 'resumo_ticket', $ticket->tenant_id);

        if ($resumo) {
            $ticket->update(['resumo_ia' => trim($resumo)]);
        } else {
            Log::warning('GerarResumoTicketJob: sem resposta da IA', ['ticket_id' => $this->ticketId]);
        }
    }
}
