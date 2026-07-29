<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\WhatsappCanal;
use App\Services\Canais\UazapiChannelService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class UazapiChannelServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_envia_texto_via_uazapi_usando_token_do_canal(): void
    {
        Http::fake(['*/send/text' => Http::response(['id' => 'abc'], 200)]);

        $tenant = Tenant::factory()->create();
        $canal  = WhatsappCanal::factory()->create([
            'tenant_id' => $tenant->id, 'tipo' => 'nao_oficial', 'provider' => 'uazapi',
            'config' => ['instance_token' => 'token-canal-uazapi'],
        ]);

        $enviado = app(UazapiChannelService::class)->enviarTexto($canal, '5511999999999', 'Oi!');

        $this->assertTrue($enviado);
        Http::assertSent(fn ($request) => $request->hasHeader('token', 'token-canal-uazapi'));
    }

    public function test_retorna_false_quando_canal_sem_token(): void
    {
        $tenant = Tenant::factory()->create();
        $canal  = WhatsappCanal::factory()->create(['tenant_id' => $tenant->id, 'config' => []]);

        $enviado = app(UazapiChannelService::class)->enviarTexto($canal, '5511999999999', 'Oi!');

        $this->assertFalse($enviado);
    }

    public function test_whatsapp_canal_servico_resolve_uazapi_channel_service_para_provider_uazapi(): void
    {
        $tenant = Tenant::factory()->create();
        $canal  = WhatsappCanal::factory()->create(['tenant_id' => $tenant->id, 'provider' => 'uazapi']);

        $this->assertInstanceOf(UazapiChannelService::class, $canal->servico());
    }
}
