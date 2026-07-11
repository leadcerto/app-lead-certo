<?php

namespace Tests\Feature;

use App\Models\Contato;
use App\Models\Tenant;
use App\Models\TicketAtendimento;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class UazapiWebhookFollowupResetTest extends TestCase
{
    use RefreshDatabase;

    public function test_mensagem_do_lead_zera_o_estagio_de_followup(): void
    {
        Http::fake(['*' => Http::response(['ok' => true], 200)]);

        $tenant = Tenant::factory()->create([
            'uazapi_webhook_token'  => 'wh-token-followup',
            'uazapi_instance_token' => 'instance-token-followup',
        ]);
        $contato = Contato::factory()->create(['telefone' => '5511977776666']);
        $ticket = TicketAtendimento::create([
            'tenant_id' => $tenant->id, 'contato_id' => $contato->id,
            'coluna_kanban' => 'lead_novo', 'agente_responsavel' => 'bot',
            'status' => 'aberto', 'aberto_em' => now(),
            'followup_estagio_enviado' => 2,
        ]);

        $response = $this->postJson('/api/webhook/uazapi/wh-token-followup', [
            'EventType' => 'messages',
            'message'   => [
                'fromMe'  => false,
                'isGroup' => false,
                'chatid'  => '5511977776666@s.whatsapp.net',
                'text'    => 'Oi, desculpa a demora!',
            ],
        ]);

        $response->assertOk();
        $this->assertSame(0, $ticket->fresh()->followup_estagio_enviado);
    }
}
