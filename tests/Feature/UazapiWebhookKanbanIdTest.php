<?php

namespace Tests\Feature;

use App\Models\Kanban;
use App\Models\Tenant;
use App\Models\TicketAtendimento;
use App\Models\WhatsappCanal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class UazapiWebhookKanbanIdTest extends TestCase
{
    use RefreshDatabase;

    public function test_mensagem_nova_cria_ticket_com_kanban_id(): void
    {
        Http::fake(['*' => Http::response(['ok' => true], 200)]);

        $tenant = Tenant::factory()->create();
        $kanban = Kanban::where('tenant_id', $tenant->id)->where('tipo', 'vendas')->firstOrFail();
        WhatsappCanal::factory()->create([
            'tenant_id'     => $tenant->id,
            'webhook_token' => 'token-kanban-id-teste',
            'config'        => ['instance_token' => 'instance-kanban-id-teste'],
        ]);

        $response = $this->postJson('/api/webhook/uazapi/token-kanban-id-teste', [
            'EventType' => 'messages',
            'message'   => [
                'fromMe'  => false,
                'isGroup' => false,
                'chatid'  => '5511933334444@s.whatsapp.net',
                'text'    => 'Olá, quero um orçamento',
            ],
        ]);

        $response->assertOk();
        $ticket = TicketAtendimento::where('tenant_id', $tenant->id)->firstOrFail();
        $this->assertSame($kanban->id, $ticket->kanban_id);
    }
}
