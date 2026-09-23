<?php

namespace Tests\Feature;

use App\Models\AlertaInterno;
use App\Models\Contato;
use App\Models\Mensagem;
use App\Models\SdrPersona;
use App\Models\Tenant;
use App\Models\TicketAtendimento;
use App\Services\OpenRouterService;
use App\Services\SdrResponderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Achado real 23/09 (Leonardo, ticket #4840 "Eduarda Santos"): a IA fechou
 * sozinha um atendimento com a janela da Meta ainda em 23h restantes, sem
 * nenhum humano decidir — a cliente tinha acabado de mandar os dois
 * endereços e a lista de itens, um lead quase pronto, e só tinha dito "Falar
 * com robô não dá. Obrigado" (queria um humano, não queria parar de vez).
 * Decisão do Leonardo: a IA nunca mais encerra sozinha — [ENCERRADO] agora
 * pausa o atendimento (mesmo mecanismo de "aguardando orientação" já usado
 * pra dúvida/handoff prematuro) e cria um alerta pra um humano decidir.
 */
class SdrResponderServiceEncerramentoIaTest extends TestCase
{
    use RefreshDatabase;

    private function criarTicket(Tenant $tenant): TicketAtendimento
    {
        $persona = SdrPersona::create([
            'tenant_id' => $tenant->id, 'nome_interno' => 'padrao', 'nome_display' => 'Joao',
            'system_prompt' => 'Você é um atendente.', 'ativo' => true, 'is_default' => true, 'tier' => 'simples',
        ]);
        $contato = Contato::factory()->create(['telefone' => '5511988887777']);

        return TicketAtendimento::create([
            'tenant_id' => $tenant->id, 'contato_id' => $contato->id,
            'coluna_kanban' => 'em_atendimento', 'agente_responsavel' => 'bot', 'status' => 'aberto',
            'aberto_em' => now(), 'sdr_persona_id' => $persona->id, 'etapa_ia' => 'etapa_1',
        ]);
    }

    public function test_token_encerrado_nao_fecha_o_ticket_sozinho(): void
    {
        Http::fake(['*' => Http::response(['ok' => true], 200)]);

        $tenant = Tenant::factory()->create(['uazapi_instance_token' => 'tok']);
        $ticket = $this->criarTicket($tenant);

        Mensagem::create([
            'ticket_id' => $ticket->id, 'tenant_id' => $tenant->id,
            'remetente' => 'lead', 'tipo' => 'texto', 'conteudo' => 'Falar com robô não dá',
            'enviado_em' => now(),
        ]);

        $this->mock(OpenRouterService::class, function ($mock) {
            $mock->shouldReceive('chat')->once()->andReturn('Entendo! Um abraço. [ENCERRADO]');
        });

        $resultado = app(SdrResponderService::class)->responder($ticket);

        $ticket->refresh();
        $this->assertNull($resultado, 'a IA não pode mandar a mensagem de despedida sozinha');
        $this->assertSame('em_atendimento', $ticket->coluna_kanban, 'o ticket não pode mudar de coluna sozinho');
        $this->assertSame('aberto', $ticket->status, 'o ticket não pode ser fechado sozinho');
        $this->assertNotNull($ticket->aguardando_orientacao_em, 'tem que pausar esperando um humano decidir');
    }

    public function test_token_encerrado_cria_alerta_interno_para_humano_decidir(): void
    {
        Http::fake(['*' => Http::response(['ok' => true], 200)]);

        $tenant = Tenant::factory()->create(['uazapi_instance_token' => 'tok']);
        $ticket = $this->criarTicket($tenant);

        $this->mock(OpenRouterService::class, function ($mock) {
            $mock->shouldReceive('chat')->once()->andReturn('Entendo! Um abraço. [ENCERRADO]');
        });

        app(SdrResponderService::class)->responder($ticket);

        $this->assertDatabaseHas('alertas_internos', [
            'tenant_id' => $tenant->id,
            'ticket_id' => $ticket->id,
            'tipo'      => 'ia_pediu_encerramento',
        ]);
    }

    public function test_token_encerrado_nao_envia_nenhuma_mensagem_pro_lead(): void
    {
        Http::fake(['*' => Http::response(['ok' => true], 200)]);

        $tenant = Tenant::factory()->create(['uazapi_instance_token' => 'tok']);
        $ticket = $this->criarTicket($tenant);

        $this->mock(OpenRouterService::class, function ($mock) {
            $mock->shouldReceive('chat')->once()->andReturn('Entendo! Um abraço. [ENCERRADO]');
        });

        app(SdrResponderService::class)->responder($ticket);

        $this->assertSame(
            0,
            Mensagem::where('ticket_id', $ticket->id)->where('remetente', 'bot')->count(),
            'nenhuma mensagem de despedida pode sair antes de um humano decidir'
        );
    }
}
