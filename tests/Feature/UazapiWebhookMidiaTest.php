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
}
