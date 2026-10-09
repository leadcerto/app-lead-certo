<?php

namespace Tests\Feature;

use App\Models\Kanban;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class KanbanBladeCompileCheckTest extends TestCase
{
    use RefreshDatabase;

    public function test_pagina_kanban_renderiza_sem_erro_de_blade(): void
    {
        $tenant = Tenant::factory()->create();
        $user   = User::factory()->create(['tenant_id' => $tenant->id, 'perfil' => 'dono', 'ativo' => true]);

        $response = $this->actingAs($user)->get('/kanban');

        $response->assertOk();
    }

    public function test_barra_lateral_mostra_um_bloco_por_kanban_do_tenant(): void
    {
        $tenant = Tenant::factory()->create();
        $user   = User::factory()->create(['tenant_id' => $tenant->id, 'perfil' => 'dono', 'ativo' => true]);
        Kanban::create([
            'tenant_id' => $tenant->id, 'tipo' => 'funil_teste', 'nome' => 'Funil de Qualificação',
            'nome_curto' => 'Imersão', 'ordem' => 1,
        ]);

        $response = $this->actingAs($user)->get('/kanban');

        $response->assertOk();
        $response->assertSee('Atendimentos');
        $response->assertSee('Imersão');
        $response->assertSee('Geral');
    }

    public function test_pagina_kanban_renderiza_sem_erro_pra_perfil_sem_acesso_as_configuracoes(): void
    {
        $tenant = Tenant::factory()->create();
        $user   = User::factory()->create(['tenant_id' => $tenant->id, 'perfil' => 'vendedor', 'ativo' => true]);

        $response = $this->actingAs($user)->get('/kanban');

        $response->assertOk();
        $response->assertDontSee('kanban-geral', false);
    }
}
