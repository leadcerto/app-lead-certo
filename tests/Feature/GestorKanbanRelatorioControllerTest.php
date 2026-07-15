<?php

namespace Tests\Feature;

use App\Models\GestorKanbanRelatorio;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GestorKanbanRelatorioControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_dono_ve_lista_de_relatorios_do_proprio_tenant(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();
        $dono    = User::factory()->create(['perfil' => 'dono', 'tenant_id' => $tenantA->id, 'ativo' => true]);

        GestorKanbanRelatorio::withoutGlobalScopes()->create([
            'tenant_id' => $tenantA->id, 'semana_inicio' => '2026-07-06', 'semana_fim' => '2026-07-12',
            'dados' => ['lead_novo' => ['entradas' => 3]], 'sintese_geral' => 'Semana A',
        ]);
        GestorKanbanRelatorio::withoutGlobalScopes()->create([
            'tenant_id' => $tenantB->id, 'semana_inicio' => '2026-07-06', 'semana_fim' => '2026-07-12',
            'dados' => ['lead_novo' => ['entradas' => 9]], 'sintese_geral' => 'Semana B',
        ]);

        $response = $this->actingAs($dono)->getJson('/api/painel/kanban/relatorios');

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
        $response->assertJsonFragment(['sintese_geral' => 'Semana A']);
    }

    public function test_dono_ve_detalhe_de_relatorio_do_proprio_tenant(): void
    {
        $tenant = Tenant::factory()->create();
        $dono   = User::factory()->create(['perfil' => 'dono', 'tenant_id' => $tenant->id, 'ativo' => true]);

        $relatorio = GestorKanbanRelatorio::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id, 'semana_inicio' => '2026-07-06', 'semana_fim' => '2026-07-12',
            'dados' => ['lead_novo' => ['entradas' => 3]], 'sintese_geral' => 'Semana A',
        ]);

        $response = $this->actingAs($dono)->getJson("/api/painel/kanban/relatorios/{$relatorio->id}");

        $response->assertOk();
        $response->assertJsonFragment(['sintese_geral' => 'Semana A']);
    }

    public function test_dono_nao_acessa_relatorio_de_outro_tenant(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();
        $dono    = User::factory()->create(['perfil' => 'dono', 'tenant_id' => $tenantA->id, 'ativo' => true]);

        $relatorioB = GestorKanbanRelatorio::withoutGlobalScopes()->create([
            'tenant_id' => $tenantB->id, 'semana_inicio' => '2026-07-06', 'semana_fim' => '2026-07-12',
            'dados' => [], 'sintese_geral' => 'Semana B',
        ]);

        $response = $this->actingAs($dono)->getJson("/api/painel/kanban/relatorios/{$relatorioB->id}");

        $response->assertStatus(404);
    }
}
