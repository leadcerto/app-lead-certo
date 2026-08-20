<?php

namespace Tests\Feature;

use App\Models\Kanban;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WhatsappCanal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class KanbanCanalControllerTest extends TestCase
{
    use RefreshDatabase;

    private function usuarioDono(Tenant $tenant): User
    {
        return User::factory()->create(['tenant_id' => $tenant->id, 'perfil' => 'dono', 'ativo' => true]);
    }

    public function test_lista_canais_do_tenant_com_flag_vinculado(): void
    {
        $tenant = Tenant::factory()->create();
        $user   = $this->usuarioDono($tenant);
        $kanban = Kanban::where('tenant_id', $tenant->id)->where('tipo', 'vendas')->firstOrFail();
        $canal  = WhatsappCanal::factory()->create(['tenant_id' => $tenant->id]);
        $kanban->canais()->attach($canal->id);
        WhatsappCanal::factory()->create(['tenant_id' => $tenant->id]); // não vinculado

        $response = $this->actingAs($user)->getJson('/api/painel/kanban/canais');

        $response->assertOk();
        $this->assertCount(2, $response->json());
        $this->assertTrue(collect($response->json())->firstWhere('id', $canal->id)['vinculado']);
    }

    public function test_lista_inclui_o_app_de_cada_canal(): void
    {
        // Achado real 2026-08-20: sem 'app' na resposta, a tela não conseguia
        // distinguir WhatsApp Business de WhatsApp Messenger, mostrava "Não-
        // Oficial" genérico pros dois — mesma confusão que a tela de
        // Configurações já tinha corrigido antes.
        $tenant = Tenant::factory()->create();
        $user   = $this->usuarioDono($tenant);
        WhatsappCanal::factory()->create(['tenant_id' => $tenant->id, 'app' => 'messenger']);

        $response = $this->actingAs($user)->getJson('/api/painel/kanban/canais');

        $response->assertOk();
        $this->assertSame('messenger', $response->json('0.app'));
    }

    public function test_sincroniza_canais_vinculados(): void
    {
        $tenant = Tenant::factory()->create();
        $user   = $this->usuarioDono($tenant);
        $canalA = WhatsappCanal::factory()->create(['tenant_id' => $tenant->id]);
        $canalB = WhatsappCanal::factory()->create(['tenant_id' => $tenant->id]);

        $response = $this->actingAs($user)->putJson('/api/painel/kanban/canais', [
            'canal_ids' => [$canalA->id],
        ]);

        $response->assertOk();
        $kanban = Kanban::where('tenant_id', $tenant->id)->where('tipo', 'vendas')->firstOrFail();
        $this->assertCount(1, $kanban->canais);
        $this->assertSame($canalA->id, $kanban->canais->first()->id);
    }

    public function test_nao_vincula_canal_de_outro_tenant(): void
    {
        $tenant       = Tenant::factory()->create();
        $user         = $this->usuarioDono($tenant);
        $canalDeOutro = WhatsappCanal::factory()->create(['tenant_id' => Tenant::factory()->create()->id]);

        $response = $this->actingAs($user)->putJson('/api/painel/kanban/canais', [
            'canal_ids' => [$canalDeOutro->id],
        ]);

        $response->assertOk();
        $kanban = Kanban::where('tenant_id', $tenant->id)->where('tipo', 'vendas')->firstOrFail();
        $this->assertCount(0, $kanban->canais);
    }
}
