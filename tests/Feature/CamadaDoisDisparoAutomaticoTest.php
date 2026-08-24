<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\TicketAtendimento;
use App\Models\WhatsappCanal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CamadaDoisDisparoAutomaticoTest extends TestCase
{
    use RefreshDatabase;

    public function test_ddi_divergente_dispara_a_escolha_de_idioma_por_botao(): void
    {
        Http::fake(['*' => Http::response(['ok' => true], 200)]);
        $tenant = Tenant::factory()->create(['locale' => 'pt-BR']);
        WhatsappCanal::factory()->create([
            'tenant_id' => $tenant->id, 'webhook_token' => 'wh-camada2-1', 'config' => ['instance_token' => 'inst-camada2-1'],
        ]);

        $this->postJson('/api/webhook/uazapi/wh-camada2-1', [
            'EventType' => 'messages',
            'message'   => [
                'fromMe' => false, 'isGroup' => false,
                'chatid' => '351912345678@s.whatsapp.net',
                'messageid' => 'msg-camada2-1',
                'text' => 'Olá!',
            ],
        ]);

        Http::assertSent(fn ($req) => str_contains($req->url(), 'menu') || str_contains($req->url(), 'button'));

        $ticket = TicketAtendimento::withoutGlobalScopes()->where('tenant_id', $tenant->id)->first();
        $this->assertTrue((bool) $ticket->idioma_aguardando_escolha);
    }

    public function test_ddi_que_bate_com_o_tenant_nao_dispara_a_escolha_de_idioma(): void
    {
        Http::fake(['*' => Http::response(['ok' => true], 200)]);
        $tenant = Tenant::factory()->create(['locale' => 'pt-BR']);
        WhatsappCanal::factory()->create([
            'tenant_id' => $tenant->id, 'webhook_token' => 'wh-camada2-2', 'config' => ['instance_token' => 'inst-camada2-2'],
        ]);

        $this->postJson('/api/webhook/uazapi/wh-camada2-2', [
            'EventType' => 'messages',
            'message'   => [
                'fromMe' => false, 'isGroup' => false,
                'chatid' => '5521987654321@s.whatsapp.net',
                'messageid' => 'msg-camada2-2',
                'text' => 'Oi!',
            ],
        ]);

        Http::assertNotSent(fn ($req) => str_contains($req->url(), '/send/menu'));

        $ticket = TicketAtendimento::withoutGlobalScopes()->where('tenant_id', $tenant->id)->first();
        $this->assertFalse((bool) $ticket->idioma_aguardando_escolha);
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

    public function test_covercut_ddi_divergente_dispara_a_escolha_de_idioma_por_texto_numerado(): void
    {
        Http::fake(['*' => Http::response(['ok' => true], 200)]);
        $tenant = Tenant::factory()->create(['locale' => 'pt-BR']);
        WhatsappCanal::factory()->create([
            'tenant_id' => $tenant->id, 'tipo' => 'oficial', 'provider' => 'covercut',
            'config' => ['phone_number_id' => '123456', 'webhook_secret' => 'segredo-camada2'],
        ]);

        $this->postComAssinatura([
            'event' => 'message', 'direction' => 'inbound', 'from_number_id' => '123456',
            'contact' => ['wa_id' => '351912345678', 'name' => 'Cliente'],
            'message' => ['id' => 'wamid.camada2-1', 'type' => 'text', 'text' => 'Olá!'],
        ], 'segredo-camada2');

        Http::assertSent(fn ($req) => str_contains($req->url(), 'messages/send')
            && str_contains($req['text']['body'] ?? '', 'Responda com o número'));

        $ticket = TicketAtendimento::withoutGlobalScopes()->where('tenant_id', $tenant->id)->first();
        $this->assertTrue((bool) $ticket->idioma_aguardando_escolha);
    }
}
