<?php
// app/Console/Commands/ExpirarPausaOrientacao.php
namespace App\Console\Commands;

use App\Models\KanbanColunaConfig;
use App\Models\TicketAtendimento;
use App\Services\AlertaInternoService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

class ExpirarPausaOrientacao extends Command
{
    protected $signature = 'conversas:expirar-pausa-orientacao
                            {--dry-run : Mostra o que faria sem alterar nada}';

    protected $description = 'Reassume automaticamente tickets pausados aguardando orientação (Regra 2) além do timeout configurado por coluna, fechando o alerta pendente';

    public function handle(AlertaInternoService $alertaService): int
    {
        $dry = $this->option('dry-run');
        $expirados = 0;

        $candidatos = TicketAtendimento::withoutGlobalScopes()
            ->with('contato')
            ->whereNotNull('aguardando_orientacao_em')
            ->get(['id', 'tenant_id', 'coluna_kanban', 'aguardando_orientacao_em', 'contato_id']);

        foreach ($candidatos as $ticket) {
            $config = KanbanColunaConfig::withoutGlobalScopes()
                ->where('tenant_id', $ticket->tenant_id)
                ->where('coluna_kanban', $ticket->coluna_kanban)
                ->first();

            if (! $config?->duvida_timeout_ativo) {
                continue;
            }

            $timeoutSegundos = $config->duvida_timeout_segundos ?? 3600;
            $esperandoSegundos = now()->diffInSeconds(Carbon::parse($ticket->aguardando_orientacao_em), absolute: true);

            if ($esperandoSegundos < $timeoutSegundos) {
                continue;
            }

            // Reconfere antes de agir — mesmo padrão defensivo do ReassumirAgente
            // (achado 3 da revisão final do Bloco 2): o humano pode ter orientado
            // entre a query e agora.
            $atual = TicketAtendimento::withoutGlobalScopes()->find($ticket->id);
            if (! $atual || ! $atual->aguardando_orientacao_em) {
                continue;
            }

            $this->line("  ⏱ [expirou] #{$ticket->id} — {$ticket->contato?->nome}");

            if ($dry) {
                continue;
            }

            try {
                $alertaService->fecharDuvidaPendente(
                    $ticket->tenant_id,
                    $ticket->id,
                    'Não respondido a tempo — retomado automaticamente.',
                );

                $atual->update([
                    'aguardando_orientacao_em' => null,
                    'mensagem_espera_enviada'  => false,
                    // Mesmo padrão do ReassumirAgente (Bloco 2, achado 2 da revisão
                    // final): sem isso, o FollowupConversas trata o silêncio que já
                    // durou o timeout como candidato a estágio de silêncio e manda
                    // uma mensagem proativa nos 5min seguintes — quebrando a
                    // promessa de reassunção silenciosa.
                    'followup_estagio_enviado' => 3,
                ]);

                $expirados++;
            } catch (\Exception $e) {
                Log::warning('ExpirarPausaOrientacao: erro ao expirar pausa', [
                    'ticket_id' => $ticket->id, 'erro' => $e->getMessage(),
                ]);
            }
        }

        $this->info("Pausas expiradas: {$expirados}");
        if ($dry) {
            $this->warn('DRY-RUN — nada foi alterado.');
        }

        return Command::SUCCESS;
    }
}
