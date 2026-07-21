<?php

namespace Tests\Feature;

use App\Models\KanbanColuna;
use App\Models\KanbanColunaConfig;
use App\Models\Tenant;
use App\Services\TenantSetupService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TenantSetupServiceKanbanColunasTest extends TestCase
{
    use RefreshDatabase;

    public function test_configurar_liga_cada_config_ao_kanban_coluna_id_e_preenche_etapa_ia_ao_mover(): void
    {
        $tenant = Tenant::factory()->create();

        app(TenantSetupService::class)->configurar($tenant);

        $colunaAguardandoOrcamento = KanbanColuna::where('tenant_id', $tenant->id)
            ->where('chave', 'aguardando_orcamento')->firstOrFail();
        $configAguardandoOrcamento = KanbanColunaConfig::where('tenant_id', $tenant->id)
            ->where('coluna_kanban', 'aguardando_orcamento')->firstOrFail();

        $this->assertSame($colunaAguardandoOrcamento->id, $configAguardandoOrcamento->kanban_coluna_id);
        $this->assertSame('handoff', $configAguardandoOrcamento->etapa_ia_ao_mover);

        $configLeadNovo = KanbanColunaConfig::where('tenant_id', $tenant->id)
            ->where('coluna_kanban', 'lead_novo')->firstOrFail();
        $this->assertSame('etapa_1', $configLeadNovo->etapa_ia_ao_mover);
    }

    public function test_configurar_e_idempotente_rodando_duas_vezes(): void
    {
        $tenant = Tenant::factory()->create();

        app(TenantSetupService::class)->configurar($tenant);
        app(TenantSetupService::class)->configurar($tenant);

        $this->assertCount(8, KanbanColuna::where('tenant_id', $tenant->id)->get());
        $this->assertCount(7, KanbanColunaConfig::where('tenant_id', $tenant->id)->get());
    }
}
