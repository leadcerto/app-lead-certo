<?php
// tests/Feature/KanbanColunaConfigTempoMaximoPermanenciaTest.php
namespace Tests\Feature;

use App\Models\KanbanColunaConfig;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class KanbanColunaConfigTempoMaximoPermanenciaTest extends TestCase
{
    use RefreshDatabase;

    public function test_show_retorna_null_quando_nao_configurado(): void
    {
        $tenant = Tenant::factory()->create();
        $user   = User::factory()->create(['tenant_id' => $tenant->id, 'perfil' => 'dono', 'ativo' => true]);

        $response = $this->actingAs($user)->getJson('/api/painel/kanban/coluna-config/lead_novo');

        $response->assertOk();
        $response->assertJson(['tempo_maximo_permanencia_minutos' => null]);
    }

    public function test_update_salva_o_tempo_maximo_configurado(): void
    {
        $tenant = Tenant::factory()->create();
        $user   = User::factory()->create(['tenant_id' => $tenant->id, 'perfil' => 'dono', 'ativo' => true]);

        $response = $this->actingAs($user)->putJson('/api/painel/kanban/coluna-config/aguardando_orcamento', [
            'tempo_maximo_permanencia_minutos' => 120,
        ]);

        $response->assertOk();
        $this->assertSame(
            120,
            KanbanColunaConfig::where('tenant_id', $tenant->id)->where('coluna_kanban', 'aguardando_orcamento')->value('tempo_maximo_permanencia_minutos')
        );
    }

    public function test_update_rejeita_valor_nao_inteiro_ou_menor_que_um(): void
    {
        $tenant = Tenant::factory()->create();
        $user   = User::factory()->create(['tenant_id' => $tenant->id, 'perfil' => 'dono', 'ativo' => true]);

        $response = $this->actingAs($user)->putJson('/api/painel/kanban/coluna-config/aguardando_orcamento', [
            'tempo_maximo_permanencia_minutos' => 0,
        ]);

        $response->assertStatus(422);
    }
}
