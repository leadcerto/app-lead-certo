<?php

namespace Tests\Feature;

use App\Models\Kanban;
use App\Models\Tenant;
use App\Models\TicketAtendimento;
use App\Models\WhatsappCanal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class UazapiWebhookChamadaWhatsAppKanbanIdTest extends TestCase
{
    use RefreshDatabase;

    public function test_chamada_perdida_cria_ticket_com_kanban_id(): void
    {
        Http::fake(['*' => Http::response(['ok' => true], 200)]);

        $tenant = Tenant::factory()->create();
        $kanban = Kanban::where('tenant_id', $tenant->id)->where('tipo', 'vendas')->firstOrFail();
        WhatsappCanal::factory()->create([
            'tenant_id'     => $tenant->id,
            'webhook_token' => 'token-chamada-kanban-id',
            'config'        => ['instance_token' => 'instance-chamada-kanban-id'],
        ]);

        $response = $this->postJson('/api/webhook/uazapi/token-chamada-kanban-id', [
            'EventType' => 'messages',
            'message'   => [
                'fromMe'      => false,
                'isGroup'     => false,
                'chatid'      => '5511944443333@s.whatsapp.net',
                'messageType' => 'call_log',
            ],
        ]);

        $response->assertOk();

        $ticket = TicketAtendimento::withoutGlobalScopes()->where('tenant_id', $tenant->id)->first();
        $this->assertNotNull($ticket);
        $this->assertSame($kanban->id, $ticket->kanban_id);
    }
}
