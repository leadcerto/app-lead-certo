<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RelatoriosGestorVisualizacaoTest extends TestCase
{
    use RefreshDatabase;

    public function test_vendedor_visualiza_relatorios_do_gestor(): void
    {
        $tenant = Tenant::factory()->create();
        $user   = User::factory()->create(['tenant_id' => $tenant->id, 'perfil' => 'vendedor', 'ativo' => true]);

        $this->actingAs($user)->get('/kanban/relatorios')->assertOk();
        $this->actingAs($user)->getJson('/api/painel/kanban/relatorios')->assertOk();
    }
}
