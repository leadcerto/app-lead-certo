<?php

namespace Tests\Feature;

use App\Jobs\SequenciaMensagemJob;
use App\Models\Contato;
use App\Models\Sequencia;
use App\Models\SequenciaMensagem;
use App\Models\Tenant;
use App\Models\TicketAtendimento;
use App\Models\WhatsappCanal;
use App\Services\OpenRouterService;
use App\Services\TicketReaberturaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Achado real 2026-09-21 (ticket #4827, Carlos): mesmo gap encontrado nos
 * outros caminhos automáticos de mudança de coluna — reabrir um ticket
 * encerrado nunca disparava a Sequência de Mensagens/Automação da coluna
 * pra onde ele voltou.
 */
class TicketReaberturaServiceDisparaSequenciaTest extends TestCase
{
    use RefreshDatabase;

    public function test_reabertura_dispara_sequencia_configurada_na_coluna_restaurada(): void
    {
        Queue::fake();
        $tenant  = Tenant::factory()->create();
        $contato = Contato::factory()->create();
        $canal   = WhatsappCanal::factory()->create(['tenant_id' => $tenant->id]);

        $sequencia = Sequencia::create([
            'tenant_id' => $tenant->id, 'nome' => 'Retomada',
            'coluna_kanban' => 'aguardando_orcamento', 'ativo' => true,
        ]);
        SequenciaMensagem::create([
            'tenant_id' => $tenant->id, 'sequencia_id' => $sequencia->id, 'ordem' => 1,
            'conteudo' => 'Vi que você voltou! Continuando seu orçamento...', 'delay_segundos' => 0,
            'ativo' => true, 'obrigatorio' => true,
        ]);

        $ticket = TicketAtendimento::create([
            'tenant_id' => $tenant->id, 'contato_id' => $contato->id,
            'coluna_kanban' => 'encerrado', 'coluna_antes_encerrar' => 'aguardando_orcamento',
            'agente_responsavel' => 'humano', 'status' => 'encerrado', 'aberto_em' => now(),
        ]);

        $this->mock(OpenRouterService::class, function ($mock) {
            $mock->shouldReceive('chat')->once()->andReturn('REABRIR');
        });

        $reativou = app(TicketReaberturaService::class)->reabrirSeNecessario($ticket, $canal->id, 'Quero saber mais sobre o orçamento');

        $this->assertTrue($reativou);
        $this->assertSame('aguardando_orcamento', $ticket->fresh()->coluna_kanban);
        Queue::assertPushed(SequenciaMensagemJob::class, fn ($job) => $job->ticketId === $ticket->id);
    }

    /**
     * Achado real 2026-09-22: deveReabrir() não tinha como receber tenantId
     * — chat() sem tenantId nunca resolve o agente IA customizado do tenant
     * nem grava ia_usages vinculado ao tenant certo.
     */
    public function test_deveReabrir_repassa_tenant_id_do_ticket_pro_chat(): void
    {
        Queue::fake();
        $tenant  = Tenant::factory()->create();
        $contato = Contato::factory()->create();
        $canal   = WhatsappCanal::factory()->create(['tenant_id' => $tenant->id]);

        $ticket = TicketAtendimento::create([
            'tenant_id' => $tenant->id, 'contato_id' => $contato->id,
            'coluna_kanban' => 'encerrado', 'coluna_antes_encerrar' => 'em_atendimento',
            'agente_responsavel' => 'humano', 'status' => 'encerrado', 'aberto_em' => now(),
        ]);

        $tenantIdRecebido = null;

        $this->mock(OpenRouterService::class, function ($mock) use (&$tenantIdRecebido) {
            $mock->shouldReceive('chat')
                ->once()
                ->withArgs(function ($messages, $tier, $maxTokens, $origem, $tenantId) use (&$tenantIdRecebido) {
                    $tenantIdRecebido = $tenantId;
                    return $origem === 'reabertura_ticket_encerrado';
                })
                ->andReturn('REABRIR');
        });

        app(TicketReaberturaService::class)->reabrirSeNecessario($ticket, $canal->id, 'Ainda tenho uma dúvida sobre o serviço');

        $this->assertSame($tenant->id, $tenantIdRecebido);
    }
}
