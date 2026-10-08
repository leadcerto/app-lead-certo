<?php

namespace Tests\Feature;

use App\Models\Contato;
use App\Models\Kanban;
use App\Models\Tenant;
use App\Models\TicketAtendimento;
use App\Models\WhatsappCanal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MessengerProprioWebhookKanbanIdTest extends TestCase
{
    use RefreshDatabase;

    public function test_mensagem_de_lead_novo_cria_ticket_com_kanban_id(): void
    {
        Http::fake(['*' => Http::response(['ok' => true], 200)]);

        $tenant = Tenant::factory()->create();
        $kanban = Kanban::where('tenant_id', $tenant->id)->where('tipo', 'vendas')->firstOrFail();
        WhatsappCanal::factory()->create([
            'tenant_id'     => $tenant->id,
            'provider'      => 'messenger_proprio',
            'webhook_token' => 'wh-messenger-kanban-id',
            'config'        => ['session_id' => 'sessao-1'],
        ]);

        $response = $this->postJson('/api/webhook/messenger-proprio/wh-messenger-kanban-id', [
            'tipo'      => 'mensagem',
            'sessionId' => 'sessao-1',
            'messageId' => 'msg-kanban-id',
            'fromMe'    => false,
            'numero'    => '5511955556666',
            'pushName'  => 'Lead Teste',
            'texto'     => 'Oi, quero fazer uma mudança',
            'timestamp' => now()->timestamp,
        ]);

        $response->assertOk();

        $contato = Contato::where('telefone', '5511955556666')->firstOrFail();
        $ticket  = TicketAtendimento::withoutGlobalScopes()->where('contato_id', $contato->id)->firstOrFail();

        $this->assertSame($kanban->id, $ticket->kanban_id);
    }
}
