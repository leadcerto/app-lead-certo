<?php

namespace Tests\Feature;

use App\Models\Contato;
use App\Models\Tenant;
use App\Models\TicketAtendimento;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TicketAtendimentoObjetivosResetTest extends TestCase
{
    use RefreshDatabase;

    private function criarTicket(array $extra = []): TicketAtendimento
    {
        $tenant  = Tenant::factory()->create();
        $contato = Contato::factory()->create();

        return TicketAtendimento::create(array_merge([
            'tenant_id' => $tenant->id, 'contato_id' => $contato->id,
            'coluna_kanban' => 'lead_novo', 'agente_responsavel' => 'bot',
            'status' => 'aberto', 'aberto_em' => now(),
        ], $extra));
    }

    public function test_update_direto_no_model_zera_objetivos_cumpridos_quando_coluna_muda(): void
    {
        $ticket = $this->criarTicket(['objetivos_cumpridos' => [10, 20]]);

        $ticket->update(['coluna_kanban' => 'em_atendimento']);

        $this->assertSame([], $ticket->fresh()->objetivos_cumpridos);
    }

    public function test_update_que_nao_muda_coluna_nao_mexe_em_objetivos_cumpridos(): void
    {
        $ticket = $this->criarTicket(['objetivos_cumpridos' => [10, 20]]);

        $ticket->update(['agente_responsavel' => 'humano']);

        $this->assertSame([10, 20], $ticket->fresh()->objetivos_cumpridos);
    }

    public function test_update_que_muda_coluna_e_define_objetivos_cumpridos_explicitamente_nao_e_sobrescrito(): void
    {
        $ticket = $this->criarTicket(['objetivos_cumpridos' => [10, 20]]);

        // Caller explicita o valor de objetivos_cumpridos na mesma chamada —
        // o hook do model não deve brigar com essa decisão.
        $ticket->update(['coluna_kanban' => 'em_atendimento', 'objetivos_cumpridos' => [99]]);

        $this->assertSame([99], $ticket->fresh()->objetivos_cumpridos);
    }
}
