<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\WhatsappCanal;
use App\Services\Canais\MessengerProprioChannelService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Fase 3 do plano do canal WhatsApp Messenger próprio (23/09) — espelha
 * UazapiChannelServiceTest.php de propósito, provando que a mesma trava de
 * aquecimento e o mesmo motor de humanização valem igual pro canal novo.
 */
class MessengerProprioChannelServiceTest extends TestCase
{
    use RefreshDatabase;

    private function canal(Tenant $tenant, array $overrides = []): WhatsappCanal
    {
        return WhatsappCanal::factory()->create(array_merge([
            'tenant_id' => $tenant->id, 'tipo' => 'nao_oficial', 'provider' => 'messenger_proprio',
            'config' => ['session_id' => 'tenant-1-principal'],
        ], $overrides));
    }

    public function test_envia_texto_via_messenger_proprio_usando_sessionid_do_canal(): void
    {
        Http::fake(['*/sessoes/tenant-1-principal/enviar/texto' => Http::response(['id' => 'abc'], 200)]);

        $tenant = Tenant::factory()->create();
        $canal  = $this->canal($tenant);

        $enviado = app(MessengerProprioChannelService::class)->enviarTexto($canal, '5511999999999', 'Oi!');

        $this->assertTrue($enviado);
        Http::assertSent(fn ($request) =>
            str_contains($request->url(), '/sessoes/tenant-1-principal/enviar/texto')
            && $request->hasHeader('X-Api-Key')
            && $request['numero'] === '5511999999999'
        );
    }

    public function test_retorna_false_quando_canal_sem_sessionid(): void
    {
        $tenant = Tenant::factory()->create();
        $canal  = WhatsappCanal::factory()->create([
            'tenant_id' => $tenant->id, 'provider' => 'messenger_proprio', 'config' => [],
        ]);

        $enviado = app(MessengerProprioChannelService::class)->enviarTexto($canal, '5511999999999', 'Oi!');

        $this->assertFalse($enviado);
    }

    public function test_whatsapp_canal_servico_resolve_messenger_proprio_channel_service_para_esse_provider(): void
    {
        $tenant = Tenant::factory()->create();
        $canal  = $this->canal($tenant);

        $this->assertInstanceOf(MessengerProprioChannelService::class, $canal->servico());
    }

    public function test_envia_imagem_via_messenger_proprio_usando_sessionid_do_canal(): void
    {
        Http::fake(['*/sessoes/tenant-1-principal/enviar/media' => Http::response(['id' => 'abc'], 200)]);

        $tenant = Tenant::factory()->create();
        $canal  = $this->canal($tenant);

        $enviado = app(MessengerProprioChannelService::class)->enviarImagem($canal, '5511999999999', 'https://exemplo.com/foto.jpg', 'legenda');

        $this->assertTrue($enviado);
        Http::assertSent(fn ($request) => $request['tipo'] === 'imagem' && $request['legenda'] === 'legenda');
    }

    public function test_envia_audio_via_messenger_proprio_usando_sessionid_do_canal(): void
    {
        Http::fake(['*/sessoes/tenant-1-principal/enviar/media' => Http::response(['id' => 'abc'], 200)]);

        $tenant = Tenant::factory()->create();
        $canal  = $this->canal($tenant);

        $enviado = app(MessengerProprioChannelService::class)->enviarAudio($canal, '5511999999999', 'https://exemplo.com/audio.ogg');

        $this->assertTrue($enviado);
        Http::assertSent(fn ($request) => $request['tipo'] === 'audio' && $request['ptt'] === true);
    }

    public function test_envia_documento_via_messenger_proprio_usando_sessionid_do_canal(): void
    {
        Http::fake(['*/sessoes/tenant-1-principal/enviar/media' => Http::response(['id' => 'abc'], 200)]);

        $tenant = Tenant::factory()->create();
        $canal  = $this->canal($tenant);

        $enviado = app(MessengerProprioChannelService::class)->enviarDocumento($canal, '5511999999999', 'https://exemplo.com/arquivo.pdf', 'arquivo.pdf');

        $this->assertTrue($enviado);
        Http::assertSent(fn ($request) => $request['tipo'] === 'documento' && $request['nomeArquivo'] === 'arquivo.pdf');
    }

    public function test_envia_sticker_via_messenger_proprio_usando_sessionid_do_canal(): void
    {
        Http::fake(['*/sessoes/tenant-1-principal/enviar/media' => Http::response(['id' => 'abc'], 200)]);

        $tenant = Tenant::factory()->create();
        $canal  = $this->canal($tenant);

        $enviado = app(MessengerProprioChannelService::class)->enviarSticker($canal, '5511999999999', 'https://exemplo.com/fig.webp');

        $this->assertTrue($enviado);
        Http::assertSent(fn ($request) => $request['tipo'] === 'sticker');
    }

    public function test_enviar_imagem_retorna_false_quando_canal_sem_sessionid(): void
    {
        $tenant = Tenant::factory()->create();
        $canal  = WhatsappCanal::factory()->create([
            'tenant_id' => $tenant->id, 'provider' => 'messenger_proprio', 'config' => [],
        ]);

        $enviado = app(MessengerProprioChannelService::class)->enviarImagem($canal, '5511999999999', 'https://exemplo.com/foto.jpg');

        $this->assertFalse($enviado);
    }

    // ─── Aquecimento — mesma trava do canal Uazapi, aqui reaproveitada ────────

    public function test_bloqueia_envio_de_texto_quando_canal_em_dia_zero_de_aquecimento(): void
    {
        Http::fake(['*/sessoes/tenant-1-principal/enviar/texto' => Http::response(['id' => 'abc'], 200)]);

        $tenant = Tenant::factory()->create();
        $canal  = $this->canal($tenant, ['aquecimento_iniciado_em' => now()]); // dia zero — limite frio é 0

        $enviado = app(MessengerProprioChannelService::class)->enviarTexto($canal, '5511999999999', 'Oi!');

        $this->assertFalse($enviado);
        Http::assertNotSent(fn ($request) => true);
    }

    public function test_enviar_texto_direto_tambem_respeita_o_limite_de_aquecimento(): void
    {
        Http::fake(['*/sessoes/tenant-1-principal/enviar/texto' => Http::response(['id' => 'abc'], 200)]);

        $tenant = Tenant::factory()->create();
        $canal  = $this->canal($tenant, ['aquecimento_iniciado_em' => now()]);

        $enviado = app(MessengerProprioChannelService::class)->enviarTextoDireto($canal, '5511999999999', 'Oi!');

        $this->assertFalse($enviado);
    }

    public function test_envio_bem_sucedido_registra_no_contador_de_aquecimento(): void
    {
        Http::fake(['*/sessoes/tenant-1-principal/enviar/texto' => Http::response(['id' => 'abc'], 200)]);

        $tenant = Tenant::factory()->create();
        // Canal padrão da factory já está "aquecido" (30 dias) — envio deve passar.
        $canal  = $this->canal($tenant);

        app(MessengerProprioChannelService::class)->enviarTexto($canal, '5511999999999', 'Oi!');

        $this->assertDatabaseHas('whatsapp_envios_diarios', ['whatsapp_canal_id' => $canal->id, 'contador_frio' => 1]);
    }
}
