<?php
// tests/Feature/TicketAtendimentoOrientacaoResetTest.php
namespace Tests\Feature;

use App\Models\Contato;
use App\Models\Tenant;
use App\Models\TicketAtendimento;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TicketAtendimentoOrientacaoResetTest extends TestCase
{
    use RefreshDatabase;

    public function test_campos_de_orientacao_sao_mass_assignable(): void
    {
        $tenant  = Tenant::factory()->create();
        $contato = Contato::factory()->create();

        $ticket = TicketAtendimento::create([
            'tenant_id' => $tenant->id, 'contato_id' => $contato->id,
            'coluna_kanban' => 'em_atendimento', 'agente_responsavel' => 'bot',
            'status' => 'aberto', 'aberto_em' => now(),
            'aguardando_orientacao_em' => now(), 'mensagem_espera_enviada' => true,
        ]);

        $this->assertNotNull($ticket->fresh()->aguardando_orientacao_em);
        $this->assertTrue($ticket->fresh()->mensagem_espera_enviada);
    }

    public function test_mudar_de_coluna_zera_o_estado_de_orientacao(): void
    {
        $tenant  = Tenant::factory()->create();
        $contato = Contato::factory()->create();
        $ticket  = TicketAtendimento::create([
            'tenant_id' => $tenant->id, 'contato_id' => $contato->id,
            'coluna_kanban' => 'em_atendimento', 'agente_responsavel' => 'bot',
            'status' => 'aberto', 'aberto_em' => now(),
            'aguardando_orientacao_em' => now(), 'mensagem_espera_enviada' => true,
        ]);

        $ticket->update(['coluna_kanban' => 'aguardando_orcamento']);

        $ticketFresco = $ticket->fresh();
        $this->assertNull($ticketFresco->aguardando_orientacao_em);
        $this->assertFalse($ticketFresco->mensagem_espera_enviada);
    }

    public function test_atualizar_outro_campo_sem_mudar_coluna_nao_zera_orientacao(): void
    {
        $tenant  = Tenant::factory()->create();
        $contato = Contato::factory()->create();
        $ticket  = TicketAtendimento::create([
            'tenant_id' => $tenant->id, 'contato_id' => $contato->id,
            'coluna_kanban' => 'em_atendimento', 'agente_responsavel' => 'bot',
            'status' => 'aberto', 'aberto_em' => now(),
            'aguardando_orientacao_em' => now(), 'mensagem_espera_enviada' => true,
        ]);

        $ticket->update(['resumo_ia' => 'nota qualquer']);

        $ticketFresco = $ticket->fresh();
        $this->assertNotNull($ticketFresco->aguardando_orientacao_em);
        $this->assertTrue($ticketFresco->mensagem_espera_enviada);
    }
}
