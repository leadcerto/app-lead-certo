<?php

namespace Tests\Feature;

use App\Jobs\AvaliarObjetivosPorMensagemHumanaJob;
use App\Models\Contato;
use App\Models\KanbanColunaConfig;
use App\Models\KanbanColunaObjetivo;
use App\Models\Mensagem;
use App\Models\Tenant;
use App\Models\TicketAtendimento;
use App\Services\AvancoAutomaticoKanbanService;
use App\Services\OpenRouterService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AvaliarObjetivosPorMensagemHumanaJobTest extends TestCase
{
    use RefreshDatabase;

    private function criarTicketComMensagens(): TicketAtendimento
    {
        $tenant  = Tenant::factory()->create();
        $contato = Contato::factory()->create();
        $ticket  = TicketAtendimento::create([
            'tenant_id' => $tenant->id, 'contato_id' => $contato->id,
            'coluna_kanban' => 'em_atendimento', 'agente_responsavel' => 'humano',
            'status' => 'aberto', 'aberto_em' => now(),
        ]);

        Mensagem::create([
            'ticket_id' => $ticket->id, 'tenant_id' => $tenant->id,
            'remetente' => 'lead', 'tipo' => 'texto',
            'conteudo' => 'Preciso mudar de Valinhos SP pra Nova Iguaçu RJ',
            'enviado_em' => now()->subMinutes(5),
        ]);
        Mensagem::create([
            'ticket_id' => $ticket->id, 'tenant_id' => $tenant->id,
            'remetente' => 'humano', 'tipo' => 'texto',
            'conteudo' => 'Show, endereços anotados! E o que vamos transportar?',
            'enviado_em' => now(),
        ]);

        return $ticket;
    }

    public function test_marca_objetivo_identificado_pela_ia_na_conversa(): void
    {
        $ticket = $this->criarTicketComMensagens();
        $obj    = KanbanColunaObjetivo::create([
            'tenant_id' => $ticket->tenant_id, 'coluna_kanban' => 'em_atendimento',
            'texto' => 'Endereço de origem e destino', 'ordem' => 1, 'ativo' => true,
        ]);
        // Adicionar segundo objetivo para manter checklist incompleta e evitar avanço automático
        KanbanColunaObjetivo::create([
            'tenant_id' => $ticket->tenant_id, 'coluna_kanban' => 'em_atendimento',
            'texto' => 'Lista de itens confirmada', 'ordem' => 2, 'ativo' => true,
        ]);

        $this->mock(OpenRouterService::class, function ($mock) use ($obj) {
            $mock->shouldReceive('chat')->once()->andReturn((string) $obj->id);
        });

        (new AvaliarObjetivosPorMensagemHumanaJob($ticket->id))->handle(app(OpenRouterService::class), app(\App\Services\AvancoAutomaticoKanbanService::class));

        $this->assertSame([$obj->id], $ticket->fresh()->objetivos_cumpridos);
    }

    public function test_resposta_nenhum_nao_marca_nada(): void
    {
        $ticket = $this->criarTicketComMensagens();
        KanbanColunaObjetivo::create([
            'tenant_id' => $ticket->tenant_id, 'coluna_kanban' => 'em_atendimento',
            'texto' => 'Endereço de origem e destino', 'ordem' => 1, 'ativo' => true,
        ]);

        $this->mock(OpenRouterService::class, function ($mock) {
            $mock->shouldReceive('chat')->once()->andReturn('NENHUM');
        });

        (new AvaliarObjetivosPorMensagemHumanaJob($ticket->id))->handle(app(OpenRouterService::class), app(\App\Services\AvancoAutomaticoKanbanService::class));

        $this->assertSame([], $ticket->fresh()->objetivos_cumpridos ?? []);
    }

    public function test_sem_objetivo_pendente_nao_chama_a_ia(): void
    {
        $ticket = $this->criarTicketComMensagens();
        $obj    = KanbanColunaObjetivo::create([
            'tenant_id' => $ticket->tenant_id, 'coluna_kanban' => 'em_atendimento',
            'texto' => 'Endereço de origem e destino', 'ordem' => 1, 'ativo' => true,
        ]);
        $ticket->update(['objetivos_cumpridos' => [$obj->id]]); // já completo

        $this->mock(OpenRouterService::class, function ($mock) {
            $mock->shouldReceive('chat')->never();
        });

        (new AvaliarObjetivosPorMensagemHumanaJob($ticket->id))->handle(app(OpenRouterService::class), app(\App\Services\AvancoAutomaticoKanbanService::class));
    }

    public function test_falha_da_ia_nao_quebra_nem_marca_nada(): void
    {
        $ticket = $this->criarTicketComMensagens();
        KanbanColunaObjetivo::create([
            'tenant_id' => $ticket->tenant_id, 'coluna_kanban' => 'em_atendimento',
            'texto' => 'Endereço de origem e destino', 'ordem' => 1, 'ativo' => true,
        ]);

        $this->mock(OpenRouterService::class, function ($mock) {
            $mock->shouldReceive('chat')->once()->andReturn(null);
        });

        (new AvaliarObjetivosPorMensagemHumanaJob($ticket->id))->handle(app(OpenRouterService::class), app(\App\Services\AvancoAutomaticoKanbanService::class));

        $this->assertSame([], $ticket->fresh()->objetivos_cumpridos ?? []);
    }

    /**
     * Achado real 2026-09-17, ticket #4791 (Tiago): o atendente mandou o
     * orçamento (imagem) enquanto o ticket ainda estava em "em_atendimento".
     * Essa mesma mensagem cumpriu o objetivo de em_atendimento (avançando
     * pra aguardando_orcamento) e já continha o próprio orçamento, cumprindo
     * também o objetivo de aguardando_orcamento ("orçamento já foi postado")
     * — mas como nenhuma mensagem humana NOVA chegou depois da mudança de
     * coluna, nada reavaliava o objetivo da coluna nova. O ticket ficou preso
     * em "aguardando_orcamento" por ~8 minutos até alguém arrastar o card
     * manualmente. O job precisa se redespachar quando a coluna muda, pra
     * reavaliar a checklist da coluna nova contra o mesmo histórico.
     */
    public function test_mensagem_que_cumpre_objetivo_da_coluna_atual_e_tambem_da_proxima_cascateia_avanco(): void
    {
        $tenant  = Tenant::factory()->create();
        $contato = Contato::factory()->create();
        $ticket  = TicketAtendimento::create([
            'tenant_id' => $tenant->id, 'contato_id' => $contato->id,
            'coluna_kanban' => 'em_atendimento', 'agente_responsavel' => 'humano',
            'status' => 'aberto', 'aberto_em' => now(),
        ]);

        $objEmAtendimento = KanbanColunaObjetivo::create([
            'tenant_id' => $tenant->id, 'coluna_kanban' => 'em_atendimento',
            'texto' => 'Endereços confirmados', 'ordem' => 1, 'ativo' => true,
        ]);
        $objAguardandoOrcamento = KanbanColunaObjetivo::create([
            'tenant_id' => $tenant->id, 'coluna_kanban' => 'aguardando_orcamento',
            'texto' => 'Orçamento já foi postado', 'ordem' => 1, 'ativo' => true,
        ]);
        KanbanColunaConfig::create(['tenant_id' => $tenant->id, 'coluna_kanban' => 'em_atendimento', 'ia_ativo' => true]);
        KanbanColunaConfig::create(['tenant_id' => $tenant->id, 'coluna_kanban' => 'aguardando_orcamento', 'ia_ativo' => true]);

        Mensagem::create([
            'ticket_id' => $ticket->id, 'tenant_id' => $tenant->id,
            'remetente' => 'humano', 'tipo' => 'texto',
            'conteudo' => '[Imagem: orçamento] Carro 3 baú, R$ 800',
            'enviado_em' => now(),
        ]);

        $this->mock(OpenRouterService::class, function ($mock) use ($objEmAtendimento, $objAguardandoOrcamento) {
            $mock->shouldReceive('chat')->twice()->andReturn(
                (string) $objEmAtendimento->id,
                (string) $objAguardandoOrcamento->id,
            );
        });

        (new AvaliarObjetivosPorMensagemHumanaJob($ticket->id))
            ->handle(app(OpenRouterService::class), app(AvancoAutomaticoKanbanService::class));

        $this->assertSame('aguardando_lead', $ticket->fresh()->coluna_kanban);
    }
}
