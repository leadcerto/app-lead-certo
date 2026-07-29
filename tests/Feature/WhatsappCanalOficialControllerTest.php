<?php

namespace Tests\Feature;

use App\Models\Kanban;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WhatsappCanal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WhatsappCanalOficialControllerTest extends TestCase
{
    use RefreshDatabase;

    private function usuarioDono(Tenant $tenant): User
    {
        return User::factory()->create(['tenant_id' => $tenant->id, 'perfil' => 'dono', 'ativo' => true]);
    }

    public function test_adota_numero_oficial_e_registra_webhook_na_covercut(): void
    {
        Http::fake([
            '*/numbers/webhook' => Http::response(['webhook_secret' => 'segredo-gerado'], 200),
        ]);

        $tenant = Tenant::factory()->create();
        $user   = $this->usuarioDono($tenant);
        $kanban = Kanban::where('tenant_id', $tenant->id)->where('tipo', 'vendas')->firstOrFail();

        $response = $this->actingAs($user)->postJson('/api/painel/whatsapp/canais-oficiais', [
            'phone_number_id' => '123456789',
            'telefone'        => '5521981813106',
            'apelido'         => 'Principal',
        ]);

        $response->assertCreated();

        $canal = WhatsappCanal::where('tenant_id', $tenant->id)->where('tipo', 'oficial')->firstOrFail();
        $this->assertSame('covercut', $canal->provider);
        $this->assertSame('123456789', $canal->config['phone_number_id']);
        $this->assertSame('segredo-gerado', $canal->config['webhook_secret']);
        $this->assertTrue($kanban->canais->contains($canal));

        Http::assertSent(fn ($request) =>
            $request['from'] === '123456789' &&
            str_contains($request['webhook_url'], '/api/webhook/covercut')
        );
    }

    public function test_nao_adota_o_mesmo_numero_duas_vezes(): void
    {
        Http::fake(['*/numbers/webhook' => Http::response(['webhook_secret' => 'x'], 200)]);

        $tenant = Tenant::factory()->create();
        $user   = $this->usuarioDono($tenant);
        WhatsappCanal::factory()->create([
            'tenant_id' => $tenant->id, 'tipo' => 'oficial', 'provider' => 'covercut',
            'config' => ['phone_number_id' => '123456789'],
        ]);

        $response = $this->actingAs($user)->postJson('/api/painel/whatsapp/canais-oficiais', [
            'phone_number_id' => '123456789',
            'telefone'        => '5521981813106',
        ]);

        $response->assertStatus(422);
    }

    public function test_vendedor_nao_acessa_rotas_de_canal_oficial(): void
    {
        $tenant = Tenant::factory()->create();
        $user   = User::factory()->create(['tenant_id' => $tenant->id, 'perfil' => 'vendedor', 'ativo' => true]);

        $response = $this->actingAs($user)->postJson('/api/painel/whatsapp/canais-oficiais', [
            'phone_number_id' => '1', 'telefone' => '5511999999999',
        ]);

        $response->assertForbidden();
    }

    public function test_remove_numero_oficial_e_desregistra_webhook(): void
    {
        Http::fake(['*/numbers/webhook' => Http::response(['ok' => true], 200)]);

        $tenant = Tenant::factory()->create();
        $user   = $this->usuarioDono($tenant);
        $canal  = WhatsappCanal::factory()->create([
            'tenant_id' => $tenant->id, 'tipo' => 'oficial', 'provider' => 'covercut',
            'config' => ['phone_number_id' => '999'],
        ]);

        $response = $this->actingAs($user)->deleteJson("/api/painel/whatsapp/canais-oficiais/{$canal->id}");

        $response->assertOk();
        $this->assertDatabaseMissing('whatsapp_canais', ['id' => $canal->id]);
        Http::assertSent(fn ($request) => $request['from'] === '999' && $request['action'] === 'delete');
    }
}
