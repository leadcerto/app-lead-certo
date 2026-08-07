<?php
// app/Console/Commands/ReassumirAgente.php
namespace App\Console\Commands;

use App\Models\KanbanColunaConfig;
use App\Models\TicketAtendimento;
use App\Services\AlertaInternoService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ReassumirAgente extends Command
{
    protected $signature = 'conversas:reassumir-agente
                            {--dry-run : Mostra o que faria sem alterar nada}';

    protected $description = 'Reassume automaticamente conversas onde o humano assumiu e ficou em silêncio além do timeout configurado por coluna (Regra 1)';

    public function handle(AlertaInternoService $alertaService): int
    {
        $dry = $this->option('dry-run');
        $reassumidos = 0;

        // Mesmo padrão de "última mensagem por ticket" já usado em
        // FollowupConversas — silêncio conta desde a última mensagem da
        // conversa, de qualquer remetente (humano ou lead).
        $candidatos = DB::table('tickets_atendimento as t')
            ->join(DB::raw('(
                SELECT m1.ticket_id, m1.enviado_em as ultima_em
                FROM mensagens m1
                INNER JOIN (
                    SELECT ticket_id, MAX(id) as max_id FROM mensagens GROUP BY ticket_id
                ) m2 ON m1.id = m2.max_id
            ) as ultima'), 'ultima.ticket_id', '=', 't.id')
            ->where('t.agente_responsavel', 'humano')
            ->where('t.status', 'aberto')
            ->select('t.id', 't.tenant_id', 't.coluna_kanban', 'ultima.ultima_em')
            ->get();

        foreach ($candidatos as $row) {
            $config = KanbanColunaConfig::withoutGlobalScopes()
                ->where('tenant_id', $row->tenant_id)
                ->where('coluna_kanban', $row->coluna_kanban)
                ->first();

            if (! $config?->timeout_reassuncao_ativo || ! $config->timeout_reassuncao_segundos) {
                continue;
            }

            $silencioSegundos = now()->diffInSeconds(Carbon::parse($row->ultima_em), absolute: true);

            if ($silencioSegundos < $config->timeout_reassuncao_segundos) {
                continue;
            }

            $ticket = TicketAtendimento::withoutGlobalScopes()->with('contato')->find($row->id);
            if (! $ticket) {
                continue;
            }

            $this->line("  ↺ [reassumir] #{$ticket->id} — {$ticket->contato?->nome}");

            if ($dry) {
                continue;
            }

            try {
                $ticket->update(['agente_responsavel' => 'bot']);

                $horas = round($silencioSegundos / 3600, 1);
                $nomeContato = $ticket->contato?->nome ?? 'contato sem nome';
                $alertaService->criar(
                    $ticket->tenant_id,
                    'reassuncao_automatica',
                    "Agente reassumiu a conversa após {$horas}h de silêncio",
                    "O atendente não respondeu, e {$nomeContato} também não escreveu, por {$horas} horas — o agente de IA retomou o atendimento automaticamente.",
                    $ticket->id,
                );
                $reassumidos++;
            } catch (\Exception $e) {
                Log::warning('ReassumirAgente: erro ao reassumir', [
                    'ticket_id' => $row->id, 'erro' => $e->getMessage(),
                ]);
            }
        }

        $this->info("Reassumidos: {$reassumidos}");
        if ($dry) {
            $this->warn('DRY-RUN — nada foi alterado.');
        }

        return Command::SUCCESS;
    }
}
