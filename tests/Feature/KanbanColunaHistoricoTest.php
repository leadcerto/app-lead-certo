<?php

namespace Tests\Feature;

use App\Models\Contato;
use App\Models\KanbanColunaHistorico;
use App\Models\Tenant;
use App\Models\TicketAtendimento;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class KanbanColunaHistoricoTest extends TestCase
{
    use RefreshDatabase;

    public function test_criar_ticket_registra_entrada_na_coluna_inicial(): void
    {
        $tenant  = Tenant::factory()->create();
        $contato = Contato::factory()->create();

        $ticket = TicketAtendimento::create([
            'tenant_id' => $tenant->id, 'contato_id' => $contato->id,
            'coluna_kanban' => 'lead_novo', 'agente_responsavel' => 'bot',
            'status' => 'aberto', 'aberto_em' => now(),
        ]);

        $this->assertDatabaseHas('kanban_coluna_historico', [
            'tenant_id'       => $tenant->id,
            'ticket_id'       => $ticket->id,
            'coluna'          => 'lead_novo',
            'coluna_anterior' => null,
        ]);
    }

    public function test_mudar_coluna_registra_nova_entrada_com_coluna_anterior(): void
    {
        $tenant  = Tenant::factory()->create();
        $contato = Contato::factory()->create();

        $ticket = TicketAtendimento::create([
            'tenant_id' => $tenant->id, 'contato_id' => $contato->id,
            'coluna_kanban' => 'lead_novo', 'agente_responsavel' => 'bot',
            'status' => 'aberto', 'aberto_em' => now(),
        ]);

        $ticket->update(['coluna_kanban' => 'em_atendimento']);

        $this->assertDatabaseHas('kanban_coluna_historico', [
            'tenant_id'       => $tenant->id,
            'ticket_id'       => $ticket->id,
            'coluna'          => 'em_atendimento',
            'coluna_anterior' => 'lead_novo',
        ]);

        $this->assertSame(2, KanbanColunaHistorico::where('ticket_id', $ticket->id)->count());
    }

    public function test_atualizar_ticket_sem_mudar_coluna_nao_registra_nada(): void
    {
        $tenant  = Tenant::factory()->create();
        $contato = Contato::factory()->create();

        $ticket = TicketAtendimento::create([
            'tenant_id' => $tenant->id, 'contato_id' => $contato->id,
            'coluna_kanban' => 'lead_novo', 'agente_responsavel' => 'bot',
            'status' => 'aberto', 'aberto_em' => now(),
        ]);

        $ticket->update(['agente_responsavel' => 'humano']);

        $this->assertSame(1, KanbanColunaHistorico::where('ticket_id', $ticket->id)->count());
    }
}
