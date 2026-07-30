<?php

namespace Tests\Feature;

use App\Enums\PapelColunaKanban;
use App\Models\Kanban;
use App\Models\KanbanColuna;
use App\Models\KanbanColunaConfig;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Cobre a migration 2026_07_30_000002: sem ela, um tenant que já tivesse
 * tenants.retencao_conversas_dias configurado perderia a limpeza automática
 * silenciosamente no dia do deploy (o campo era removido na migration
 * seguinte sem nada assumir seu lugar).
 */
class ExclusaoDefinitivaBackfillTest extends TestCase
{
    use RefreshDatabase;

    public function test_backfill_copia_retencao_global_para_a_coluna_de_encerramento(): void
    {
        $tenant = Tenant::factory()->create();
        $kanban = Kanban::where('tenant_id', $tenant->id)->where('tipo', 'vendas')->firstOrFail();
        $colunaEncerramento = KanbanColuna::where('tenant_id', $tenant->id)
            ->where('kanban_id', $kanban->id)
            ->where('papel', PapelColunaKanban::Encerramento)
            ->firstOrFail();

        // A suíte já rodou as 3 migrations uma vez pro schema global (RefreshDatabase).
        // Desfaz as duas seguintes (remove retencao_conversas_dias) e a própria (backfill),
        // simulando fielmente o estado real: tenant antigo com retenção configurada,
        // ANTES desta leva de migrations ter passado pela VPS de produção.
        $this->artisan('migrate:rollback', ['--path' => 'database/migrations/2026_07_30_000003_remove_retencao_conversas_dias_de_tenants.php', '--realpath' => false])->run();
        $this->artisan('migrate:rollback', ['--path' => 'database/migrations/2026_07_30_000002_backfill_exclusao_definitiva_dias_da_retencao_global.php', '--realpath' => false])->run();

        DB::table('tenants')->where('id', $tenant->id)->update(['retencao_conversas_dias' => 45]);

        $this->artisan('migrate', ['--path' => 'database/migrations/2026_07_30_000002_backfill_exclusao_definitiva_dias_da_retencao_global.php', '--realpath' => false])->run();

        $config = KanbanColunaConfig::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->where('coluna_kanban', $colunaEncerramento->chave)
            ->first();

        $this->assertNotNull($config, 'Config da coluna de Encerramento deveria ter sido criado pelo backfill.');
        $this->assertTrue($config->exclusao_definitiva_ativo);
        $this->assertSame(45, $config->exclusao_definitiva_dias);
    }

    public function test_backfill_ignora_tenant_sem_retencao_configurada(): void
    {
        $tenant = Tenant::factory()->create(); // retencao_conversas_dias nulo por padrão

        $this->artisan('migrate:rollback', ['--path' => 'database/migrations/2026_07_30_000003_remove_retencao_conversas_dias_de_tenants.php', '--realpath' => false])->run();
        $this->artisan('migrate:rollback', ['--path' => 'database/migrations/2026_07_30_000002_backfill_exclusao_definitiva_dias_da_retencao_global.php', '--realpath' => false])->run();

        $this->artisan('migrate', ['--path' => 'database/migrations/2026_07_30_000002_backfill_exclusao_definitiva_dias_da_retencao_global.php', '--realpath' => false])->run();

        $this->assertDatabaseMissing('kanban_coluna_configs', ['tenant_id' => $tenant->id, 'exclusao_definitiva_ativo' => true]);
    }
}
