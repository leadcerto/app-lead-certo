<?php
// tests/Feature/SdrResponderServiceDuvidaTest.php
namespace Tests\Feature;

use App\Models\AlertaInterno;
use App\Models\Contato;
use App\Models\KanbanColunaObjetivo;
use App\Models\SdrPersona;
use App\Models\Tenant;
use App\Models\TicketAtendimento;
use App\Models\WhatsappCanal;
use App\Services\OpenRouterService;
use App\Services\SdrResponderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SdrResponderServiceDuvidaTest extends TestCase
{
    use RefreshDatabase;

    private function criarTicketComCanal(): TicketAtendimento
    {
        $tenant  = Tenant::factory()->create(['uazapi_instance_token' => 'tok']);
        $canal   = WhatsappCanal::factory()->create(['tenant_id' => $tenant->id, 'config' => ['instance_token' => 'tok']]);
        $persona = SdrPersona::create([
            'tenant_id' => $tenant->id, 'nome_interno' => 'padrao', 'nome_display' => 'Joao',
            'system_prompt' => 'Você é um atendente.', 'ativo' => true, 'is_default' => true, 'tier' => 'simples',
        ]);
        $contato = Contato::factory()->create(['telefone' => '5511988887777']);

        return TicketAtendimento::create([
            'tenant_id' => $tenant->id, 'contato_id' => $contato->id, 'whatsapp_canal_id' => $canal->id,
            'coluna_kanban' => 'em_atendimento', 'agente_responsavel' => 'bot', 'etapa_ia' => 'etapa_1',
            'status' => 'aberto', 'aberto_em' => now(), 'sdr_persona_id' => $persona->id,
        ]);
    }

    public function test_token_duvida_pausa_o_ticket_sem_enviar_mensagem_e_cria_alerta(): void
    {
        Http::fake(['*' => Http::response(['ok' => true], 200)]);
        $ticket = $this->criarTicketComCanal();

        $this->mock(OpenRouterService::class, function ($mock) {
            $mock->shouldReceive('chat')->once()
                ->andReturn('[DUVIDA: O lead perguntou o preço de um serviço que não está na tabela.]');
        });

        $resposta = app(SdrResponderService::class)->responder($ticket);

        $this->assertNull($resposta);
        Http::assertNothingSent();
        $this->assertDatabaseMissing('mensagens', ['ticket_id' => $ticket->id, 'remetente' => 'bot']);

        $ticketFresco = $ticket->fresh();
        $this->assertNotNull($ticketFresco->aguardando_orientacao_em);
        $this->assertFalse($ticketFresco->mensagem_espera_enviada);

        $this->assertDatabaseHas('alertas_internos', [
            'ticket_id' => $ticket->id, 'tenant_id' => $ticket->tenant_id, 'tipo' => 'duvida_ia',
            'conteudo'  => 'O lead perguntou o preço de um serviço que não está na tabela.',
        ]);
    }

    public function test_ticket_aguardando_orientacao_suprime_qualquer_resposta_do_agente(): void
    {
        $ticket = $this->criarTicketComCanal();
        $ticket->update(['aguardando_orientacao_em' => now()]);

        $mock = $this->mock(OpenRouterService::class);
        $mock->shouldReceive('chat')->never();

        $resposta = app(SdrResponderService::class)->responder($ticket);

        $this->assertNull($resposta);
    }

    public function test_orientacao_humana_e_injetada_no_prompt_e_gera_resposta_real_ao_lead(): void
    {
        Http::fake(['*' => Http::response(['ok' => true], 200)]);
        $ticket = $this->criarTicketComCanal();
        $ticket->update(['aguardando_orientacao_em' => null]); // já foi limpo antes do redisparo (Task 5)

        $mensagensCapturadas = null;
        $this->mock(OpenRouterService::class, function ($mock) use (&$mensagensCapturadas) {
            $mock->shouldReceive('chat')->once()
                ->withArgs(function ($messages) use (&$mensagensCapturadas) {
                    $mensagensCapturadas = $messages;
                    return true;
                })
                ->andReturn('O preço desse serviço é R$ 250.');
        });

        $resposta = app(SdrResponderService::class)->responder(
            $ticket, orientacaoHumana: 'O preço desse serviço específico é R$ 250, pode confirmar.'
        );

        $this->assertSame('O preço desse serviço é R$ 250.', $resposta);
        $this->assertStringContainsString('O preço desse serviço específico é R$ 250', $mensagensCapturadas[0]['content']);
        $this->assertDatabaseHas('mensagens', ['ticket_id' => $ticket->id, 'conteudo' => 'O preço desse serviço é R$ 250.']);
    }

    public function test_falha_ao_criar_alerta_nao_impede_a_pausa(): void
    {
        $ticket = $this->criarTicketComCanal();

        $this->mock(OpenRouterService::class, function ($mock) {
            $mock->shouldReceive('chat')->once()->andReturn('[DUVIDA: teste]');
        });
        $this->mock(\App\Services\AlertaInternoService::class, function ($mock) {
            $mock->shouldReceive('criar')->once()->andThrow(new \Exception('falha simulada'));
        });

        app(SdrResponderService::class)->responder($ticket);

        $this->assertNotNull($ticket->fresh()->aguardando_orientacao_em);
    }

    private function assertTokenDuvidaPausaOTicket(string $tokenBruto): void
    {
        Http::fake(['*' => Http::response(['ok' => true], 200)]);
        $ticket = $this->criarTicketComCanal();

        $this->mock(OpenRouterService::class, function ($mock) use ($tokenBruto) {
            $mock->shouldReceive('chat')->once()->andReturn($tokenBruto);
        });

        $resposta = app(SdrResponderService::class)->responder($ticket);

        $this->assertNull($resposta);
        Http::assertNothingSent();
        $this->assertDatabaseMissing('mensagens', ['ticket_id' => $ticket->id, 'remetente' => 'bot']);
        $this->assertNotNull($ticket->fresh()->aguardando_orientacao_em);
    }

    public function test_token_duvida_acentuado_maiusculo_e_detectado(): void
    {
        $this->assertTokenDuvidaPausaOTicket('[DÚVIDA: preço não está na tabela]');
    }

    public function test_token_duvida_misto_sem_acento_e_detectado(): void
    {
        $this->assertTokenDuvidaPausaOTicket('[Duvida: preço não está na tabela]');
    }

    public function test_token_duvida_minusculo_acentuado_e_detectado(): void
    {
        $this->assertTokenDuvidaPausaOTicket('[dúvida: preço não está na tabela]');
    }

    public function test_duvida_tem_prioridade_sobre_outros_tokens_na_mesma_resposta(): void
    {
        Http::fake(['*' => Http::response(['ok' => true], 200)]);
        $ticket = $this->criarTicketComCanal();

        $objetivo = KanbanColunaObjetivo::create([
            'tenant_id' => $ticket->tenant_id, 'coluna_kanban' => 'em_atendimento',
            'texto' => 'Endereço confirmado', 'ordem' => 1, 'ativo' => true,
        ]);

        $this->mock(OpenRouterService::class, function ($mock) use ($objetivo) {
            $mock->shouldReceive('chat')->once()
                ->andReturn("[DUVIDA: preciso de ajuda] [PAGAMENTO] [OBJETIVO_CUMPRIDO:{$objetivo->id}]");
        });

        $resposta = app(SdrResponderService::class)->responder($ticket);

        $this->assertNull($resposta);
        Http::assertNothingSent();

        $ticketFresco = $ticket->fresh();
        $this->assertSame('em_atendimento', $ticketFresco->coluna_kanban);
        $this->assertEmpty($ticketFresco->objetivos_cumpridos ?? []);
        $this->assertNotNull($ticketFresco->aguardando_orientacao_em);
    }
}
