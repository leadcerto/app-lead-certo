<?php

namespace Tests\Feature;

use App\Models\Contato;
use App\Models\Mensagem;
use App\Models\Tenant;
use App\Models\TicketAtendimento;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class UazapiWebhookMidiaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        config(['services.uazapi.base_url' => 'https://fake-uazapi.test']);
    }

    private function fakeDownloadDeMidia(string $mimetype): void
    {
        Http::fake([
            '*/message/download' => Http::response([
                'base64'   => base64_encode('conteudo-binario-fake'),
                'mimetype' => $mimetype,
            ], 200),
            '*' => Http::response(['ok' => true], 200),
        ]);
    }

    public function test_imagem_enviada_pelo_lead_e_salva_com_midia_url(): void
    {
        $this->fakeDownloadDeMidia('image/jpeg');

        Tenant::factory()->create(['uazapi_webhook_token' => 'wh-midia-1', 'uazapi_instance_token' => 'inst-1']);

        $this->postJson('/api/webhook/uazapi/wh-midia-1', [
            'EventType' => 'messages',
            'message'   => [
                'fromMe'      => false,
                'isGroup'     => false,
                'chatid'      => '5511933334444@s.whatsapp.net',
                'mediaType'   => 'image',
                'messageid'   => 'msg-1',
                'content'     => '{}',
            ],
        ]);

        $contato = Contato::where('telefone', '5511933334444')->first();
        $this->assertNotNull($contato);

        $mensagem = Mensagem::where('remetente', 'lead')->latest()->first();
        $this->assertNotNull($mensagem);
        $this->assertSame('imagem', $mensagem->tipo);
        $this->assertNotNull($mensagem->midia_url);
        $this->assertStringContainsString('/storage/kanban-midia/', $mensagem->midia_url);
        $this->assertNotEmpty(Storage::disk('public')->allFiles('kanban-midia'));
    }

    public function test_audio_enviado_pelo_whatsapp_web_sem_legenda_agora_e_salvo(): void
    {
        $this->fakeDownloadDeMidia('audio/ogg');

        $tenant  = Tenant::factory()->create(['uazapi_webhook_token' => 'wh-midia-2', 'uazapi_instance_token' => 'inst-2']);
        $contato = Contato::factory()->create(['telefone' => '5511944445555']);
        $ticket  = TicketAtendimento::create([
            'tenant_id' => $tenant->id, 'contato_id' => $contato->id,
            'coluna_kanban' => 'em_atendimento', 'agente_responsavel' => 'bot',
            'status' => 'aberto', 'aberto_em' => now(),
        ]);

        $this->postJson('/api/webhook/uazapi/wh-midia-2', [
            'EventType' => 'messages',
            'message'   => [
                'fromMe'      => true,
                'wasSentByApi' => false,
                'isGroup'     => false,
                'chatid'      => '5511944445555@s.whatsapp.net',
                'mediaType'   => 'audio',
                'messageid'   => 'msg-2',
                'content'     => '{}',
            ],
        ]);

        $mensagem = Mensagem::where('ticket_id', $ticket->id)->where('remetente', 'humano')->latest()->first();
        $this->assertNotNull($mensagem, 'A mensagem de áudio enviada via WhatsApp Web deveria ter sido salva');
        $this->assertSame('audio', $mensagem->tipo);
        $this->assertNotNull($mensagem->midia_url);
    }

    public function test_imagem_usa_url_direta_do_content_quando_download_do_uazapi_falha(): void
    {
        // Simula o cenário real de produção: os endpoints de download do Uazapi
        // retornam erro, então o fallback tem que extrair a URL direta do campo
        // `content` — que chega como array já decodificado, não como string JSON.
        Http::fake(['*' => Http::response('method not allowed', 405)]);

        Tenant::factory()->create(['uazapi_webhook_token' => 'wh-midia-3', 'uazapi_instance_token' => 'inst-3']);

        $this->postJson('/api/webhook/uazapi/wh-midia-3', [
            'EventType' => 'messages',
            'message'   => [
                'fromMe'    => false,
                'isGroup'   => false,
                'chatid'    => '5511955556666@s.whatsapp.net',
                'mediaType' => 'image',
                'messageid' => 'msg-3',
                'content'   => [
                    'URL'      => 'https://mmg.whatsapp.net/o1/v/t24/f2/m235/fake-image-url',
                    'mimetype' => 'image/jpeg',
                ],
            ],
        ]);

        $mensagem = Mensagem::where('remetente', 'lead')->latest()->first();
        $this->assertNotNull($mensagem);
        $this->assertSame('imagem', $mensagem->tipo);
        $this->assertSame('https://mmg.whatsapp.net/o1/v/t24/f2/m235/fake-image-url', $mensagem->midia_url);
    }

    public function test_webhook_duplicado_com_mesmo_messageid_nao_cria_segunda_mensagem(): void
    {
        Http::fake(['*' => Http::response('not found', 404)]);

        Tenant::factory()->create(['uazapi_webhook_token' => 'wh-midia-4', 'uazapi_instance_token' => 'inst-4']);

        $payload = [
            'EventType' => 'messages',
            'message'   => [
                'fromMe'    => false,
                'isGroup'   => false,
                'chatid'    => '5511966667777@s.whatsapp.net',
                'text'      => 'Oi, tudo bem?',
                'messageid' => 'msg-duplicado',
            ],
        ];

        // Uazapi reenvia o mesmo evento (mesmo messageid) mais de uma vez
        $this->postJson('/api/webhook/uazapi/wh-midia-4', $payload);
        $this->postJson('/api/webhook/uazapi/wh-midia-4', $payload);

        $this->assertSame(1, Mensagem::where('uazapi_message_id', 'msg-duplicado')->count());
    }

    public function test_album_placeholder_e_ignorado_e_nao_vira_mensagem(): void
    {
        Tenant::factory()->create(['uazapi_webhook_token' => 'wh-midia-5', 'uazapi_instance_token' => 'inst-5']);

        $this->postJson('/api/webhook/uazapi/wh-midia-5', [
            'EventType' => 'messages',
            'message'   => [
                'fromMe'    => false,
                'isGroup'   => false,
                'chatid'    => '5511977778888@s.whatsapp.net',
                'text'      => 'Album: 3 images',
                'messageid' => 'msg-album',
            ],
        ]);

        $this->assertSame(0, Mensagem::where('conteudo', 'Album: 3 images')->count());
    }
}
