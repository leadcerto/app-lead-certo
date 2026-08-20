<?php

namespace Tests\Feature;

use App\Models\Contato;
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
 * Item 11 do roteiro de 2026-08-20 — tradução bidirecional real, não só um
 * selo de idioma estático. O agente continua gerando a resposta em
 * português normalmente; a tradução acontece só na hora de enviar.
 */
class SdrResponderServiceTraducaoTest extends TestCase
{
    use RefreshDatabase;

    private function criarTicket(?string $idiomaLead = null): TicketAtendimento
    {
        $tenant  = Tenant::factory()->create();
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
            'idioma_lead' => $idiomaLead,
        ]);
    }

    public function test_traduz_resposta_quando_lead_fala_outro_idioma(): void
    {
        Http::fake(['*' => Http::response(['ok' => true], 200)]);
        $ticket = $this->criarTicket('en');

        $this->mock(OpenRouterService::class, function ($mock) {
            $mock->shouldReceive('chat')->once()->andReturn('Perfeito, vou verificar isso pra você.');
        });
        $this->mock(\App\Services\TraducaoService::class, function ($mock) {
            $mock->shouldReceive('traduzir')
                ->once()
                ->with('Perfeito, vou verificar isso pra você.', 'en')
                ->andReturn('Perfect, I will check that for you.');
        });

        $resposta = app(SdrResponderService::class)->responder($ticket);

        // Retorno continua em português (uso interno)
        $this->assertSame('Perfeito, vou verificar isso pra você.', $resposta);

        $mensagem = \App\Models\Mensagem::where('ticket_id', $ticket->id)->where('remetente', 'bot')->latest()->first();
        $this->assertSame('Perfect, I will check that for you.', $mensagem->conteudo);
        $this->assertSame('en', $mensagem->idioma);
        $this->assertSame('Perfeito, vou verificar isso pra você.', $mensagem->conteudo_pt);

        Http::assertSent(fn ($request) => str_contains($request->body() ?? '', 'Perfect, I will check that for you.')
            || str_contains(json_encode($request->data()), 'Perfect, I will check that for you.'));
    }

    public function test_nao_traduz_quando_lead_fala_portugues(): void
    {
        Http::fake(['*' => Http::response(['ok' => true], 200)]);
        $ticket = $this->criarTicket('pt');

        $this->mock(OpenRouterService::class, function ($mock) {
            $mock->shouldReceive('chat')->once()->andReturn('Perfeito, vou verificar isso pra você.');
        });
        $this->mock(\App\Services\TraducaoService::class, function ($mock) {
            $mock->shouldNotReceive('traduzir');
        });

        app(SdrResponderService::class)->responder($ticket);

        $mensagem = \App\Models\Mensagem::where('ticket_id', $ticket->id)->where('remetente', 'bot')->latest()->first();
        $this->assertSame('Perfeito, vou verificar isso pra você.', $mensagem->conteudo);
        $this->assertSame('pt', $mensagem->idioma);
        $this->assertNull($mensagem->conteudo_pt);
    }

    public function test_nao_traduz_quando_idioma_do_lead_ainda_nao_foi_detectado(): void
    {
        Http::fake(['*' => Http::response(['ok' => true], 200)]);
        $ticket = $this->criarTicket(null);

        $this->mock(OpenRouterService::class, function ($mock) {
            $mock->shouldReceive('chat')->once()->andReturn('Perfeito, vou verificar isso pra você.');
        });
        $this->mock(\App\Services\TraducaoService::class, function ($mock) {
            $mock->shouldNotReceive('traduzir');
        });

        app(SdrResponderService::class)->responder($ticket);

        $mensagem = \App\Models\Mensagem::where('ticket_id', $ticket->id)->where('remetente', 'bot')->latest()->first();
        $this->assertSame('Perfeito, vou verificar isso pra você.', $mensagem->conteudo);
    }

    public function test_falha_de_traducao_manda_o_texto_original_em_portugues(): void
    {
        Http::fake(['*' => Http::response(['ok' => true], 200)]);
        $ticket = $this->criarTicket('en');

        $this->mock(OpenRouterService::class, function ($mock) {
            $mock->shouldReceive('chat')->once()->andReturn('Perfeito, vou verificar isso pra você.');
        });
        $this->mock(\App\Services\TraducaoService::class, function ($mock) {
            $mock->shouldReceive('traduzir')->once()->andReturn(null);
        });

        $resposta = app(SdrResponderService::class)->responder($ticket);

        $this->assertNotNull($resposta);
        $mensagem = \App\Models\Mensagem::where('ticket_id', $ticket->id)->where('remetente', 'bot')->latest()->first();
        $this->assertSame('Perfeito, vou verificar isso pra você.', $mensagem->conteudo);
        $this->assertSame('pt', $mensagem->idioma);
    }
}
