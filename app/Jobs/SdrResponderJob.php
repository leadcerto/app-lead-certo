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

    // Teto de reagendamentos por debounce — nunca deve ser atingido em produção
    // de verdade (cada reagendamento já mira depois da janela da mensagem mais
    // recente), só protege contra uma corrida esquisita virar loop infinito.
    private const MAX_TENTATIVAS_DEBOUNCE = 5;

    public function __construct(
        private int     $ticketId,
        private string  $ultimaMensagem  = '',
        private bool    $origemLigacao   = false,
        private bool    $imediato        = false,
        private int     $debounceSegundos = self::DEBOUNCE_SEGUNDOS,
        private ?string $orientacaoHumana = null,
        private int     $tentativaDebounce = 0,
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

            // Achado real 2026-09-17 (ticket #4791): Carbon 3.x mudou o padrão de
            // diffInSeconds() pra devolver diferença COM SINAL (não mais absoluta) —
            // now()->diffInSeconds($passado) agora vem NEGATIVO. Sem abs() aqui, a
            // condição "< debounceSegundos" ficava sempre verdadeira pra qualquer
            // mensagem no passado (não só as recentes), fazendo o job se auto-cancelar
            // SEMPRE que já havia mensagem do lead — silenciosamente, sem nunca chamar
            // a IA de verdade. Isso explica as execuções "suspeitosamente rápidas" (ms)
            // vistas nos logs em vários tickets ao longo do dia.
            $segundosDesde = $ultimaMensagemEm ? abs(now()->diffInSeconds($ultimaMensagemEm)) : null;
            if ($segundosDesde !== null && $segundosDesde < $this->debounceSegundos) {
                Log::info("SdrResponderJob: debounce — lead digitando, job cancelado. ticket #{$this->ticketId}");

                // Achado real 2026-09-17 (ticket #4791): sem isso, um job cancelado por
                // debounce nunca era reavaliado se o lead simplesmente parasse de
                // escrever (ex.: mandou o nome e ficou esperando) — a resposta nunca
                // saía, silenciosamente, até um humano notar e assumir na mão. Reagenda
                // pra reavaliar depois que a janela de debounce da última mensagem dele
                // fechar de verdade.
                if ($this->tentativaDebounce < self::MAX_TENTATIVAS_DEBOUNCE) {
                    $restante = max(5, $this->debounceSegundos - (int) $segundosDesde) + 2;
                    self::dispatch(
                        $this->ticketId,
                        $this->ultimaMensagem,
                        $this->origemLigacao,
                        false,
                        $this->debounceSegundos,
                        $this->orientacaoHumana,
                        $this->tentativaDebounce + 1,
                    )->delay(now()->addSeconds($restante));
                }

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
