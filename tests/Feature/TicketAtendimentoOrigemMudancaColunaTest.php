<?php
// tests/Feature/TicketAtendimentoOrigemMudancaColunaTest.php
namespace Tests\Feature;

use App\Models\Contato;
use App\Models\KanbanColunaHistorico;
use App\Models\Tenant;
use App\Models\TicketAtendimento;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TicketAtendimentoOrigemMudancaColunaTest extends TestCase
{
    use RefreshDatabase;

    private function criarTicket(Tenant $tenant, string $coluna = 'lead_novo'): TicketAtendimento
    {
        $contato = Contato::factory()->create();

        return TicketAtendimento::create([
            'tenant_id' => $tenant->id, 'contato_id' => $contato->id,
            'coluna_kanban' => $coluna, 'agente_responsavel' => 'bot',
            'status' => 'aberto', 'aberto_em' => now(),
        ]);
    }

    public function test_mudanca_de_coluna_sem_marcar_origem_grava_ia_por_padrao(): void
    {
        $tenant = Tenant::factory()->create();
        $ticket = $this->criarTicket($tenant);

        $ticket->update(['coluna_kanban' => 'em_atendimento']);

        $this->assertDatabaseHas('kanban_coluna_historico', [
            'ticket_id' => $ticket->id, 'coluna' => 'em_atendimento', 'origem' => 'ia',
        ]);
    }

    public function test_mudanca_de_coluna_com_propriedade_marcada_grava_humano(): void
    {
        $tenant = Tenant::factory()->create();
        $ticket = $this->criarTicket($tenant);

        $ticket->origemMudancaColuna = 'humano';
        $ticket->update(['coluna_kanban' => 'em_atendimento']);

        $this->assertDatabaseHas('kanban_coluna_historico', [
            'ticket_id' => $ticket->id, 'coluna' => 'em_atendimento', 'origem' => 'humano',
        ]);
    }

    public function test_criacao_inicial_do_ticket_nao_grava_origem(): void
    {
        $tenant = Tenant::factory()->create();
        $ticket = $this->criarTicket($tenant);

        $this->assertNull(
            KanbanColunaHistorico::where('ticket_id', $ticket->id)->whereNull('coluna_anterior')->value('origem')
        );
    }

    public function test_propriedade_transiente_nao_e_persistida_no_proprio_ticket(): void
    {
        $tenant = Tenant::factory()->create();
        $ticket = $this->criarTicket($tenant);

        $ticket->origemMudancaColuna = 'humano';
        $ticket->update(['coluna_kanban' => 'em_atendimento']);

        $this->assertArrayNotHasKey('origem_mudanca_coluna', $ticket->fresh()->getAttributes());
        $this->assertArrayNotHasKey('origemMudancaColuna', $ticket->fresh()->getAttributes());
    }

    public function test_ordem_de_retorna_a_ordem_correta_e_null_se_a_coluna_nao_existir(): void
    {
        $tenant = Tenant::factory()->create();

        $this->assertSame(1, \App\Models\KanbanColuna::ordemDe($tenant->id, 'lead_novo'));
        $this->assertSame(5, \App\Models\KanbanColuna::ordemDe($tenant->id, 'pagamento'));
        $this->assertNull(\App\Models\KanbanColuna::ordemDe($tenant->id, 'nao_existe'));
    }
}
