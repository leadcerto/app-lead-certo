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

    protected $description = 'Deleta tickets e mensagens mais antigos que o limite de retenção configurado por tenant';

    public function handle(): int
    {
        $dry      = $this->option('dry-run');
        $soTenant = $this->option('tenant');

        $query = DB::table('tenants')
            ->whereNotNull('retencao_conversas_dias')
            ->where('retencao_conversas_dias', '>', 0)
            ->select('id', 'nome', 'retencao_conversas_dias');

        if ($soTenant) {
            $query->where('id', (int) $soTenant);
        }

        $tenants = $query->get();

        if ($tenants->isEmpty()) {
            $this->info('Nenhum tenant com retenção configurada.');
            return Command::SUCCESS;
        }

        $totalTickets = 0;

        foreach ($tenants as $tenant) {
            $corte = now()->subDays($tenant->retencao_conversas_dias);

            $count = DB::table('tickets_atendimento')
                ->where('tenant_id', $tenant->id)
                ->where('updated_at', '<', $corte)
                ->count();

            $this->line("Tenant #{$tenant->id} ({$tenant->nome}): {$count} tickets anteriores a {$corte->toDateString()} (retenção: {$tenant->retencao_conversas_dias} dias)");

            if (! $dry && $count > 0) {
                // Mensagens são deletadas em cascade (FK cascadeOnDelete)
                $deleted = DB::table('tickets_atendimento')
                    ->where('tenant_id', $tenant->id)
                    ->where('updated_at', '<', $corte)
                    ->delete();

                $this->info("  → {$deleted} tickets deletados (com mensagens em cascade)");

                Log::info('LimparConversasAntigas', [
                    'tenant_id'   => $tenant->id,
                    'tenant_nome' => $tenant->nome,
                    'deletados'   => $deleted,
                    'corte'       => $corte->toDateTimeString(),
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
