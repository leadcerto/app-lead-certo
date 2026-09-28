<?php

namespace Tests\Feature;

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
 * Achado real 24/09 (Leonardo, ticket #4908 "Jairo Jr" e #4907 "Rodrigo Sani"):
 * varredura no histórico de colunas do tenant 1 mostrou 101 de 167 tickets
 * (60%) saltando de "Aguardando Orçamento" pra "Aguardando Lead" em menos de
 * 5 minutos — muitos em 1-2 segundos, tempo impossível pra um atendente
 * humano ter digitado um orçamento de verdade. A coluna "Aguardando Orçamento"
 * usa uma IA "observadora silenciosa" cujo único trabalho é detectar quando um
 * HUMANO manda o valor real (R$) pro lead e então mover o card — mas ela vem
 * disparando o movimento sem nenhum valor real ter sido enviado (provavelmente
 * confundida pela própria mensagem automática do sistema "vou preparar seu
 * orçamento..."). Resultado: o ticket cai numa coluna que assume "orçamento já
 * enviado" sem ter sido, e a IA de lá entra num loop cobrando comprovante de
 * um sinal que não existe. Mesmo padrão de rede de segurança determinística já
 * usado em SdrResponderServiceHandoffPrematuroTest/RejeicaoAreaAlucinadaTest —
 * não confia só na palavra do modelo, confere contra o estado real.
 */
class SdrResponderServiceOrcamentoRealTest extends TestCase
{
    use RefreshDatabase;

    private function criarTicket(): TicketAtendimento
    {
        $tenant  = Tenant::factory()->create();
        $persona = SdrPersona::create([
            'tenant_id' => $tenant->id, 'nome_interno' => 'padrao', 'nome_display' => 'Joao',
            'system_prompt' => 'Você é um atendente.', 'ativo' => true, 'is_default' => true, 'tier' => 'simples',
        ]);
        $contato = Contato::factory()->create(['telefone' => '5511988887777']);

        return TicketAtendimento::create([
            'tenant_id' => $tenant->id, 'contato_id' => $contato->id,
            'coluna_kanban' => 'aguardando_orcamento', 'agente_responsavel' => 'bot', 'etapa_ia' => 'etapa_1',
            'status' => 'aberto', 'aberto_em' => now(), 'sdr_persona_id' => $persona->id,
        ]);
    }

    public function test_bloqueia_movimento_para_aguardando_lead_sem_mensagem_de_orcamento_real_de_humano(): void
    {
        Http::fake(['*' => Http::response(['ok' => true], 200)]);
        $ticket = $this->criarTicket();

        Mensagem::create([
            'ticket_id' => $ticket->id, 'tenant_id' => $ticket->tenant_id,
            'remetente' => 'lead', 'tipo' => 'texto', 'conteudo' => 'consegue me passar um orçamento?',
            'enviado_em' => now(),
        ]);

        // Mensagem automática do sistema — não é um humano mandando valor,
        // é exatamente o que confundiu o observador no caso real.
        Mensagem::create([
            'ticket_id' => $ticket->id, 'tenant_id' => $ticket->tenant_id,
            'remetente' => 'bot', 'tipo' => 'texto',
            'conteudo' => 'Olá Jairo, vou preparar seu orçamento com base nas informações que nos enviou e já lhe retorno!',
            'enviado_em' => now(),
        ]);

        $this->mock(OpenRouterService::class, function ($mock) {
            $mock->shouldReceive('chat')->once()->andReturn('[AGUARDANDO_LEAD]');
        });

        $resposta = app(SdrResponderService::class)->responder($ticket);

        $this->assertNull($resposta);
        $this->assertSame('aguardando_orcamento', $ticket->fresh()->coluna_kanban, 'não pode avançar sem orçamento real');
        $this->assertNotNull($ticket->fresh()->aguardando_orientacao_em);
        $this->assertDatabaseHas('alertas_internos', [
            'ticket_id' => $ticket->id, 'tenant_id' => $ticket->tenant_id, 'tipo' => 'movimento_sem_orcamento_real',
        ]);
    }

    public function test_permite_movimento_para_aguardando_lead_quando_humano_mandou_orcamento_real(): void
    {
        Http::fake(['*' => Http::response(['ok' => true], 200)]);
        $ticket = $this->criarTicket();

        Mensagem::create([
            'ticket_id' => $ticket->id, 'tenant_id' => $ticket->tenant_id,
            'remetente' => 'lead', 'tipo' => 'texto', 'conteudo' => 'consegue me passar um orçamento?',
            'enviado_em' => now(),
        ]);
        Mensagem::create([
            'ticket_id' => $ticket->id, 'tenant_id' => $ticket->tenant_id,
            'remetente' => 'humano', 'tipo' => 'texto',
            'conteudo' => "🛻 Carro 1 carroceria 1,00m\n*R$300* = Somente Transporte",
            'enviado_em' => now(),
        ]);

        $this->mock(OpenRouterService::class, function ($mock) {
            $mock->shouldReceive('chat')->once()->andReturn('[AGUARDANDO_LEAD]');
        });

        $resposta = app(SdrResponderService::class)->responder($ticket);

        $ticket->refresh();
        $this->assertSame('aguardando_lead', $ticket->coluna_kanban);
        $this->assertNull($ticket->aguardando_orientacao_em);
    }

    public function test_nao_interfere_em_outras_colunas_movendo_para_aguardando_lead(): void
    {
        // A trava só se aplica à transição específica aguardando_orcamento -> aguardando_lead;
        // qualquer outra origem não deve ser afetada por essa regra.
        Http::fake(['*' => Http::response(['ok' => true], 200)]);
        $tenant  = Tenant::factory()->create();
        $persona = SdrPersona::create([
            'tenant_id' => $tenant->id, 'nome_interno' => 'padrao', 'nome_display' => 'Joao',
            'system_prompt' => 'Você é um atendente.', 'ativo' => true, 'is_default' => true, 'tier' => 'simples',
        ]);
        $contato = Contato::factory()->create(['telefone' => '5511988887777']);
        $ticket  = TicketAtendimento::create([
            'tenant_id' => $tenant->id, 'contato_id' => $contato->id,
            'coluna_kanban' => 'em_atendimento', 'agente_responsavel' => 'bot', 'etapa_ia' => 'etapa_1',
            'status' => 'aberto', 'aberto_em' => now(), 'sdr_persona_id' => $persona->id,
        ]);
        Mensagem::create([
            'ticket_id' => $ticket->id, 'tenant_id' => $ticket->tenant_id,
            'remetente' => 'lead', 'tipo' => 'texto', 'conteudo' => 'oi', 'enviado_em' => now(),
        ]);

        $this->mock(OpenRouterService::class, function ($mock) {
            $mock->shouldReceive('chat')->once()->andReturn('Beleza! [AGUARDANDO_ORCAMENTO]');
        });

        $resposta = app(SdrResponderService::class)->responder($ticket);

        $ticket->refresh();
        $this->assertSame('aguardando_orcamento', $ticket->coluna_kanban);
        $this->assertNull($ticket->aguardando_orientacao_em);
    }
}
