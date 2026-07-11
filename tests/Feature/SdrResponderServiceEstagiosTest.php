<?php

namespace Tests\Feature;

use App\Models\Contato;
use App\Models\SdrPersona;
use App\Models\Tenant;
use App\Models\TicketAtendimento;
use App\Services\OpenRouterService;
use App\Services\SdrResponderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SdrResponderServiceEstagiosTest extends TestCase
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

    public function test_gatilho_estagio_3_injeta_contexto_correto_e_encerra_com_token(): void
    {
        Http::fake(['*' => Http::response(['ok' => true], 200)]);
        $ticket = $this->criarTicketComPersona();

        $mensagensCapturadas = null;
        $this->mock(OpenRouterService::class, function ($mock) use (&$mensagensCapturadas) {
            $mock->shouldReceive('chat')
                ->once()
                ->withArgs(function ($messages) use (&$mensagensCapturadas) {
                    $mensagensCapturadas = $messages;
                    return true;
                })
                ->andReturn('Tudo bem, um prazer! Fico à disposição. [ENCERRADO]');
        });

        $resposta = app(SdrResponderService::class)->responder($ticket, gatilho: 'estagio_3');

        $this->assertNotNull($resposta);
        $this->assertStringContainsString('ESTÁGIO 3 DE SILÊNCIO CONFIRMADO', $mensagensCapturadas[0]['content']);
        $this->assertSame('encerrado', $ticket->fresh()->coluna_kanban);
        $this->assertSame('encerrado', $ticket->fresh()->status);
    }

    public function test_gatilho_estagio_1_nao_inclui_instrucao_de_encerramento_no_contexto(): void
    {
        Http::fake(['*' => Http::response(['ok' => true], 200)]);
        $ticket = $this->criarTicketComPersona();

        $mensagensCapturadas = null;
        $this->mock(OpenRouterService::class, function ($mock) use (&$mensagensCapturadas) {
            $mock->shouldReceive('chat')
                ->once()
                ->withArgs(function ($messages) use (&$mensagensCapturadas) {
                    $mensagensCapturadas = $messages;
                    return true;
                })
                ->andReturn('Oi! Tudo certo por aí? Consegue me mandar os dados?');
        });

        app(SdrResponderService::class)->responder($ticket, gatilho: 'estagio_1');

        $this->assertStringContainsString('ESTÁGIO 1 DE SILÊNCIO CONFIRMADO', $mensagensCapturadas[0]['content']);
        $this->assertStringContainsString('NÃO use [ENCERRADO] neste estágio', $mensagensCapturadas[0]['content']);
        $this->assertSame('lead_novo', $ticket->fresh()->coluna_kanban);
    }
}
