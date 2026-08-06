<?php

namespace Tests\Feature;

use App\Models\KanbanColunaObjetivo;
use App\Models\Tenant;
use App\Models\TicketAtendimento;
use App\Models\Contato;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class KanbanColunaObjetivoModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_cria_objetivo_com_casts_corretos(): void
    {
        $tenant = Tenant::factory()->create();

        $objetivo = KanbanColunaObjetivo::create([
            'tenant_id'     => $tenant->id,
            'coluna_kanban' => 'em_atendimento',
            'texto'         => 'Endereço de origem confirmado',
            'ordem'         => 1,
            'ativo'         => true,
        ]);

        $this->assertTrue($objetivo->fresh()->ativo);
        $this->assertIsInt($objetivo->fresh()->ordem);
    }

    public function test_ticket_persiste_objetivos_cumpridos_como_array(): void
    {
        $tenant  = Tenant::factory()->create();
        $contato = Contato::factory()->create();

        $ticket = TicketAtendimento::create([
            'tenant_id' => $tenant->id, 'contato_id' => $contato->id,
            'coluna_kanban' => 'em_atendimento', 'agente_responsavel' => 'bot',
            'status' => 'aberto', 'aberto_em' => now(),
            'objetivos_cumpridos' => [1, 3],
        ]);

        $this->assertSame([1, 3], $ticket->fresh()->objetivos_cumpridos);
    }
}
