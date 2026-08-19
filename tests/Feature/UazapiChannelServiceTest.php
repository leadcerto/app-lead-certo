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

    public function test_envia_imagem_via_uazapi_usando_token_do_canal(): void
    {
        Http::fake(['*/send/media' => Http::response(['id' => 'abc'], 200)]);

        $tenant = Tenant::factory()->create();
        $canal  = WhatsappCanal::factory()->create([
            'tenant_id' => $tenant->id, 'tipo' => 'nao_oficial', 'provider' => 'uazapi',
            'config' => ['instance_token' => 'token-canal-uazapi'],
        ]);

        $enviado = app(UazapiChannelService::class)->enviarImagem($canal, '5511999999999', 'https://exemplo.com/foto.jpg', 'legenda');

        $this->assertTrue($enviado);
        Http::assertSent(fn ($request) => $request->hasHeader('token', 'token-canal-uazapi') && $request['type'] === 'image');
    }

    public function test_envia_audio_via_uazapi_usando_token_do_canal(): void
    {
        Http::fake(['*/send/media' => Http::response(['id' => 'abc'], 200)]);

        $tenant = Tenant::factory()->create();
        $canal  = WhatsappCanal::factory()->create([
            'tenant_id' => $tenant->id, 'tipo' => 'nao_oficial', 'provider' => 'uazapi',
            'config' => ['instance_token' => 'token-canal-uazapi'],
        ]);

        $enviado = app(UazapiChannelService::class)->enviarAudio($canal, '5511999999999', 'https://exemplo.com/audio.ogg');

        $this->assertTrue($enviado);
        Http::assertSent(fn ($request) => $request['type'] === 'ptt');
    }

    public function test_envia_documento_via_uazapi_usando_token_do_canal(): void
    {
        Http::fake(['*/send/media' => Http::response(['id' => 'abc'], 200)]);

        $tenant = Tenant::factory()->create();
        $canal  = WhatsappCanal::factory()->create([
            'tenant_id' => $tenant->id, 'tipo' => 'nao_oficial', 'provider' => 'uazapi',
            'config' => ['instance_token' => 'token-canal-uazapi'],
        ]);

        $enviado = app(UazapiChannelService::class)->enviarDocumento($canal, '5511999999999', 'https://exemplo.com/arquivo.pdf', 'arquivo.pdf');

        $this->assertTrue($enviado);
        Http::assertSent(fn ($request) => $request['type'] === 'document' && $request['docName'] === 'arquivo.pdf');
    }

    public function test_envia_sticker_via_uazapi_usando_token_do_canal(): void
    {
        Http::fake(['*/send/media' => Http::response(['id' => 'abc'], 200)]);

        $tenant = Tenant::factory()->create();
        $canal  = WhatsappCanal::factory()->create([
            'tenant_id' => $tenant->id, 'tipo' => 'nao_oficial', 'provider' => 'uazapi',
            'config' => ['instance_token' => 'token-canal-uazapi'],
        ]);

        $enviado = app(UazapiChannelService::class)->enviarSticker($canal, '5511999999999', 'https://exemplo.com/fig.webp');

        $this->assertTrue($enviado);
        Http::assertSent(fn ($request) => $request['type'] === 'sticker');
    }

    public function test_enviar_imagem_retorna_false_quando_canal_sem_token(): void
    {
        $tenant = Tenant::factory()->create();
        $canal  = WhatsappCanal::factory()->create(['tenant_id' => $tenant->id, 'config' => []]);

        $enviado = app(UazapiChannelService::class)->enviarImagem($canal, '5511999999999', 'https://exemplo.com/foto.jpg');

        $this->assertFalse($enviado);
    }

    // ─── Aquecimento — achado 2026-08-19: todo envio não-oficial passa por aqui ──

    public function test_bloqueia_envio_de_texto_quando_canal_em_dia_zero_de_aquecimento(): void
    {
        Http::fake(['*/send/text' => Http::response(['id' => 'abc'], 200)]);

        $tenant = Tenant::factory()->create();
        $canal  = WhatsappCanal::factory()->create([
            'tenant_id' => $tenant->id, 'tipo' => 'nao_oficial', 'provider' => 'uazapi',
            'config' => ['instance_token' => 'token-canal-uazapi'],
            'aquecimento_iniciado_em' => now(), // dia zero — limite frio é 0
        ]);

        $enviado = app(UazapiChannelService::class)->enviarTexto($canal, '5511999999999', 'Oi!');

        $this->assertFalse($enviado);
        Http::assertNotSent(fn ($request) => true);
    }

    public function test_enviar_texto_direto_tambem_respeita_o_limite_de_aquecimento(): void
    {
        // enviarTextoDireto() é o caminho da resposta manual no Kanban, pula o
        // HumanizacaoService — mas o WhatsApp não distingue a origem do envio,
        // então o teto de aquecimento tem que valer aqui também.
        Http::fake(['*/send/text' => Http::response(['id' => 'abc'], 200)]);

        $tenant = Tenant::factory()->create();
        $canal  = WhatsappCanal::factory()->create([
            'tenant_id' => $tenant->id, 'tipo' => 'nao_oficial', 'provider' => 'uazapi',
            'config' => ['instance_token' => 'token-canal-uazapi'],
            'aquecimento_iniciado_em' => now(),
        ]);

        $enviado = app(UazapiChannelService::class)->enviarTextoDireto($canal, '5511999999999', 'Oi!');

        $this->assertFalse($enviado);
    }

    public function test_envio_bem_sucedido_registra_no_contador_de_aquecimento(): void
    {
        Http::fake(['*/send/text' => Http::response(['id' => 'abc'], 200)]);

        $tenant = Tenant::factory()->create();
        // Canal padrão da factory já está "aquecido" (30 dias) — envio deve passar.
        $canal  = WhatsappCanal::factory()->create([
            'tenant_id' => $tenant->id, 'tipo' => 'nao_oficial', 'provider' => 'uazapi',
            'config' => ['instance_token' => 'token-canal-uazapi'],
        ]);

        app(UazapiChannelService::class)->enviarTexto($canal, '5511999999999', 'Oi!');

        $this->assertDatabaseHas('whatsapp_envios_diarios', ['whatsapp_canal_id' => $canal->id, 'contador_frio' => 1]);
    }
}
