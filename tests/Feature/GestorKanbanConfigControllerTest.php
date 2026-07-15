<?php

namespace Tests\Feature;

use App\Models\GestorKanbanConfig;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GestorKanbanConfigControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_ve_o_prompt_atual(): void
    {
        $admin = User::factory()->create(['perfil' => 'admin', 'tenant_id' => null, 'ativo' => true]);

        $response = $this->actingAs($admin)->getJson('/api/admin/gestor-kanban/prompt');

        $response->assertOk();
        $response->assertJsonStructure(['prompt_coluna', 'prompt_sintese']);
    }

    public function test_admin_atualiza_o_prompt(): void
    {
        $admin = User::factory()->create(['perfil' => 'admin', 'tenant_id' => null, 'ativo' => true]);

        $response = $this->actingAs($admin)->putJson('/api/admin/gestor-kanban/prompt', [
            'prompt_coluna'  => 'Novo prompt de coluna',
            'prompt_sintese' => 'Novo prompt de síntese',
        ]);

        $response->assertOk();
        $config = GestorKanbanConfig::first();
        $this->assertSame('Novo prompt de coluna', $config->prompt_coluna);
        $this->assertSame($admin->id, $config->updated_by);
    }

    public function test_dono_nao_acessa_a_config(): void
    {
        $tenant = Tenant::factory()->create();
        $dono   = User::factory()->create(['perfil' => 'dono', 'tenant_id' => $tenant->id, 'ativo' => true]);

        $response = $this->actingAs($dono)->getJson('/api/admin/gestor-kanban/prompt');

        $response->assertStatus(403);
    }

    public function test_dono_nao_consegue_editar_a_config(): void
    {
        $tenant = Tenant::factory()->create();
        $dono   = User::factory()->create(['perfil' => 'dono', 'tenant_id' => $tenant->id, 'ativo' => true]);

        $response = $this->actingAs($dono)->putJson('/api/admin/gestor-kanban/prompt', [
            'prompt_coluna'  => 'Tentativa de invasão',
            'prompt_sintese' => 'Tentativa de invasão',
        ]);

        $response->assertStatus(403);
    }
}
