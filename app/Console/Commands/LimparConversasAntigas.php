<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class LimparConversasAntigas extends Command
{
    protected $signature = 'conversas:limpar-antigas
                            {--dry-run : Mostra o que seria deletado sem executar}
                            {--tenant= : Processa apenas este tenant (ID)}';

    protected $description = 'Deleta definitivamente tickets encerrados há mais tempo que o configurado por coluna (kanban_coluna_configs.exclusao_definitiva_dias)';

    public function handle(): int
    {
        $dry      = $this->option('dry-run');
        $soTenant = $this->option('tenant');

        // Substitui o antigo prazo único por tenant (tenants.retencao_conversas_dias):
        // agora cada coluna decide seu próprio prazo, e só tickets ENCERRADOS entram
        // na conta — um ticket ainda aberto, mesmo quieto há meses, nunca é tocado
        // (o comando antigo apagava por 'updated_at' de qualquer status, um risco
        // real de perder lead ativo só por estar quieto).
        $query = DB::table('kanban_coluna_configs')
            ->where('exclusao_definitiva_ativo', true)
            ->whereNotNull('exclusao_definitiva_dias')
            ->select('id', 'tenant_id', 'coluna_kanban', 'exclusao_definitiva_dias');

        if ($soTenant) {
            $query->where('tenant_id', (int) $soTenant);
        }

        $configs = $query->get();

        if ($configs->isEmpty()) {
            $this->info('Nenhuma coluna com exclusão definitiva configurada.');
            return Command::SUCCESS;
        }

        $totalTickets = 0;

        foreach ($configs as $config) {
            $corte = now()->subDays($config->exclusao_definitiva_dias);

            $base = DB::table('tickets_atendimento')
                ->where('tenant_id', $config->tenant_id)
                ->where('coluna_kanban', $config->coluna_kanban)
                ->where('status', 'encerrado')
                ->where('encerrado_em', '<', $corte);

            $count = (clone $base)->count();

            $this->line("Tenant #{$config->tenant_id}, coluna '{$config->coluna_kanban}': {$count} tickets encerrados antes de {$corte->toDateString()} (prazo: {$config->exclusao_definitiva_dias} dias)");

            if (! $dry && $count > 0) {
                // Mensagens são deletadas em cascade (FK cascadeOnDelete)
                $deleted = $base->delete();

                $this->info("  → {$deleted} tickets deletados (com mensagens em cascade)");

                Log::info('LimparConversasAntigas', [
                    'tenant_id'     => $config->tenant_id,
                    'coluna_kanban' => $config->coluna_kanban,
                    'deletados'     => $deleted,
                    'corte'         => $corte->toDateTimeString(),
                ]);

                $totalTickets += $deleted;
            }
        }

        if ($dry) {
            $this->warn('DRY-RUN — nenhuma alteração foi salva.');
        } else {
            $this->info("Total: {$totalTickets} tickets deletados.");
        }

        return Command::SUCCESS;
    }
}
