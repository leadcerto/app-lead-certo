<?php
// tests/Feature/KanbanInfoControllerTest.php
namespace Tests\Feature;

use App\Models\Kanban;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class KanbanInfoControllerTest extends TestCase
{
    use RefreshDatabase;

    private function criarUsuarioDono(Tenant $tenant): User
    {
        return User::factory()->create(['tenant_id' => $tenant->id, 'perfil' => 'dono', 'ativo' => true]);
    }

    public function test_show_retorna_vazio_quando_nao_configurado(): void
    {
        $tenant = Tenant::factory()->create();
        $user   = $this->criarUsuarioDono($tenant);

        $response = $this->actingAs($user)->getJson('/api/painel/kanban/info');

        $response->assertOk();
        $response->assertJson(['conhecimento_geral' => '']);
    }

    public function test_update_persiste_conhecimento_geral(): void
    {
        $tenant = Tenant::factory()->create();
        $user   = $this->criarUsuarioDono($tenant);

        $response = $this->actingAs($user)->putJson('/api/painel/kanban/info', [
            'conhecimento_geral' => 'Atendemos só Zona Sul do Rio de Janeiro.',
        ]);

        $response->assertOk();

        $kanban = Kanban::where('tenant_id', $tenant->id)->where('tipo', 'vendas')->first();
        $this->assertSame('Atendemos só Zona Sul do Rio de Janeiro.', $kanban->conhecimento_geral);
    }
}
