<?php
// app/Console/Commands/MonitorarKanban.php
namespace App\Console\Commands;

use App\Models\TicketAtendimento;
use App\Services\AlertaInternoService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class MonitorarKanban extends Command
{
    protected $signature = 'kanban:monitorar
                            {--dry-run : Mostra o que faria sem alterar nada}';

    protected $description = 'Alerta tickets travados além do tempo máximo configurado por coluna (Regra 3/12)';

    public function handle(AlertaInternoService $alertaService): int
    {
        $dry = $this->option('dry-run');
        $travados = 0;

        // Mesmo padrão de "última linha por ticket" já usado em
        // ReassumirAgente (lá era "última mensagem"; aqui é "última entrada
        // de coluna") — junta com a config da coluna atual, ignora colunas
        // sem tempo_maximo_permanencia_minutos configurado, e só considera
        // tickets ainda abertos (um ticket encerrado parado na coluna de
        // Encerramento é esperado, não é "travado").
        $candidatos = DB::table('kanban_coluna_historico as h')
            ->join(DB::raw('(
                SELECT ticket_id, MAX(id) as max_id FROM kanban_coluna_historico GROUP BY ticket_id
            ) as ultimo'), function ($join) {
                $join->on('h.ticket_id', '=', 'ultimo.ticket_id')
                     ->on('h.id', '=', 'ultimo.max_id');
            })
            ->whereNull('h.alertado_em')
            ->join('kanban_coluna_configs as c', function ($join) {
                $join->on('c.tenant_id', '=', 'h.tenant_id')
                     ->on('c.coluna_kanban', '=', 'h.coluna');
            })
            ->whereNotNull('c.tempo_maximo_permanencia_minutos')
            ->join('tickets_atendimento as t', 't.id', '=', 'h.ticket_id')
            ->where('t.status', 'aberto')
            ->select('h.id as historico_id', 'h.tenant_id', 'h.ticket_id', 'h.coluna', 'h.entrou_em', 'c.tempo_maximo_permanencia_minutos')
            ->get();

        foreach ($candidatos as $row) {
            // absolute: true — mesmo padrão do ReassumirAgente (Bloco 2), evita
            // um valor negativo se o relógio/timezone divergir por algum motivo.
            $minutosParado = now()->diffInMinutes(Carbon::parse($row->entrou_em), absolute: true);

            if ($minutosParado < $row->tempo_maximo_permanencia_minutos) {
                continue;
            }

            // Reconfere o ticket antes de agir — mesmo padrão defensivo do
            // ReassumirAgente (achado 3 da revisão final do Bloco 2): entre a
            // query e agora, o ticket pode ter saído dessa coluna.
            $ticket = TicketAtendimento::withoutGlobalScopes()->with('contato')->find($row->ticket_id);
            if (! $ticket || $ticket->coluna_kanban !== $row->coluna) {
                continue;
            }

            $nomeContato = $ticket->contato?->nome ?? 'contato sem nome';
            $this->line("  ⏱ [travado] #{$ticket->id} — {$nomeContato} — {$row->coluna}");

            if ($dry) {
                continue;
            }

            try {
                $horas = round($minutosParado / 60, 1);
                $alertaService->criar(
                    $ticket->tenant_id,
                    'ticket_travado',
                    "{$nomeContato} travado há {$horas}h na coluna",
                    "O ticket está na coluna \"{$row->coluna}\" há {$horas} horas, além do tempo máximo configurado ({$row->tempo_maximo_permanencia_minutos} min).",
                    $ticket->id,
                );

                // Só marca alertado_em se o alerta foi criado com sucesso —
                // diferente do padrão do ReassumirAgente (lá a ação principal
                // era independente do alerta). Aqui o alerta É a ação
                // principal: se ele falhar, não faz sentido suprimir a
                // tentativa seguinte — deixa tentar de novo daqui a 15min.
                DB::table('kanban_coluna_historico')->where('id', $row->historico_id)->update(['alertado_em' => now()]);

                $travados++;
            } catch (\Exception $e) {
                Log::warning('MonitorarKanban: erro ao alertar ticket travado', [
                    'ticket_id' => $row->ticket_id, 'erro' => $e->getMessage(),
                ]);
            }
        }

        $this->info("Travados alertados: {$travados}");
        if ($dry) {
            $this->warn('DRY-RUN — nada foi alterado.');
        }

        return Command::SUCCESS;
    }
}
