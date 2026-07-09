<?php

namespace App\Console\Commands;

use App\Models\TicketAtendimento;
use App\Services\SdrResponderService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class FollowupConversas extends Command
{
    protected $signature = 'conversas:followup
                            {--dry-run : Mostra o que faria sem enviar}';

    protected $description = 'Envia follow-up para leads que pararam de responder (10min = reaquecimento, 12h = reengajamento)';

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

        // ── Follow-up LONGO (12h) ────────────────────────────────────────────
        // Última mensagem de qualquer um foi há mais de 12h, followup_enviado = false
        $longos = DB::table('tickets_atendimento as t')
            ->join(DB::raw('(
                SELECT m1.ticket_id, m1.enviado_em as ultima_em
                FROM mensagens m1
                INNER JOIN (SELECT ticket_id, MAX(id) as max_id FROM mensagens GROUP BY ticket_id) m2
                ON m1.id = m2.max_id
            ) as ultima'), 'ultima.ticket_id', '=', 't.id')
            ->where('t.agente_responsavel', 'bot')
            ->whereNotIn('t.etapa_ia', ['handoff'])
            ->where('t.status', 'aberto')
            ->where('t.followup_enviado', false)
            ->where('ultima.ultima_em', '<', now()->subHours(12))
            ->whereRaw('HOUR(NOW()) BETWEEN 8 AND 20') // só em horário comercial
            ->select('t.id', 't.tenant_id')
            ->get();

        $this->info("Follow-up longo (12h): {$longos->count()} tickets");

        foreach ($longos as $row) {
            $ticket = TicketAtendimento::withoutGlobalScopes()
                ->with(['contato', 'mensagens', 'persona', 'tenant'])
                ->find($row->id);

            if (! $ticket) continue;

            $this->line("  ↺ [longo] #{$ticket->id} — {$ticket->contato?->nome}");

            if (! $dry) {
                try {
                    $sdr->responder($ticket, gatilho: 'vacuo_12h');
                    $ticket->update(['followup_enviado' => true]);
                    $enviados++;
                } catch (\Exception $e) {
                    Log::warning('FollowupConversas: erro no longo', ['ticket_id' => $row->id, 'erro' => $e->getMessage()]);
                }
            }
        }

        $this->info("Total enviados: {$enviados}");
        if ($dry) $this->warn('DRY-RUN — nada foi enviado.');

        return Command::SUCCESS;
    }
}
