<?php

namespace Tests\Feature;

use App\Models\Kanban;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WhatsappCanal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
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

    /**
     * Achado Crítico 2 da revisão final: a checagem de duplicidade era escopada por
     * tenant_id, mas as credenciais da Covercut são globais — outro tenant podia
     * "adotar" o mesmo phone_number_id de outro franqueado, rotacionando o
     * webhook_secret na Covercut e derrubando (sequestrando) o canal original. A
     * checagem GLOBAL barra isso: nenhum canal é criado, e a Covercut nunca chega a
     * ser chamada de novo pra re-registrar o webhook.
     */
    public function test_outro_tenant_nao_consegue_adotar_numero_ja_conectado_por_outro_tenant(): void
    {
        Http::fake(['*/numbers/webhook' => Http::response(['webhook_secret' => 'x'], 200)]);

        $tenantDono = Tenant::factory()->create();
        WhatsappCanal::factory()->create([
            'tenant_id' => $tenantDono->id, 'tipo' => 'oficial', 'provider' => 'covercut',
            'config' => ['phone_number_id' => '123456789', 'webhook_secret' => 'segredo-original'],
        ]);

        $tenantInvasor = Tenant::factory()->create();
        $userInvasor    = $this->usuarioDono($tenantInvasor);

        $response = $this->actingAs($userInvasor)->postJson('/api/painel/whatsapp/canais-oficiais', [
            'phone_number_id' => '123456789',
            'telefone'        => '5521981813106',
        ]);

        $response->assertStatus(422);

        $this->assertSame(1, WhatsappCanal::withoutGlobalScopes()
            ->where('provider', 'covercut')
            ->whereJsonContains('config->phone_number_id', '123456789')
            ->count());
        $this->assertDatabaseMissing('whatsapp_canais', ['tenant_id' => $tenantInvasor->id]);
        Http::assertNothingSent();
    }

    /**
     * Achado Importante 4 da revisão final: se a Covercut responder 200 sem
     * webhook_secret no corpo, o canal era criado com segredo null e todo inbound
     * ia 401 pra sempre (canal morto, sem forma de recuperar). O fix barra ANTES de
     * criar a linha.
     */
    public function test_retorna_502_quando_covercut_responde_200_sem_webhook_secret(): void
    {
        Http::fake(['*/numbers/webhook' => Http::response(['ok' => true], 200)]);

        $tenant = Tenant::factory()->create();
        $user   = $this->usuarioDono($tenant);

        $response = $this->actingAs($user)->postJson('/api/painel/whatsapp/canais-oficiais', [
            'phone_number_id' => '123456789',
            'telefone'        => '5521981813106',
        ]);

        $response->assertStatus(502);
        $this->assertDatabaseMissing('whatsapp_canais', ['tenant_id' => $tenant->id, 'tipo' => 'oficial']);
    }

    public function test_retorna_502_quando_covercut_responde_erro(): void
    {
        Http::fake(['*/numbers/webhook' => Http::response(['message' => 'unauthorized'], 401)]);

        $tenant = Tenant::factory()->create();
        $user   = $this->usuarioDono($tenant);

        $response = $this->actingAs($user)->postJson('/api/painel/whatsapp/canais-oficiais', [
            'phone_number_id' => '123456789',
            'telefone'        => '5521981813106',
        ]);

        $response->assertStatus(502);
        $this->assertDatabaseMissing('whatsapp_canais', ['tenant_id' => $tenant->id, 'tipo' => 'oficial']);
    }

    public function test_retorna_502_quando_covercut_esta_fora_do_ar(): void
    {
        Http::fake(['*/numbers/webhook' => fn () => throw new ConnectionException('Connection timed out')]);

        $tenant = Tenant::factory()->create();
        $user   = $this->usuarioDono($tenant);

        $response = $this->actingAs($user)->postJson('/api/painel/whatsapp/canais-oficiais', [
            'phone_number_id' => '123456789',
            'telefone'        => '5521981813106',
        ]);

        $response->assertStatus(502);
        $this->assertDatabaseMissing('whatsapp_canais', ['tenant_id' => $tenant->id, 'tipo' => 'oficial']);
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

    public function test_remove_numero_local_mesmo_com_covercut_fora_do_ar(): void
    {
        Http::fake(['*/numbers/webhook' => fn () => throw new ConnectionException('Connection timed out')]);

        $tenant = Tenant::factory()->create();
        $user   = $this->usuarioDono($tenant);
        $canal  = WhatsappCanal::factory()->create([
            'tenant_id' => $tenant->id, 'tipo' => 'oficial', 'provider' => 'covercut',
            'config' => ['phone_number_id' => '999'],
        ]);

        $response = $this->actingAs($user)->deleteJson("/api/painel/whatsapp/canais-oficiais/{$canal->id}");

        $response->assertOk();
        $this->assertDatabaseMissing('whatsapp_canais', ['id' => $canal->id]);
    }
}
