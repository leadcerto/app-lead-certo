<?php

namespace Tests\Feature;

use App\Jobs\SdrResponderJob;
use App\Models\Mensagem;
use App\Models\Tenant;
use App\Models\TicketAtendimento;
use App\Models\WhatsappCanal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CovercutWebhookMidiaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        Bus::fake();
        config([
            'services.covercut.base_url'   => 'https://fake-covercut.test/api/v1',
            'services.covercut.api_key'    => 'fake-key',
            'services.covercut.api_secret' => 'fake-secret',
        ]);
    }

    private function postComAssinatura(array $payload, string $segredo)
    {
        $body       = json_encode($payload);
        $assinatura = hash_hmac('sha256', $body, $segredo);

        return $this->call('POST', '/api/webhook/covercut', [], [], [], [
            'CONTENT_TYPE'         => 'application/json',
            'HTTP_X-BSP-Signature' => $assinatura,
        ], $body);
    }

    public function test_audio_recebido_e_transcrito_e_salvo_com_midia_url(): void
    {
        Http::fake([
            '*/media/get*' => Http::response('conteudo-binario-fake-audio', 200, ['Content-Type' => 'audio/ogg']),
        ]);

        $tenant = Tenant::factory()->create();
        WhatsappCanal::factory()->create([
            'tenant_id' => $tenant->id, 'tipo' => 'oficial', 'provider' => 'covercut',
            'config' => ['phone_number_id' => '950147584848138', 'webhook_secret' => 'segredo-abc'],
        ]);

        $payload = [
            'event' => 'message', 'direction' => 'inbound', 'from_number_id' => '950147584848138',
            'contact' => ['wa_id' => '5521988887777', 'name' => 'Sandro'],
            'message' => ['id' => 'wamid.audio1', 'type' => 'audio', 'audio' => ['id' => 'media-audio-1', 'mime_type' => 'audio/ogg']],
        ];

        $this->postComAssinatura($payload, 'segredo-abc')->assertOk();

        $mensagem = Mensagem::where('provider_message_id', 'wamid.audio1')->first();
        $this->assertNotNull($mensagem, 'Mensagem de áudio deveria ter sido criada');
        $this->assertSame('audio', $mensagem->tipo);
        $this->assertNotNull($mensagem->midia_url);
        $this->assertStringContainsString('/storage/kanban-midia/', $mensagem->midia_url);
        $this->assertNotEmpty(Storage::disk('public')->allFiles('kanban-midia'));

        Http::assertSent(fn ($request) =>
            str_contains($request->url(), '/media/get')
            && $request['id'] === 'media-audio-1'
            && $request['from'] === '950147584848138'
            && $request['mode'] === 'stream'
        );
    }

    public function test_audio_sem_id_no_payload_e_tratado_sem_quebrar(): void
    {
        Http::fake(); // não deveria ser chamado

        $tenant = Tenant::factory()->create();
        WhatsappCanal::factory()->create([
            'tenant_id' => $tenant->id, 'tipo' => 'oficial', 'provider' => 'covercut',
            'config' => ['phone_number_id' => '950147584848138', 'webhook_secret' => 'segredo-abc'],
        ]);

        $payload = [
            'event' => 'message', 'direction' => 'inbound', 'from_number_id' => '950147584848138',
            'contact' => ['wa_id' => '5521988887777'],
            'message' => ['id' => 'wamid.audio2', 'type' => 'audio'], // sem 'audio' => ['id' => ...]
        ];

        $response = $this->postComAssinatura($payload, 'segredo-abc');

        $response->assertOk();
        $mensagem = Mensagem::where('provider_message_id', 'wamid.audio2')->first();
        $this->assertNotNull($mensagem);
        $this->assertSame('[Áudio recebido — não foi possível identificar o arquivo]', $mensagem->conteudo);
        Http::assertNothingSent();
    }

    public function test_download_da_covercut_falha_nao_quebra_o_webhook(): void
    {
        Http::fake(['*/media/get*' => Http::response('erro interno', 500)]);

        $tenant = Tenant::factory()->create();
        WhatsappCanal::factory()->create([
            'tenant_id' => $tenant->id, 'tipo' => 'oficial', 'provider' => 'covercut',
            'config' => ['phone_number_id' => '950147584848138', 'webhook_secret' => 'segredo-abc'],
        ]);

        $payload = [
            'event' => 'message', 'direction' => 'inbound', 'from_number_id' => '950147584848138',
            'contact' => ['wa_id' => '5521988887777'],
            'message' => ['id' => 'wamid.audio3', 'type' => 'audio', 'audio' => ['id' => 'media-x']],
        ];

        $response = $this->postComAssinatura($payload, 'segredo-abc');

        $response->assertOk();
        $mensagem = Mensagem::where('provider_message_id', 'wamid.audio3')->first();
        $this->assertNotNull($mensagem);
        $this->assertSame('[Áudio recebido — não foi possível transcrever]', $mensagem->conteudo);
        $this->assertNull($mensagem->midia_url);
    }
}
