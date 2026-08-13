<?php

namespace Tests\Feature;

use App\Jobs\AvaliarObjetivosPorMensagemHumanaJob;
use App\Models\Contato;
use App\Models\KanbanColunaObjetivo;
use App\Models\Mensagem;
use App\Models\Tenant;
use App\Models\TicketAtendimento;
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
}
