<?php

namespace Tests\Feature;

use App\Models\Contato;
use App\Models\Tenant;
use App\Models\TicketAtendimento;
use App\Models\VinculoContatoTenant;
use App\Services\KanbanBotaoActionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class KanbanBotaoActionServiceTest extends TestCase
{
    use RefreshDatabase;

    private function criarTicket(Tenant $tenant, string $coluna, array $botoesAtivos = []): TicketAtendimento
    {
        $contato = Contato::factory()->create();

        return TicketAtendimento::create([
            'tenant_id'          => $tenant->id,
            'contato_id'         => $contato->id,
            'coluna_kanban'      => $coluna,
            'agente_responsavel' => 'bot',
            'status'             => 'aberto',
            'aberto_em'          => now(),
            'botoes_ativos'      => $botoesAtivos,
        ]);
    }

    public function test_move_column_move_o_ticket(): void
    {
        $tenant = Tenant::factory()->create();
        $ticket = $this->criarTicket($tenant, 'aguardando_lead', [
            ['text' => 'Falar com Humano', 'action' => 'move_column', 'target' => 'em_atendimento'],
        ]);

        $executou = app(KanbanBotaoActionService::class)->executar($ticket, 'move_column:0');

        $this->assertTrue($executou);
        $this->assertSame('em_atendimento', $ticket->fresh()->coluna_kanban);
    }

    public function test_opt_out_marca_vinculo_como_bloqueado(): void
    {
        $tenant = Tenant::factory()->create();
        $ticket = $this->criarTicket($tenant, 'lead_novo', [
            ['text' => 'Não tenho interesse', 'action' => 'opt_out', 'target' => null],
        ]);
        VinculoContatoTenant::create(['contato_id' => $ticket->contato_id, 'tenant_id' => $tenant->id]);

        $executou = app(KanbanBotaoActionService::class)->executar($ticket, 'move_column:0');

        // action do índice 0 é opt_out, não move_column -> não executa
        $this->assertFalse($executou);

        $executou = app(KanbanBotaoActionService::class)->executar($ticket, 'opt_out:0');
        $this->assertTrue($executou);

        $vinculo = VinculoContatoTenant::where('contato_id', $ticket->contato_id)
            ->where('tenant_id', $tenant->id)->first();
        $this->assertNotNull($vinculo->bloqueado_em);
    }

    public function test_opt_out_cria_vinculo_bloqueado_quando_nao_existe_previamente(): void
    {
        $tenant = Tenant::factory()->create();
        $ticket = $this->criarTicket($tenant, 'lead_novo', [
            ['text' => 'Não tenho interesse', 'action' => 'opt_out', 'target' => null],
        ]);

        // Propositalmente NÃO criamos um VinculoContatoTenant prévio aqui —
        // esse é o cenário em que o update() silencioso falhava.
        $this->assertDatabaseMissing('vinculos_contato_tenant', [
            'contato_id' => $ticket->contato_id,
            'tenant_id'  => $tenant->id,
        ]);

        $executou = app(KanbanBotaoActionService::class)->executar($ticket, 'opt_out:0');
        $this->assertTrue($executou);

        $vinculo = VinculoContatoTenant::where('contato_id', $ticket->contato_id)
            ->where('tenant_id', $tenant->id)->first();
        $this->assertNotNull($vinculo, 'VinculoContatoTenant deveria ter sido criado mesmo sem existir previamente');
        $this->assertNotNull($vinculo->bloqueado_em);
    }

    public function test_indice_sem_botao_ativo_correspondente_nao_e_executado(): void
    {
        $tenant = Tenant::factory()->create();
        // Ticket sem nenhum botão ativo registrado (nunca recebeu um menu)
        $ticket = $this->criarTicket($tenant, 'aguardando_lead', []);

        $executou = app(KanbanBotaoActionService::class)->executar($ticket, 'move_column:0');

        $this->assertFalse($executou);
        $this->assertSame('aguardando_lead', $ticket->fresh()->coluna_kanban);
    }

    public function test_trigger_ia_marca_agente_responsavel_como_bot(): void
    {
        $tenant = Tenant::factory()->create();
        $ticket = $this->criarTicket($tenant, 'aguardando_orcamento', [
            ['text' => 'Continuar com IA', 'action' => 'trigger_ia', 'target' => null],
        ]);
        $ticket->update(['agente_responsavel' => 'humano']);

        $executou = app(KanbanBotaoActionService::class)->executar($ticket, 'trigger_ia:0');

        $this->assertTrue($executou);
        $this->assertSame('bot', $ticket->fresh()->agente_responsavel);
    }
}
