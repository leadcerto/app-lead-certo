<?php

namespace Tests\Feature;

use App\Models\Contato;
use App\Models\Mensagem;
use App\Models\SdrPersona;
use App\Models\Tenant;
use App\Models\TicketAtendimento;
use App\Services\EcoTranscricaoService;
use App\Services\OpenRouterService;
use App\Services\SdrResponderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Achado em 2026-08-05, ao revisar se o agente respeita corretamente o
 * contexto da conversa (pedido do Leonardo): as mensagens de eco de
 * transcrição de áudio (EcoTranscricaoService) ficam salvas com
 * remetente=bot — e como o histórico mandado ao LLM trata qualquer
 * remetente != lead como "assistant" (o próprio agente falando), o eco
 * entrava no contexto como se a IA tivesse dito aquilo. Duplica informação
 * (o áudio já aparece transcrito como mensagem do lead) e pode confundir o
 * agente sobre o que ele mesmo já disse. Decisão do Leonardo: excluir do
 * contexto do LLM, mantendo normalmente no card/WhatsApp.
 */
class SdrResponderServiceHistoricoTest extends TestCase
{
    use RefreshDatabase;

    private function criarTicketComPersona(): TicketAtendimento
    {
        $tenant  = Tenant::factory()->create(['uazapi_instance_token' => 'tok']);
        $persona = SdrPersona::create([
            'tenant_id' => $tenant->id, 'nome_interno' => 'padrao', 'nome_display' => 'Joao',
            'system_prompt' => 'Você é um atendente.', 'ativo' => true, 'is_default' => true, 'tier' => 'simples',
        ]);
        $contato = Contato::factory()->create(['telefone' => '5511988887777']);

        return TicketAtendimento::create([
            'tenant_id' => $tenant->id, 'contato_id' => $contato->id,
            'coluna_kanban' => 'lead_novo', 'agente_responsavel' => 'bot', 'etapa_ia' => 'etapa_1',
            'status' => 'aberto', 'aberto_em' => now(), 'sdr_persona_id' => $persona->id,
        ]);
    }

    public function test_eco_de_transcricao_nao_entra_no_historico_mandado_ao_llm(): void
    {
        Http::fake(['*' => Http::response(['ok' => true], 200)]);
        $ticket = $this->criarTicketComPersona();

        Mensagem::create([
            'ticket_id' => $ticket->id, 'tenant_id' => $ticket->tenant_id,
            'remetente' => 'lead', 'tipo' => 'audio',
            'conteudo' => '[Áudio transcrito: oi, quero saber o valor do frete]',
            'enviado_em' => now()->subMinutes(2),
        ]);
        Mensagem::create([
            'ticket_id' => $ticket->id, 'tenant_id' => $ticket->tenant_id,
            'remetente' => 'bot', 'tipo' => 'texto',
            'conteudo' => EcoTranscricaoService::PREFIXO . "Cliente]\n\noi, quero saber o valor do frete",
            'enviado_em' => now()->subMinutes(1),
        ]);

        $mensagensCapturadas = null;
        $this->mock(OpenRouterService::class, function ($mock) use (&$mensagensCapturadas) {
            $mock->shouldReceive('chat')
                ->once()
                ->withArgs(function ($messages) use (&$mensagensCapturadas) {
                    $mensagensCapturadas = $messages;
                    return true;
                })
                ->andReturn('Oi! O valor depende de alguns detalhes...');
        });

        app(SdrResponderService::class)->responder($ticket);

        $conteudos = array_column($mensagensCapturadas, 'content');
        $this->assertContains('[Áudio transcrito: oi, quero saber o valor do frete]', $conteudos);
        foreach ($conteudos as $conteudo) {
            $this->assertStringNotContainsString(EcoTranscricaoService::PREFIXO, $conteudo);
        }
    }

