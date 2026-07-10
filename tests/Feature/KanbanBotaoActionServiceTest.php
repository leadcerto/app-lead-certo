<?php

namespace Tests\Feature;

use App\Models\Contato;
use App\Models\KanbanColunaConfig;
use App\Models\Tenant;
use App\Models\TicketAtendimento;
use App\Models\VinculoContatoTenant;
use App\Services\KanbanBotaoActionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class KanbanBotaoActionServiceTest extends TestCase
{
    use RefreshDatabase;

    private function criarTicket(Tenant $tenant, string $coluna): TicketAtendimento
    {
        $contato = Contato::factory()->create();

        return TicketAtendimento::create([
            'tenant_id'          => $tenant->id,
            'contato_id'         => $contato->id,
            'coluna_kanban'      => $coluna,
            'agente_responsavel' => 'bot',
            'status'             => 'aberto',
            'aberto_em'          => now(),
        ]);
    }

    public function test_move_column_move_o_ticket(): void
    {
        $tenant = Tenant::factory()->create();
        KanbanColunaConfig::create([
            'tenant_id'       => $tenant->id,
            'coluna_kanban'   => 'aguardando_lead',
            'button_settings' => [
                ['text' => 'Falar com Humano', 'action' => 'move_column', 'target' => 'em_atendimento'],
            ],
        ]);
        $ticket = $this->criarTicket($tenant, 'aguardando_lead');

        $executou = app(KanbanBotaoActionService::class)->executar($ticket, 'move_column:0');

        $this->assertTrue($executou);
        $this->assertSame('em_atendimento', $ticket->fresh()->coluna_kanban);
    }

    public function test_opt_out_marca_vinculo_como_bloqueado(): void
    {
        $tenant = Tenant::factory()->create();
        KanbanColunaConfig::create([
            'tenant_id'       => $tenant->id,
            'coluna_kanban'   => 'lead_novo',
            'button_settings' => [
                ['text' => 'Não tenho interesse', 'action' => 'opt_out', 'target' => null],
            ],
        ]);
        $ticket = $this->criarTicket($tenant, 'lead_novo');
        VinculoContatoTenant::create(['contato_id' => $ticket->contato_id, 'tenant_id' => $tenant->id]);

        $executou = app(KanbanBotaoActionService::class)->executar($ticket, 'move_column:0');

        // id não corresponde a nenhuma config -> não executa
        $this->assertFalse($executou);

        $executou = app(KanbanBotaoActionService::class)->executar($ticket, 'opt_out:0');
        $this->assertTrue($executou);

        $vinculo = VinculoContatoTenant::where('contato_id', $ticket->contato_id)
            ->where('tenant_id', $tenant->id)->first();
        $this->assertNotNull($vinculo->bloqueado_em);
    }

    public function test_botao_de_outra_coluna_nao_e_executado(): void
    {
        $tenant = Tenant::factory()->create();
        KanbanColunaConfig::create([
            'tenant_id'       => $tenant->id,
            'coluna_kanban'   => 'em_atendimento',
            'button_settings' => [
                ['text' => 'Encerrar', 'action' => 'move_column', 'target' => 'encerrado'],
            ],
        ]);
        // ticket está em outra coluna — a config de 'em_atendimento' não deve se aplicar aqui
        $ticket = $this->criarTicket($tenant, 'aguardando_lead');

        $executou = app(KanbanBotaoActionService::class)->executar($ticket, 'move_column:0');

        $this->assertFalse($executou);
        $this->assertSame('aguardando_lead', $ticket->fresh()->coluna_kanban);
    }
}
