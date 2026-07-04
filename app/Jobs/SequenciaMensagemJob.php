<?php

namespace App\Jobs;

use App\Models\Mensagem;
use App\Models\TicketAtendimento;
use App\Services\HumanizacaoService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class SequenciaMensagemJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public int    $ticketId,
        public string $conteudo,
    ) {}

    public function handle(HumanizacaoService $humanizacao): void
    {
        $ticket = TicketAtendimento::with(['contato', 'tenant'])->find($this->ticketId);

        if (! $ticket || $ticket->coluna_kanban === 'encerrado') {
            return;
        }

        // Não envia se o cliente já respondeu
        $clienteRespondeu = Mensagem::where('ticket_id', $this->ticketId)
            ->whereIn('remetente', ['contato', 'lead'])
            ->exists();

        if ($clienteRespondeu) {
            return;
        }

        $telefone = $ticket->contato?->telefone;
        $tenant   = $ticket->tenant;

        if (! $telefone || ! $tenant?->uazapi_instance_token) {
            Log::warning('SequenciaMensagemJob: sem telefone ou token', ['ticket_id' => $this->ticketId]);
            return;
        }

        // Substitui variável {nome}
        $nome   = $ticket->contato?->nome;
        $temNome = $nome && $nome !== $telefone;
        $texto  = $temNome
            ? str_replace('{nome}', $nome, $this->conteudo)
            : preg_replace('/\{nome\},?\s*/u', '', $this->conteudo);

        $humanizacao->processar($tenant->uazapi_instance_token, $telefone, $texto);

        Mensagem::create([
            'ticket_id'  => $ticket->id,
            'tenant_id'  => $ticket->tenant_id,
            'remetente'  => 'bot',
            'tipo'       => 'texto',
            'conteudo'   => $texto,
            'enviado_em' => now(),
        ]);
    }
}
