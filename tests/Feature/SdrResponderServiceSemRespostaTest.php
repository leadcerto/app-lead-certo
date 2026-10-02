<?php
// tests/Feature/SdrResponderServiceSemRespostaTest.php
namespace Tests\Feature;

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

/**
 * Achado real 02/10 (Leonardo, ticket #4999 "Mariana Pacará", decisão
 * confirmada com ele no mesmo dia): com a Meta cobrando por mensagem
 * entregue (não mais por conversa), uma mensagem de cortesia pura (ex.:
 * responder "Fico feliz em ajudar! Estou por aqui!" a um simples "obrigada")
 * é custo sem valor agregado. A própria IA decide — com o contexto completo
 * da conversa — quando a mensagem do lead não precisa de resposta nenhuma,
 * via o token [SEM_RESPOSTA], mesmo padrão de [DUVIDA:]. Diferença: não
 * pausa o ticket nem cria alerta — não é uma situação que precise de
 * revisão humana.
 */
class SdrResponderServiceSemRespostaTest extends TestCase
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

    public function test_token_sem_resposta_nao_envia_mensagem_nem_pausa_o_ticket(): void
    {
        Http::fake(['*' => Http::response(['ok' => true], 200)]);
        $ticket = $this->criarTicketComCanal();

        $this->mock(OpenRouterService::class, function ($mock) {
            $mock->shouldReceive('chat')->once()->andReturn('[SEM_RESPOSTA]');
        });

        $resposta = app(SdrResponderService::class)->responder($ticket);

        $this->assertNull($resposta);
        Http::assertNothingSent();
        $this->assertDatabaseMissing('mensagens', ['ticket_id' => $ticket->id, 'remetente' => 'bot']);

        $ticketFresco = $ticket->fresh();
        $this->assertNull($ticketFresco->aguardando_orientacao_em);
        $this->assertDatabaseMissing('alertas_internos', ['ticket_id' => $ticket->id]);
    }

    public function test_sem_resposta_tem_prioridade_sobre_outros_tokens_na_mesma_resposta(): void
    {
        Http::fake(['*' => Http::response(['ok' => true], 200)]);
        $ticket = $this->criarTicketComCanal();

        $objetivo = KanbanColunaObjetivo::create([
            'tenant_id' => $ticket->tenant_id, 'coluna_kanban' => 'em_atendimento',
            'texto' => 'Endereço confirmado', 'ordem' => 1, 'ativo' => true,
        ]);

        $this->mock(OpenRouterService::class, function ($mock) use ($objetivo) {
            $mock->shouldReceive('chat')->once()
                ->andReturn("[SEM_RESPOSTA] [PAGAMENTO] [OBJETIVO_CUMPRIDO:{$objetivo->id}]");
        });

        $resposta = app(SdrResponderService::class)->responder($ticket);

        $this->assertNull($resposta);
        Http::assertNothingSent();

        $ticketFresco = $ticket->fresh();
        $this->assertSame('em_atendimento', $ticketFresco->coluna_kanban);
        $this->assertEmpty($ticketFresco->objetivos_cumpridos ?? []);
    }

    public function test_resposta_normal_sem_o_token_continua_enviando_normalmente(): void
    {
        Http::fake(['*' => Http::response(['ok' => true], 200)]);
        $ticket = $this->criarTicketComCanal();

        $this->mock(OpenRouterService::class, function ($mock) {
            $mock->shouldReceive('chat')->once()->andReturn('Perfeito, vou verificar isso pra você!');
        });

        $resposta = app(SdrResponderService::class)->responder($ticket);

        $this->assertSame('Perfeito, vou verificar isso pra você!', $resposta);
        $this->assertDatabaseHas('mensagens', [
            'ticket_id' => $ticket->id, 'remetente' => 'bot',
            'conteudo'  => 'Perfeito, vou verificar isso pra você!',
        ]);
    }

    public function test_prompt_instrui_a_ia_a_usar_o_token_sem_resposta_pra_agradecimentos_curtos(): void
    {
        Http::fake(['*' => Http::response(['ok' => true], 200)]);
        $ticket = $this->criarTicketComCanal();

        $mensagensCapturadas = null;
        $this->mock(OpenRouterService::class, function ($mock) use (&$mensagensCapturadas) {
            $mock->shouldReceive('chat')->once()
                ->withArgs(function ($messages) use (&$mensagensCapturadas) {
                    $mensagensCapturadas = $messages;
                    return true;
                })
                ->andReturn('Perfeito!');
        });

        app(SdrResponderService::class)->responder($ticket);

        $prompt = $mensagensCapturadas[0]['content'];
        $this->assertStringContainsString('[SEM_RESPOSTA]', $prompt);
        $this->assertStringContainsString('obrigada', $prompt);
    }
}
