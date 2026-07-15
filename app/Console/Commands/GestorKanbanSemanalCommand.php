<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Services\GestorKanbanService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class GestorKanbanSemanalCommand extends Command
{
    protected $signature = 'kanban:gestor-semanal
                            {--tenant= : Roda só para este tenant_id}
                            {--dry-run : Mostra o que faria sem chamar IA nem persistir}';

    protected $description = 'Gera o relatório semanal do Gestor do Kanban para os tenants ativos';

    public function handle(GestorKanbanService $service): int
    {
        $dryRun = $this->option('dry-run');
        // Termina ONTEM (não hoje) — se rodar manualmente via --tenant no meio
        // do sábado, um Carbon::now()->endOfDay() incluiria o próprio sábado
        // (ainda incompleto) na janela "semana anterior", gerando 8 dias em
        // vez de 7 e misturando dado parcial de hoje no relatório.
        $fim    = Carbon::yesterday()->endOfDay();
        $inicio = $fim->copy()->subDays(6)->startOfDay();

        $query = Tenant::query();

        if ($tenantId = $this->option('tenant')) {
            $query->where('id', $tenantId);
        } else {
            $query->where('status', 'ativo');
        }

        $tenants = $query->get();

        $this->info("Gerando relatório semanal ({$inicio->toDateString()} a {$fim->toDateString()}) para {$tenants->count()} tenant(s).");

        foreach ($tenants as $tenant) {
            if ($dryRun) {
                $this->line("  [DRY-RUN] Processaria tenant #{$tenant->id} ({$tenant->nome})");
                continue;
            }

            $relatorio = $service->gerarRelatorioSemanal($tenant, $inicio, $fim);

            if ($relatorio) {
                $this->line("  ✓ Relatório gerado para tenant #{$tenant->id} ({$tenant->nome})");
            } else {
                $this->line("  – Sem atividade para tenant #{$tenant->id} ({$tenant->nome}), pulado");
            }
        }

        return Command::SUCCESS;
    }
}