    public function test_mensagens_normais_de_bot_continuam_no_historico(): void
    {
        Http::fake(['*' => Http::response(['ok' => true], 200)]);
        $ticket = $this->criarTicketComPersona();

        Mensagem::create([
            'ticket_id' => $ticket->id, 'tenant_id' => $ticket->tenant_id,
            'remetente' => 'bot', 'tipo' => 'texto',
            'conteudo' => 'Oi! Vou te ajudar com o orçamento.',
            'enviado_em' => now()->subMinutes(1),
        ]);

        $mensagensCapturadas = null;
        $this->mock(OpenRouterService::class, function ($mock) use (&$mensagensCapturadas) {
            $mock->shouldReceive('chat')
                ->once()
                ->withArgs(function ($messages) use (&$mensagensCapturadas) {
                    $mensagensCapturadas = $messages;
                    return true;
                })
                ->andReturn('Perfeito, me conta mais...');
        });

        app(SdrResponderService::class)->responder($ticket);

        $conteudos = array_column($mensagensCapturadas, 'content');
        $this->assertContains('Oi! Vou te ajudar com o orçamento.', $conteudos);
    }

    /**
     * Pedido do Leonardo (2026-08-05): o agente precisa saber explicitamente
     * quando foi o atendente humano que falou, não ele mesmo — sem isso, o
     * histórico mandado ao LLM tratava mensagens de 'bot' e 'humano' como o
     * mesmo papel "assistant", sem diferenciar quem realmente disse o quê.
     */
    public function test_mensagem_de_humano_e_marcada_no_historico_para_o_llm_distinguir(): void
    {
        Http::fake(['*' => Http::response(['ok' => true], 200)]);
        $ticket = $this->criarTicketComPersona();

        Mensagem::create([
            'ticket_id' => $ticket->id, 'tenant_id' => $ticket->tenant_id,
            'remetente' => 'humano', 'tipo' => 'texto',
            'conteudo' => 'Pode deixar, eu confirmo o horário com você.',
            'enviado_em' => now()->subMinutes(2),
        ]);
        Mensagem::create([
            'ticket_id' => $ticket->id, 'tenant_id' => $ticket->tenant_id,
            'remetente' => 'bot', 'tipo' => 'texto',
            'conteudo' => 'Oi! Vou te ajudar com o orçamento.',
            'enviado_em' => now()->subMinutes(1),
        ]);

        $mensagensCapturadas = null;
        $this->mock(OpenRouterService::class, function ($mock) use (&$mensagensCapturadas) {
            $mock->shouldReceive('chat')
                ->once()
                ->withArgs(function ($messages) use (&$mensagensCapturadas) {
                    $mensagensCapturadas = $messages;
                    return true;
                })
                ->andReturn('Perfeito, me conta mais...');
        });

        app(SdrResponderService::class)->responder($ticket);

        $conteudos = array_column($mensagensCapturadas, 'content');
        $doHumano = array_values(array_filter($conteudos, fn ($c) => str_contains($c, 'Pode deixar, eu confirmo o horário com você.')))[0] ?? null;
        $doBot    = array_values(array_filter($conteudos, fn ($c) => str_contains($c, 'Oi! Vou te ajudar com o orçamento.')))[0] ?? null;

        $this->assertNotNull($doHumano);
        $this->assertStringContainsString('[Atendente humano respondeu]', $doHumano, 'Mensagem do humano deveria estar marcada');
        $this->assertNotNull($doBot);
        $this->assertStringNotContainsString('[Atendente humano respondeu]', $doBot, 'Mensagem do próprio bot não deveria ser marcada como humano');
    }

    public function test_conhecimento_geral_do_kanban_entra_no_prompt(): void
    {
        Http::fake(['*' => Http::response(['ok' => true], 200)]);
        $ticket = $this->criarTicketComPersona();

        \App\Models\Kanban::where('tenant_id', $ticket->tenant_id)->where('tipo', 'vendas')
            ->update(['conhecimento_geral' => 'Este Kanban atende só clientes da Zona Sul do Rio.']);

        $mensagensCapturadas = null;
        $this->mock(OpenRouterService::class, function ($mock) use (&$mensagensCapturadas) {
            $mock->shouldReceive('chat')
                ->once()
                ->withArgs(function ($messages) use (&$mensagensCapturadas) {
                    $mensagensCapturadas = $messages;
                    return true;
                })
                ->andReturn('Oi! Vamos começar.');
        });

        app(SdrResponderService::class)->responder($ticket);

        $this->assertStringContainsString('Este Kanban atende só clientes da Zona Sul do Rio.', $mensagensCapturadas[0]['content']);
    }
}
