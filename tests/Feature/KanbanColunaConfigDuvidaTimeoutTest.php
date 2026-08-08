<?php
// tests/Feature/KanbanColunaConfigDuvidaTimeoutTest.php
namespace Tests\Feature;

use App\Models\KanbanColunaConfig;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class KanbanColunaConfigDuvidaTimeoutTest extends TestCase
{
    use RefreshDatabase;

    public function test_show_retorna_defaults_quando_nao_configurado(): void
    {
        $tenant = Tenant::factory()->create();
        $user   = User::factory()->create(['tenant_id' => $tenant->id, 'perfil' => 'dono', 'ativo' => true]);

        $response = $this->actingAs($user)->getJson('/api/painel/kanban/coluna-config/lead_novo');

        $response->assertOk();
        $response->assertJson(['duvida_timeout_ativo' => false, 'duvida_timeout_segundos' => 3600]);
    }

    public function test_update_salva_o_timeout_configurado(): void
    {
        $tenant = Tenant::factory()->create();
        $user   = User::factory()->create(['tenant_id' => $tenant->id, 'perfil' => 'dono', 'ativo' => true]);

        $response = $this->actingAs($user)->putJson('/api/painel/kanban/coluna-config/em_atendimento', [
            'duvida_timeout_ativo' => true, 'duvida_timeout_segundos' => 1800,
        ]);

        $response->assertOk();
        $config = KanbanColunaConfig::where('tenant_id', $tenant->id)->where('coluna_kanban', 'em_atendimento')->first();
        $this->assertTrue($config->duvida_timeout_ativo);
        $this->assertSame(1800, $config->duvida_timeout_segundos);
    }
}
