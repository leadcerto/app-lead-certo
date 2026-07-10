<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class KanbanColunaConfigButtonValidationTest extends TestCase
{
    use RefreshDatabase;

    public function test_rejeita_mais_de_3_botoes(): void
    {
        $tenant = Tenant::factory()->create();
        $user   = User::factory()->create(['tenant_id' => $tenant->id, 'perfil' => 'dono']);

        $response = $this->actingAs($user)->putJson('/api/painel/kanban/coluna-config/lead_novo', [
            'button_settings' => [
                ['text' => 'A', 'action' => 'move_column', 'target' => 'em_atendimento'],
                ['text' => 'B', 'action' => 'trigger_ia', 'target' => null],
                ['text' => 'C', 'action' => 'opt_out', 'target' => null],
                ['text' => 'D', 'action' => 'opt_out', 'target' => null],
            ],
        ]);

        $response->assertStatus(422);
    }

    public function test_aceita_ate_3_botoes_validos(): void
    {
        $tenant = Tenant::factory()->create();
        $user   = User::factory()->create(['tenant_id' => $tenant->id, 'perfil' => 'dono']);

        $response = $this->actingAs($user)->putJson('/api/painel/kanban/coluna-config/lead_novo', [
            'button_settings' => [
                ['text' => 'Falar com Humano', 'action' => 'move_column', 'target' => 'em_atendimento'],
                ['text' => 'Continuar com IA', 'action' => 'trigger_ia', 'target' => null],
            ],
        ]);

        $response->assertOk();

        $get = $this->actingAs($user)->getJson('/api/painel/kanban/coluna-config/lead_novo');
        $get->assertJsonCount(2, 'button_settings');
    }
}
