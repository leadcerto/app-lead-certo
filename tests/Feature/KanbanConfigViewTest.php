<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class KanbanConfigViewTest extends TestCase
{
    use RefreshDatabase;

    public function test_dono_ve_o_card_de_canais_de_whatsapp_na_config_do_kanban(): void
    {
        $tenant = Tenant::factory()->create();
        $user   = User::factory()->create(['tenant_id' => $tenant->id, 'perfil' => 'dono', 'ativo' => true]);

        $response = $this->actingAs($user)->get('/kanban/config');

        $response->assertOk();
        $response->assertSee('Canais de WhatsApp');
        $response->assertSee('canaisDisponiveis', false);
        $response->assertSee('carregarCanais', false);
        $response->assertSee('toggleCanal', false);
    }
}
