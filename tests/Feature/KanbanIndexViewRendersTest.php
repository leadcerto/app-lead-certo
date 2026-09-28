<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Smoke test — confirma que o Blade compila sem erro depois de adicionar o
 * botão "Auditoria" (pedido do Leonardo 24/09) na página do Kanban.
 */
class KanbanIndexViewRendersTest extends TestCase
{
    use RefreshDatabase;

    public function test_pagina_do_kanban_renderiza_sem_erro(): void
    {
        $tenant = Tenant::factory()->create();
        $user   = User::factory()->create(['tenant_id' => $tenant->id, 'perfil' => 'dono', 'ativo' => true]);

        $response = $this->actingAs($user)->get(route('kanban'));

        $response->assertOk();
        $response->assertSee('Auditoria');
    }
}
