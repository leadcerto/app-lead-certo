<?php

namespace App\Console\Commands;

use App\Models\KanbanColunaConfig;
use App\Models\TicketAtendimento;
use App\Services\SdrResponderService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class FollowupConversas extends Command
{
    protected $signature = 'conversas:followup
                            {--dry-run : Mostra o que faria sem enviar}';

    protected $description = 'Envia follow-up para leads que pararam de responder (10min = reaquecimento, estágios 1/2/3 = reengajamento por silêncio configurável por coluna)';

    public function handle(SdrResponderService $sdr): int
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

        // ── Estágios de silêncio (1/2/3) ──────────────────────────────────────
        // Silêncio = tempo desde a última mensagem da conversa (de qualquer
        // remetente) até agora. Os limites de cada estágio são configuráveis
        // por coluna (kanban_coluna_configs.followup_estagio{1,2,3}_segundos).
        // Cada ticket só dispara um estágio se ainda não tiver disparado esse
        // estágio (ou um maior) desde a última vez que o lead respondeu.
        $horaAtual          = now()->hour;
        $emHorarioComercial = $horaAtual >= 8 && $horaAtual < 20;

        $configsPorColuna   = [];
        $estagiosDisparados = ['1' => 0, '2' => 0, '3' => 0];

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
                ->where('t.followup_estagio_enviado', '<', 3)
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

            $limite1 = $config?->followup_estagio1_segundos ?? 3600;
            $limite2 = $config?->followup_estagio2_segundos ?? 7200;
            $limite3 = $config?->followup_estagio3_segundos ?? 21600;

            $silencioSegundos = now()->diffInSeconds(\Illuminate\Support\Carbon::parse($row->ultima_em), absolute: true);

            $estagioAlvo = match (true) {
                $silencioSegundos >= $limite3 => 3,
                $silencioSegundos >= $limite2 => 2,
                $silencioSegundos >= $limite1 => 1,
                default => 0,
            };

            if ($estagioAlvo === 0 || $estagioAlvo <= $row->followup_estagio_enviado) {
                continue;
            }

            $ticket = TicketAtendimento::withoutGlobalScopes()
                ->with(['contato', 'mensagens', 'persona', 'tenant'])
                ->find($row->id);

            if (! $ticket) continue;

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

        $this->info("Estágio 1: {$estagiosDisparados['1']} · Estágio 2: {$estagiosDisparados['2']} · Estágio 3: {$estagiosDisparados['3']}");
        if (! $emHorarioComercial) {
            $this->warn('Fora do horário comercial (8h-20h) — estágios de silêncio não disparam nesta execução.');
        }

        $this->info("Total enviados: {$enviados}");
        if ($dry) $this->warn('DRY-RUN — nada foi enviado.');

        return Command::SUCCESS;
    }
}
