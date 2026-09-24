<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\WhatsappCanal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Achado do planejamento do canal WhatsApp Messenger próprio (23/09): o
 * comando filtrava por tipo='nao_oficial', que também vale pra qualquer
 * provider futuro não-oficial — sem checar provider='uazapi', tentaria
 * sincronizar agenda de um canal de outro provedor contra o endpoint real da
 * Uazapi, usando o token errado.
 */
class SincronizarContatosWhatsAppSoUazapiTest extends TestCase
{
    use RefreshDatabase;

    public function test_nao_processa_canal_nao_oficial_de_outro_provider(): void
    {
        Http::fake(['*/contacts' => Http::response([], 200)]);

        $tenant = Tenant::factory()->create();
        WhatsappCanal::factory()->create([
            'tenant_id' => $tenant->id,
            'tipo'      => 'nao_oficial',
            'provider'  => 'outro_provider_qualquer',
            'status'    => 'connected',
            'config'    => ['instance_token' => 'tok'],
        ]);

        $this->artisan('contatos:sincronizar-whatsapp')->assertExitCode(0);

        Http::assertNothingSent();
    }

    public function test_ainda_processa_canal_uazapi_normalmente(): void
    {
        Http::fake(['*/contacts' => Http::response([], 200)]);

        $tenant = Tenant::factory()->create();
        WhatsappCanal::factory()->create([
            'tenant_id' => $tenant->id,
            'tipo'      => 'nao_oficial',
            'provider'  => 'uazapi',
            'status'    => 'connected',
            'config'    => ['instance_token' => 'tok'],
        ]);

        $this->artisan('contatos:sincronizar-whatsapp')->assertExitCode(0);

        Http::assertSent(fn ($request) => str_contains($request->url(), '/contacts'));
    }
}
