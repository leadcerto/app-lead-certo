<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\TicketAtendimento;
use App\Models\WhatsappCanal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CamadaUmDdiTest extends TestCase
{
    use RefreshDatabase;

    public function test_uazapi_marca_idioma_pais_ddi_e_idioma_lead_quando_bate_com_o_tenant(): void
    {
        Http::fake(['*' => Http::response(['ok' => true], 200)]);
        $tenant = Tenant::factory()->create(['locale' => 'pt-BR']);
        WhatsappCanal::factory()->create([
            'tenant_id' => $tenant->id, 'webhook_token' => 'wh-ddi-1', 'config' => ['instance_token' => 'inst-ddi-1'],
        ]);

        $this->postJson('/api/webhook/uazapi/wh-ddi-1', [
            'EventType' => 'messages',
            'message'   => [
                'fromMe' => false, 'isGroup' => false,
                'chatid' => '5521987654321@s.whatsapp.net',
                'messageid' => 'msg-ddi-1',
                'text' => 'Oi, tudo bem?',
            ],
        ]);

        $ticket = TicketAtendimento::withoutGlobalScopes()->where('tenant_id', $tenant->id)->first();
        $this->assertSame('pt-BR', $ticket->idioma_pais_ddi);
        $this->assertSame('pt', $ticket->idioma_lead);
        $this->assertSame('ddi', $ticket->idioma_origem);
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

    public function test_covercut_marca_idioma_pais_ddi_quando_bate_com_o_tenant(): void
    {
        $tenant = Tenant::factory()->create(['locale' => 'pt-BR']);
        WhatsappCanal::factory()->create([
            'tenant_id' => $tenant->id, 'tipo' => 'oficial', 'provider' => 'covercut',
            'config' => ['phone_number_id' => '123456', 'webhook_secret' => 'segredo-ddi'],
        ]);

        $this->postComAssinatura([
            'event' => 'message', 'direction' => 'inbound', 'from_number_id' => '123456',
            'contact' => ['wa_id' => '5521987654321', 'name' => 'Cliente'],
            'message' => ['id' => 'wamid.ddi-1', 'type' => 'text', 'text' => 'Oi!'],
        ], 'segredo-ddi');

        $ticket = TicketAtendimento::withoutGlobalScopes()->where('tenant_id', $tenant->id)->first();
        $this->assertSame('pt-BR', $ticket->idioma_pais_ddi);
    }
}
