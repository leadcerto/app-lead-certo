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

    public function test_aceita_botao_open_url_e_persiste_target(): void
    {
        $tenant = Tenant::factory()->create();
        $user   = User::factory()->create(['tenant_id' => $tenant->id, 'perfil' => 'dono']);

        $response = $this->actingAs($user)->putJson('/api/painel/kanban/coluna-config/lead_novo', [
            'button_settings' => [
                ['text' => 'Ver Produto', 'action' => 'open_url', 'target' => 'https://exemplo.com/produto'],
            ],
        ]);

        $response->assertOk();

        $get = $this->actingAs($user)->getJson('/api/painel/kanban/coluna-config/lead_novo');
        $get->assertJsonPath('button_settings.0.action', 'open_url');
        $get->assertJsonPath('button_settings.0.target', 'https://exemplo.com/produto');
    }

    public function test_aceita_botao_call_e_persiste_target(): void
    {
        $tenant = Tenant::factory()->create();
        $user   = User::factory()->create(['tenant_id' => $tenant->id, 'perfil' => 'dono']);

        $response = $this->actingAs($user)->putJson('/api/painel/kanban/coluna-config/lead_novo', [
            'button_settings' => [
                ['text' => 'Ligar', 'action' => 'call', 'target' => '+5511999999999'],
            ],
        ]);

        $response->assertOk();

        $get = $this->actingAs($user)->getJson('/api/painel/kanban/coluna-config/lead_novo');
        $get->assertJsonPath('button_settings.0.action', 'call');
        $get->assertJsonPath('button_settings.0.target', '+5511999999999');
    }

    public function test_aceita_target_maior_que_50_caracteres_ate_255(): void
    {
        $tenant = Tenant::factory()->create();
        $user   = User::factory()->create(['tenant_id' => $tenant->id, 'perfil' => 'dono']);

        $urlLonga = 'https://exemplo.com/produto/categoria/subcategoria?utm_source=whatsapp&utm_medium=kanban&utm_campaign=lead-novo&ref=' . str_repeat('a', 40);

        $this->assertGreaterThan(50, strlen($urlLonga));
        $this->assertLessThan(255, strlen($urlLonga));

        $response = $this->actingAs($user)->putJson('/api/painel/kanban/coluna-config/lead_novo', [
            'button_settings' => [
                ['text' => 'Ver Produto', 'action' => 'open_url', 'target' => $urlLonga],
            ],
        ]);

        $response->assertOk();

        $get = $this->actingAs($user)->getJson('/api/painel/kanban/coluna-config/lead_novo');
        $get->assertJsonPath('button_settings.0.target', $urlLonga);
    }

    public function test_rejeita_action_fora_do_enum_permitido(): void
    {
        $tenant = Tenant::factory()->create();
        $user   = User::factory()->create(['tenant_id' => $tenant->id, 'perfil' => 'dono']);

        $response = $this->actingAs($user)->putJson('/api/painel/kanban/coluna-config/lead_novo', [
            'button_settings' => [
                ['text' => 'Botao', 'action' => 'invalido', 'target' => null],
            ],
        ]);

        $response->assertStatus(422);
    }
}
