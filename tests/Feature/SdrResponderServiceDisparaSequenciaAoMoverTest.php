<?php

namespace Tests\Feature;

use App\Jobs\SequenciaMensagemJob;
use App\Models\Contato;
use App\Models\KanbanColunaConfig;
use App\Models\Mensagem;
use App\Models\SdrPersona;
use App\Models\Sequencia;
use App\Models\SequenciaMensagem;
use App\Models\Tenant;
use App\Models\TicketAtendimento;
use App\Services\OpenRouterService;
use App\Services\SdrResponderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Achado real 2026-09-21 (ticket #4827, Carlos): a Sequência de
 * Mensagens/Automação configurada numa coluna só disparava quando um humano
 * movia o card manualmente (KanbanController). Quando a própria IA move o
 * ticket via token (aqui) ou o avanço automático por checklist completo
 * (AvancoAutomaticoKanbanService, testado em arquivo separado), a sequência
 * configurada pra coluna de destino nunca era despachada — o lead não
 * recebia as mensagens automáticas "envio obrigatório" daquela etapa.
 */
class SdrResponderServiceDisparaSequenciaAoMoverTest extends TestCase
{
    use RefreshDatabase;

    public function test_token_de_movimento_da_ia_dispara_sequencia_configurada_na_coluna_destino(): void
    {
        Http::fake(['*' => Http::response(['ok' => true], 200)]);
        Queue::fake();

        $tenant = Tenant::factory()->create(['uazapi_instance_token' => 'tok']);

        KanbanColunaConfig::create(['tenant_id' => $tenant->id, 'coluna_kanban' => 'em_atendimento']);

        $sequencia = Sequencia::create([
            'tenant_id' => $tenant->id, 'nome' => 'Atendimento Inicial',
            'coluna_kanban' => 'em_atendimento', 'ativo' => true,
        ]);
        SequenciaMensagem::create([
            'tenant_id' => $tenant->id, 'sequencia_id' => $sequencia->id, 'ordem' => 1,
            'conteudo' => 'Qual o endereço de origem e destino?', 'delay_segundos' => 0,
            'ativo' => true, 'obrigatorio' => true,
        ]);

        $persona = SdrPersona::create([
            'tenant_id' => $tenant->id, 'nome_interno' => 'padrao', 'nome_display' => 'Joao',
            'system_prompt' => 'Você é um atendente.', 'ativo' => true, 'is_default' => true, 'tier' => 'simples',
        ]);
        $contato = Contato::factory()->create(['nome' => 'Carlos', 'telefone' => '5521975580918']);
        $ticket  = TicketAtendimento::create([
            'tenant_id' => $tenant->id, 'contato_id' => $contato->id,
            'coluna_kanban' => 'lead_novo', 'agente_responsavel' => 'bot', 'status' => 'aberto',
            'aberto_em' => now(), 'sdr_persona_id' => $persona->id, 'etapa_ia' => 'etapa_1',
        ]);
        Mensagem::create([
            'ticket_id' => $ticket->id, 'tenant_id' => $tenant->id,
            'remetente' => 'lead', 'tipo' => 'texto', 'conteudo' => 'Carlos',
            'enviado_em' => now(),
        ]);

        $this->mock(OpenRouterService::class, function ($mock) {
            $mock->shouldReceive('chat')->once()->andReturn('Perfeito, Carlos! [EM_ATENDIMENTO]');
        });

        app(SdrResponderService::class)->responder($ticket);

        $ticket->refresh();
        $this->assertSame('em_atendimento', $ticket->coluna_kanban);
        Queue::assertPushed(SequenciaMensagemJob::class, fn ($job) => $job->ticketId === $ticket->id);
    }
}
