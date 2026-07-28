<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use App\Models\WhatsappCanal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WhatsappCanalControllerTest extends TestCase
{
    use RefreshDatabase;

    private function usuarioDono(Tenant $tenant): User
    {
        return User::factory()->create(['tenant_id' => $tenant->id, 'perfil' => 'dono', 'ativo' => true]);
    }

    public function test_lista_apenas_canais_nao_oficiais_do_proprio_tenant(): void
    {
        $tenant = Tenant::factory()->create();
        $user   = $this->usuarioDono($tenant);
        WhatsappCanal::factory()->create(['tenant_id' => $tenant->id, 'tipo' => 'nao_oficial']);
        WhatsappCanal::factory()->create(['tenant_id' => $tenant->id, 'tipo' => 'oficial']);
        WhatsappCanal::factory()->create(['tenant_id' => Tenant::factory()->create()->id, 'tipo' => 'nao_oficial']);

        $response = $this->actingAs($user)->getJson('/api/painel/whatsapp/canais');

        $response->assertOk();
        $this->assertCount(1, $response->json());
    }

    public function test_cria_novo_canal_nao_oficial(): void
    {
        Http::fake([
            '*/instance/create' => Http::response([
                'token'    => 'novo-token',
                'instance' => ['id' => 1, 'name' => 'inst-1', 'status' => 'connecting'],
            ], 200),
            '*/webhook' => Http::response(['ok' => true], 200),
        ]);

        $tenant = Tenant::factory()->create();
        $user   = $this->usuarioDono($tenant);

        $response = $this->actingAs($user)->postJson('/api/painel/whatsapp/canais');

        $response->assertCreated();
        $this->assertDatabaseHas('whatsapp_canais', [
            'tenant_id' => $tenant->id, 'tipo' => 'nao_oficial', 'provider' => 'uazapi', 'status' => 'connecting',
        ]);
    }

    public function test_nao_acessa_canal_de_outro_tenant(): void
    {
        $tenant       = Tenant::factory()->create();
        $outroTenant  = Tenant::factory()->create();
        $user         = $this->usuarioDono($tenant);
        $canalDeOutro = WhatsappCanal::factory()->create(['tenant_id' => $outroTenant->id]);

        $response = $this->actingAs($user)->deleteJson("/api/painel/whatsapp/canais/{$canalDeOutro->id}");

        $response->assertNotFound();
    }
}
