<?php

namespace Tests\Feature;

use App\Models\KanbanColunaConfig;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class KanbanColunaConfigFillableTest extends TestCase
{
    use RefreshDatabase;

    public function test_sdr_delay_segundos_e_persistido_via_mass_assignment(): void
    {
        $tenant = Tenant::factory()->create();

        $config = KanbanColunaConfig::updateOrCreate(
            ['tenant_id' => $tenant->id, 'coluna_kanban' => 'em_atendimento'],
            ['sdr_delay_segundos' => 120]
        );

        $this->assertSame(120, $config->fresh()->sdr_delay_segundos);
    }

    public function test_kanban_coluna_id_e_etapa_ia_ao_mover_sao_preenchiveis(): void
    {
        $tenant = \App\Models\Tenant::factory()->create();
        $kanban = \App\Models\Kanban::create(['tenant_id' => $tenant->id, 'tipo' => 'vendas', 'nome' => 'Vendas']);
        $coluna = \App\Models\KanbanColuna::create([
            'tenant_id' => $tenant->id, 'kanban_id' => $kanban->id,
            'chave' => 'lead_novo', 'label' => 'Novo',
            'papel' => \App\Enums\PapelColunaKanban::Entrada, 'ordem' => 1,
        ]);

        $config = \App\Models\KanbanColunaConfig::create([
            'tenant_id' => $tenant->id, 'coluna_kanban' => 'lead_novo',
            'kanban_coluna_id' => $coluna->id, 'etapa_ia_ao_mover' => 'handoff',
        ]);

        $this->assertSame($coluna->id, $config->fresh()->kanban_coluna_id);
        $this->assertSame('handoff', $config->fresh()->etapa_ia_ao_mover);
    }

    public function test_etapa_ia_ao_mover_tem_default_etapa_1(): void
    {
        $tenant = \App\Models\Tenant::factory()->create();
        $config = \App\Models\KanbanColunaConfig::create([
            'tenant_id' => $tenant->id, 'coluna_kanban' => 'lead_novo',
        ]);

        $this->assertSame('etapa_1', $config->fresh()->etapa_ia_ao_mover);
    }
}