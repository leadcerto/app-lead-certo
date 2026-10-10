<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class KanbanConfigPermissaoEdicaoTest extends TestCase
{
    use RefreshDatabase;

    public function test_vendedor_visualiza_configuracoes_do_kanban(): void
    {
        $tenant = Tenant::factory()->create();
        $user   = User::factory()->create(['tenant_id' => $tenant->id, 'perfil' => 'vendedor', 'ativo' => true]);

        $this->actingAs($user)->get('/kanban/config')->assertOk();
        $this->actingAs($user)->getJson('/api/painel/kanban/colunas')->assertOk();
        $this->actingAs($user)->getJson('/api/painel/kanban/variaveis')->assertOk();
    }

    public function test_dono_nao_edita_mais_colunas_nem_variaveis(): void
    {
        $tenant = Tenant::factory()->create();
        $user   = User::factory()->create(['tenant_id' => $tenant->id, 'perfil' => 'dono', 'ativo' => true]);

        $this->actingAs($user)->postJson('/api/painel/kanban/colunas', [
            'label' => 'Nova', 'papel' => 'em_andamento',
        ])->assertStatus(403);

        $this->actingAs($user)->postJson('/api/painel/kanban/variaveis', [
            'nome' => 'teste', 'label' => 'Teste', 'opcoes' => 'a, b',
        ])->assertStatus(403);

        $this->actingAs($user)->postJson('/api/painel/kanban', [
            'nome' => 'Novo Kanban', 'nome_curto' => 'Novo',
        ])->assertStatus(403);
    }

    public function test_admin_continua_editando_colunas_e_variaveis(): void
    {
        $tenant = Tenant::factory()->create();
        $user   = User::factory()->create(['tenant_id' => $tenant->id, 'perfil' => 'admin', 'ativo' => true]);

        $this->actingAs($user)->postJson('/api/painel/kanban/colunas', [
            'label' => 'Nova', 'papel' => 'em_andamento',
        ])->assertStatus(201);

        $this->actingAs($user)->postJson('/api/painel/kanban/variaveis', [
            'nome' => 'teste', 'label' => 'Teste', 'opcoes' => 'a, b',
        ])->assertStatus(201);
    }

    public function test_perfil_sem_acesso_a_kanban_continua_barrado(): void
    {
        $tenant = Tenant::factory()->create();
        $user   = User::factory()->create(['tenant_id' => $tenant->id, 'perfil' => 'auditor', 'ativo' => true]);

        $this->actingAs($user)->get('/kanban/config')->assertStatus(403);
        $this->actingAs($user)->getJson('/api/painel/kanban/colunas')->assertStatus(403);
    }
}
