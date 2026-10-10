<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MotivoDesfechoPermissaoEdicaoTest extends TestCase
{
    use RefreshDatabase;

    public function test_vendedor_visualiza_motivos_de_encerramento(): void
    {
        $tenant = Tenant::factory()->create();
        $user   = User::factory()->create(['tenant_id' => $tenant->id, 'perfil' => 'vendedor', 'ativo' => true]);

        $this->actingAs($user)->get('/kanban/motivos-desfecho')->assertOk();
        $this->actingAs($user)->getJson('/api/painel/kanban/motivos-desfecho')->assertOk();
    }

    public function test_dono_nao_edita_mais_motivos_de_encerramento(): void
    {
        $tenant = Tenant::factory()->create();
        $user   = User::factory()->create(['tenant_id' => $tenant->id, 'perfil' => 'dono', 'ativo' => true]);

        $this->actingAs($user)->postJson('/api/painel/kanban/motivos-desfecho', [
            'label' => 'Teste', 'e_venda' => false,
        ])->assertStatus(403);
    }

    public function test_admin_continua_editando_motivos_de_encerramento(): void
    {
        $tenant = Tenant::factory()->create();
        $user   = User::factory()->create(['tenant_id' => $tenant->id, 'perfil' => 'admin', 'ativo' => true]);

        $this->actingAs($user)->postJson('/api/painel/kanban/motivos-desfecho', [
            'label' => 'Teste', 'e_venda' => false,
        ])->assertStatus(201);
    }
}
