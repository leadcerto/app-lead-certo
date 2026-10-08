<?php

namespace Tests\Feature;

use App\Models\Kanban;
use App\Models\Tenant;
use App\Models\TicketAtendimento;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class SecretariaEletronicaKanbanIdTest extends TestCase
{
    use RefreshDatabase;

    public function test_chamada_perdida_cria_ticket_com_kanban_id(): void
    {
        Queue::fake();

        $tenant = Tenant::factory()->create(['secretaria_token' => 'token-kanban-id', 'secretaria_envio_ativo' => true]);
        $kanban = Kanban::where('tenant_id', $tenant->id)->where('tipo', 'vendas')->firstOrFail();

        $this->postJson('/api/secretaria/token-kanban-id', [
            'numero_chamador'  => '11966665555',
            'duracao_segundos' => 0,
        ])->assertOk();

        $ticket = TicketAtendimento::where('tenant_id', $tenant->id)->latest()->first();
        $this->assertNotNull($ticket);
        $this->assertSame($kanban->id, $ticket->kanban_id);
    }
}
