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
}
