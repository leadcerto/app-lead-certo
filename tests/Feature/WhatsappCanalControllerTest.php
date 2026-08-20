<?php

namespace Tests\Feature;

use App\Models\Kanban;
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

    private function usuarioVendedor(Tenant $tenant): User
    {
        return User::factory()->create(['tenant_id' => $tenant->id, 'perfil' => 'vendedor', 'ativo' => true]);
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
            'app' => 'business',
        ]);
    }

    // ─── app: WhatsApp Business vs WhatsApp Messenger (achado 2026-08-19) ──────

    public function test_filtra_canais_nao_oficiais_por_app(): void
    {
        $tenant = Tenant::factory()->create();
        $user   = $this->usuarioDono($tenant);
        WhatsappCanal::factory()->create(['tenant_id' => $tenant->id, 'tipo' => 'nao_oficial', 'app' => 'business']);
        WhatsappCanal::factory()->create(['tenant_id' => $tenant->id, 'tipo' => 'nao_oficial', 'app' => 'messenger']);

        $responseBusiness = $this->actingAs($user)->getJson('/api/painel/whatsapp/canais?app=business');
        $responseMessenger = $this->actingAs($user)->getJson('/api/painel/whatsapp/canais?app=messenger');

        $responseBusiness->assertOk();
        $this->assertCount(1, $responseBusiness->json());
        $this->assertSame('business', $responseBusiness->json('0.app'));

        $responseMessenger->assertOk();
        $this->assertCount(1, $responseMessenger->json());
        $this->assertSame('messenger', $responseMessenger->json('0.app'));
    }

    public function test_cria_canal_do_app_messenger_quando_informado(): void
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

        $response = $this->actingAs($user)->postJson('/api/painel/whatsapp/canais', ['app' => 'messenger']);

        $response->assertCreated();
        $this->assertDatabaseHas('whatsapp_canais', [
            'tenant_id' => $tenant->id, 'tipo' => 'nao_oficial', 'app' => 'messenger',
        ]);
    }

    public function test_sem_app_informado_assume_business_por_padrao(): void
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
        $this->assertDatabaseHas('whatsapp_canais', ['id' => $response->json('id'), 'app' => 'business']);
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

    public function test_canal_recem_criado_e_vinculado_a_todos_os_kanbans_do_tenant(): void
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
        $kanban = Kanban::where('tenant_id', $tenant->id)->where('tipo', 'vendas')->firstOrFail();

        $response = $this->actingAs($user)->postJson('/api/painel/whatsapp/canais');
        $response->assertCreated();

        $canalId = $response->json('id');
        $this->assertTrue($kanban->canais()->whereKey($canalId)->exists());
    }

    // ─── Exclusão: nunca deixar órfão na Uazapi (achado real 2026-08-20) ───────

    public function test_exclusao_bem_sucedida_remove_o_canal_local(): void
    {
        Http::fake(['*/instance' => Http::response(['ok' => true], 200)]);
        $tenant = Tenant::factory()->create();
        $user   = $this->usuarioDono($tenant);
        $canal  = WhatsappCanal::factory()->create(['tenant_id' => $tenant->id, 'config' => ['instance_token' => 'tok']]);

        $response = $this->actingAs($user)->deleteJson("/api/painel/whatsapp/canais/{$canal->id}");

        $response->assertOk();
        $this->assertDatabaseMissing('whatsapp_canais', ['id' => $canal->id]);
    }

    public function test_falha_ao_excluir_na_uazapi_mantem_o_canal_local(): void
    {
        // Achado real: registro local desaparecia mesmo quando a Uazapi falhava
        // em apagar a instância — o token se perdia e a instância ficava órfã na
        // conta, contando pro limite. Aconteceu 2x antes (test-buttons, tenant-1)
        // e travou a criação de canal novo (limite de instâncias esgotado).
        Http::fake(['*/instance' => Http::response(['error' => 'falhou'], 500)]);
        $tenant = Tenant::factory()->create();
        $user   = $this->usuarioDono($tenant);
        $canal  = WhatsappCanal::factory()->create(['tenant_id' => $tenant->id, 'config' => ['instance_token' => 'tok']]);

        $response = $this->actingAs($user)->deleteJson("/api/painel/whatsapp/canais/{$canal->id}");

        $response->assertStatus(500);
        $this->assertDatabaseHas('whatsapp_canais', ['id' => $canal->id]);
    }

    public function test_vendedor_nao_pode_excluir_canal(): void
    {
        $tenant    = Tenant::factory()->create();
        $vendedor  = $this->usuarioVendedor($tenant);
        $canal     = WhatsappCanal::factory()->create(['tenant_id' => $tenant->id]);

        $response = $this->actingAs($vendedor)->deleteJson("/api/painel/whatsapp/canais/{$canal->id}");

        $response->assertForbidden();
        $this->assertDatabaseHas('whatsapp_canais', ['id' => $canal->id]);
    }

    public function test_vendedor_nao_pode_criar_canal(): void
    {
        $tenant   = Tenant::factory()->create();
        $vendedor = $this->usuarioVendedor($tenant);

        $response = $this->actingAs($vendedor)->postJson('/api/painel/whatsapp/canais');

        $response->assertForbidden();
    }
}
