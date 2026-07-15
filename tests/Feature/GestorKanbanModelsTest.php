<?php

namespace Tests\Feature;

use App\Models\GestorKanbanConfig;
use App\Models\GestorKanbanRelatorio;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GestorKanbanModelsTest extends TestCase
{
    use RefreshDatabase;

    public function test_config_global_ja_vem_semeada_pela_migration(): void
    {
        $config = GestorKanbanConfig::first();

        $this->assertNotNull($config);
        $this->assertNotEmpty($config->prompt_coluna);
        $this->assertNotEmpty($config->prompt_sintese);
    }

    public function test_existe_apenas_uma_linha_de_config(): void
    {
        $this->assertSame(1, GestorKanbanConfig::count());
    }

    public function test_relatorio_e_isolado_por_tenant(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();

        GestorKanbanRelatorio::create([
            'tenant_id'     => $tenantA->id,
            'semana_inicio' => '2026-07-06',
            'semana_fim'    => '2026-07-12',
            'dados'         => ['lead_novo' => ['entradas' => 3]],
            'sintese_geral' => 'Semana ok para o tenant A.',
        ]);

        GestorKanbanRelatorio::create([
            'tenant_id'     => $tenantB->id,
            'semana_inicio' => '2026-07-06',
            'semana_fim'    => '2026-07-12',
            'dados'         => ['lead_novo' => ['entradas' => 9]],
            'sintese_geral' => 'Semana ok para o tenant B.',
        ]);

        session(['tenant_id' => $tenantA->id]);
        $this->actingAs(\App\Models\User::factory()->create(['tenant_id' => $tenantA->id]));

        $relatorios = GestorKanbanRelatorio::all();

        $this->assertCount(1, $relatorios);
        $this->assertSame('Semana ok para o tenant A.', $relatorios->first()->sintese_geral);
    }

    public function test_indice_unico_por_tenant_e_semana(): void
    {
        $tenant = Tenant::factory()->create();

        GestorKanbanRelatorio::create([
            'tenant_id' => $tenant->id, 'semana_inicio' => '2026-07-06', 'semana_fim' => '2026-07-12',
            'dados' => [], 'sintese_geral' => 'primeira',
        ]);

        $this->expectException(\Illuminate\Database\QueryException::class);

        GestorKanbanRelatorio::create([
            'tenant_id' => $tenant->id, 'semana_inicio' => '2026-07-06', 'semana_fim' => '2026-07-12',
            'dados' => [], 'sintese_geral' => 'duplicada',
        ]);
    }
}
