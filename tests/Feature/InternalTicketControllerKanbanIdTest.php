<?php

namespace Tests\Feature;

use App\Models\Contato;
use App\Models\Kanban;
use App\Models\Tenant;
use App\Models\TicketAtendimento;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InternalTicketControllerKanbanIdTest extends TestCase
{
    use RefreshDatabase;

    public function test_endpoint_interno_cria_ticket_com_kanban_id(): void
    {
        config(['app.service_key' => 'chave-teste-service-key']);

        $tenant  = Tenant::factory()->create();
        $kanban  = Kanban::where('tenant_id', $tenant->id)->where('tipo', 'vendas')->firstOrFail();
        $contato = Contato::factory()->create();

        $response = $this->withHeaders(['X-Service-Key' => 'chave-teste-service-key'])
            ->postJson('/api/internal/ticket', [
                'tenant_id'  => $tenant->id,
                'contato_id' => $contato->id,
            ]);

        $response->assertOk();

        $ticket = TicketAtendimento::where('tenant_id', $tenant->id)->where('contato_id', $contato->id)->first();
        $this->assertNotNull($ticket);
        $this->assertSame($kanban->id, $ticket->kanban_id);
    }
}
