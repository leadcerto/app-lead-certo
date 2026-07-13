<?php

namespace App\Console\Commands;

use App\Jobs\ConversationQAJob;
use App\Jobs\GerarResumoTicketJob;
use App\Models\KanbanColunaConfig;
use App\Models\Mensagem;
use App\Models\TicketAtendimento;
use App\Services\HumanizacaoService;
use App\Services\SdrResponderService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class FollowupConversas extends Command
{
    protected $signature = 'conversas:followup
                            {--dry-run : Mostra o que faria sem enviar}';

    protected $description = 'Envia follow-up para leads que pararam de responder (10min = reaquecimento, estágios 1/2/3 = reengajamento por silêncio, auto-mover = transferência automática de coluna, tudo configurável por coluna)';

    public function handle(SdrResponderService $sdr, HumanizacaoService $humanizacao): int
    {
        $dry = $this->option('dry-run');

        $enviados = 0;

        // ── Follow-up CURTO (10 min) ─────────────────────────────────────────
        // Última mensagem é do lead, enviada entre 10 min e 90 min atrás
        // Usamos a última mensagem pelo id máximo (compatível com only_full_group_by)
        $curtos = DB::table('tickets_atendimento as t')
            ->join(DB::raw('(
                SELECT m1.ticket_id, m1.enviado_em as ultima_em, m1.remetente as ultimo_remetente
                FROM mensagens m1
                INNER JOIN (
                    SELECT ticket_id, MAX(id) as max_id FROM mensagens GROUP BY ticket_id
                ) m2 ON m1.id = m2.max_id
            ) as ultima'), 'ultima.ticket_id', '=', 't.id')
            ->where('t.agente_responsavel', 'bot')
            ->whereNotIn('t.etapa_ia', ['handoff'])
            ->where('t.status', 'aberto')
            ->where('ultima.ultimo_remetente', 'lead')
            ->whereBetween('ultima.ultima_em', [now()->subMinutes(90), now()->subMinutes(10)])
            ->select('t.id', 't.tenant_id')
            ->get();

        $this->info("Follow-up curto (10min): {$curtos->count()} tickets");

        foreach ($curtos as $row) {
            $ticket = TicketAtendimento::withoutGlobalScopes()
                ->with(['contato', 'mensagens', 'persona', 'tenant'])
                ->find($row->id);

            if (! $ticket) continue;

            $this->line("  ↺ [curto] #{$ticket->id} — {$ticket->contato?->nome}");

            if (! $dry) {
                try {
                    $sdr->responder($ticket, gatilho: 'vacuo_10m');
                    $enviados++;
                } catch (\Exception $e) {
                    Log::warning('FollowupConversas: erro no curto', ['ticket_id' => $row->id, 'erro' => $e->getMessage()]);
                }
            }
        }

        // ── Estágios de silêncio (1/2/3) + Auto-mover de coluna ───────────────
        // Silêncio = tempo desde a última mensagem da conversa (de qualquer
        // remetente) até agora. Os limites de cada estágio, e o limite de
        // auto-mover, são configuráveis por coluna (kanban_coluna_configs).
        // Cada ticket só dispara um estágio de mensagem se ainda não tiver
        // disparado esse estágio (ou um maior). O auto-mover é independente
        // dos estágios de mensagem — dispara sozinho quando configurado.
        $horaAtual          = now()->hour;
        $emHorarioComercial = $horaAtual >= 8 && $horaAtual < 20;

        $configsPorColuna   = [];
        $estagiosDisparados = ['1' => 0, '2' => 0, '3' => 0];
        $autoMovidos        = 0;

        $candidatos = $emHorarioComercial
            ? DB::table('tickets_atendimento as t')
                ->join(DB::raw('(
                    SELECT m1.ticket_id, m1.enviado_em as ultima_em
                    FROM mensagens m1
                    INNER JOIN (SELECT ticket_id, MAX(id) as max_id FROM mensagens GROUP BY ticket_id) m2
                    ON m1.id = m2.max_id
                ) as ultima'), 'ultima.ticket_id', '=', 't.id')
                ->where('t.agente_responsavel', 'bot')
                ->whereNotIn('t.etapa_ia', ['handoff'])
                ->where('t.status', 'aberto')
                ->select('t.id', 't.tenant_id', 't.coluna_kanban', 't.followup_estagio_enviado', 'ultima.ultima_em')
                ->get()
            : collect();

        foreach ($candidatos as $row) {
            $chaveConfig = "{$row->tenant_id}:{$row->coluna_kanban}";
            if (! isset($configsPorColuna[$chaveConfig])) {
                $configsPorColuna[$chaveConfig] = KanbanColunaConfig::withoutGlobalScopes()
                    ->where('tenant_id', $row->tenant_id)
                    ->where('coluna_kanban', $row->coluna_kanban)
                    ->first();
            }
            $config = $configsPorColuna[$chaveConfig];

            $silencioSegundos = now()->diffInSeconds(Carbon::parse($row->ultima_em), absolute: true);

            $ticket = null; // carregado sob demanda, só se alguma ação for aplicável

            // ── Estágios de mensagem (1/2/3) ──────────────────────────────────
            if ($row->followup_estagio_enviado < 3) {
                $limite1 = $config?->followup_estagio1_segundos ?? 3600;
                $limite2 = $config?->followup_estagio2_segundos ?? 7200;
                $limite3 = $config?->followup_estagio3_segundos ?? 21600;

                $estagioAlvo = match (true) {
                    $silencioSegundos >= $limite3 => 3,
                    $silencioSegundos >= $limite2 => 2,
                    $silencioSegundos >= $limite1 => 1,
                    default => 0,
                };

                if ($estagioAlvo > 0 && $estagioAlvo > $row->followup_estagio_enviado) {
                    $ticket ??= TicketAtendimento::withoutGlobalScopes()
                        ->with(['contato', 'mensagens', 'persona', 'tenant'])
                        ->find($row->id);

                    if ($ticket) {
                        $this->line("  ↺ [estágio {$estagioAlvo}] #{$ticket->id} — {$ticket->contato?->nome}");

                        if (! $dry) {
                            try {
                                $sdr->responder($ticket, gatilho: "estagio_{$estagioAlvo}");
                                $ticket->update(['followup_estagio_enviado' => $estagioAlvo]);
                                $estagiosDisparados[(string) $estagioAlvo]++;
                                $enviados++;
                            } catch (\Exception $e) {
                                Log::warning('FollowupConversas: erro no estágio', [
                                    'ticket_id' => $row->id, 'estagio' => $estagioAlvo, 'erro' => $e->getMessage(),
                                ]);
                            }
                        }
                    }
                }
            }

            // ── Auto-mover de coluna por silêncio ─────────────────────────────
            if ($config?->auto_mover_ativo && $config->auto_mover_coluna_destino
                && $config->auto_mover_coluna_destino !== $row->coluna_kanban
                && $silencioSegundos >= ($config->auto_mover_segundos ?? PHP_INT_MAX)
            ) {
                $ticket ??= TicketAtendimento::withoutGlobalScopes()
                    ->with(['contato', 'mensagens', 'persona', 'tenant'])
                    ->find($row->id);

                if ($ticket && $ticket->coluna_kanban === $row->coluna_kanban) {
                    $this->line("  → [auto-mover → {$config->auto_mover_coluna_destino}] #{$ticket->id} — {$ticket->contato?->nome}");

                    if (! $dry) {
                        try {
                            $this->aplicarMovimentoAutomatico($ticket, $config->auto_mover_coluna_destino, $config->auto_mover_mensagem, $humanizacao);
                            $autoMovidos++;
                        } catch (\Exception $e) {
                            Log::warning('FollowupConversas: erro no auto-mover', [
                                'ticket_id' => $row->id, 'destino' => $config->auto_mover_coluna_destino, 'erro' => $e->getMessage(),
                            ]);
                        }
                    }
                }
            }
        }

        $this->info("Estágio 1: {$estagiosDisparados['1']} · Estágio 2: {$estagiosDisparados['2']} · Estágio 3: {$estagiosDisparados['3']} · Auto-movidos: {$autoMovidos}");
        if (! $emHorarioComercial) {
            $this->warn('Fora do horário comercial (8h-20h) — estágios de silêncio e auto-mover não disparam nesta execução.');
        }

        $this->info("Total enviados: {$enviados}");
        if ($dry) $this->warn('DRY-RUN — nada foi enviado.');

        return Command::SUCCESS;
    }

    /**
     * Move o ticket automaticamente para $destino por silêncio prolongado.
     * Se $mensagem estiver preenchida, envia antes de mover (e registra no
     * histórico). Se o destino for 'encerrado' ou 'outros', aplica os mesmos
     * efeitos que o fluxo manual equivalente (status/tag/relatórios de IA,
     * ou transferência pra humano) — pra não deixar o ticket num estado
     * inconsistente (coluna encerrada mas status ainda "aberto").
     */
    private function aplicarMovimentoAutomatico(TicketAtendimento $ticket, string $destino, ?string $mensagem, HumanizacaoService $humanizacao): void
    {
        if ($mensagem) {
            $telefone = $ticket->contato?->telefone;
            $token    = $ticket->tenant?->uazapi_instance_token;

            if ($telefone && $token) {
                $nomeContato = $ticket->contato?->nome;
                $temNome     = $nomeContato && $nomeContato !== $telefone;
                $texto       = str_replace('{nome}', $temNome ? $nomeContato : '', $mensagem);

                $humanizacao->processar($token, $telefone, $texto);

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

        if ($destino === 'encerrado') {
            $ticket->update($ticket->dadosParaEncerrar([
                'tag_desfecho' => 'sem_resposta_automatico',
                'encerrado_em' => now(),
            ]));
            ConversationQAJob::dispatch($ticket->id);
            GerarResumoTicketJob::dispatch($ticket->id)->delay(now()->addSeconds(5));
        } elseif ($destino === 'outros') {
            $ticket->update([
                'coluna_kanban'      => 'outros',
                'agente_responsavel' => 'humano',
            ]);
        } else {
            $ticket->update(['coluna_kanban' => $destino]);
        }

        Log::info("FollowupConversas: ticket #{$ticket->id} movido automaticamente por silêncio para '{$destino}'");
    }
}
