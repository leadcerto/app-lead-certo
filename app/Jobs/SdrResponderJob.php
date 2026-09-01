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
        private int     $ticketId,
        private string  $ultimaMensagem  = '',
        private bool    $origemLigacao   = false,
        private bool    $imediato        = false,
        private int     $debounceSegundos = self::DEBOUNCE_SEGUNDOS,
        private ?string $orientacaoHumana = null,
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

        if ($ticket->status === 'encerrado' || $ticket->coluna_kanban === 'encerrado' || \App\Models\KanbanColuna::papelDe($ticket->tenant_id, $ticket->coluna_kanban) === \App\Enums\PapelColunaKanban::Encerramento) {
            Log::info("SdrResponderJob: ticket #{$this->ticketId} está encerrado, resposta cancelada");
            return;
        }

        // Só responde se o bot ainda é responsável
        if ($ticket->agente_responsavel !== 'bot') {
            Log::info("SdrResponderJob: ticket #{$this->ticketId} já foi assumido por humano, ignorando");
            return;
        }

        // Regra 9: enquanto o ticket aguarda orientação humana sobre uma
        // dúvida, o agente não responde normalmente ao lead — manda a
        // mensagem de espera uma única vez (se ainda não mandou) e para por
        // aqui. Não se aplica quando $orientacaoHumana está preenchido: isso
        // só acontece no redisparo da Task 5, que já limpa
        // aguardando_orientacao_em ANTES de despachar este job.
        if ($ticket->aguardando_orientacao_em && $this->orientacaoHumana === null) {
            if (! $ticket->mensagem_espera_enviada) {
                $config = KanbanColunaConfig::withoutGlobalScopes()
                    ->where('tenant_id', $ticket->tenant_id)
                    ->where('coluna_kanban', $ticket->coluna_kanban)
                    ->first();

                $texto = $config?->aguardando_orientacao_mensagem
                    ?: 'Estou verificando mais detalhes sobre isso pra te dar a melhor resposta. Em breve retorno!';

                $telefone = $ticket->contato?->telefone;
                $canal    = $ticket->canal;

                if ($telefone && $canal) {
                    $enviado = $canal->servico()->enviarTexto($canal, $telefone, $texto);
                    if ($enviado) {
                        Mensagem::create([
                            'ticket_id'  => $ticket->id,
                            'tenant_id'  => $ticket->tenant_id,
                            'remetente'  => 'bot',
                            'tipo'       => 'texto',
                            'conteudo'   => $texto,
                            'enviado_em' => now(),
                        ]);
                        $ticket->update(['mensagem_espera_enviada' => true]);
                    } else {
                        Log::warning("SdrResponderJob: falha ao enviar mensagem de espera, ticket #{$this->ticketId}");
                    }
                } else {
                    Log::warning("SdrResponderJob: sem canal ou telefone, mensagem de espera não enviada, ticket #{$this->ticketId}");
                }
            }

            Log::info("SdrResponderJob: ticket #{$this->ticketId} aguardando orientação, resposta normal suprimida");
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

        $service->responder($ticket, $this->origemLigacao, orientacaoHumana: $this->orientacaoHumana);
    }
}
