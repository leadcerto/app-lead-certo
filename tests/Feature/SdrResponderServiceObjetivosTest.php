<?php

namespace Tests\Feature;

use App\Models\Contato;
use App\Models\KanbanColunaObjetivo;
use App\Models\SdrPersona;
use App\Models\Tenant;
use App\Models\TicketAtendimento;
use App\Services\OpenRouterService;
use App\Services\SdrResponderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SdrResponderServiceObjetivosTest extends TestCase
{
    use RefreshDatabase;

    private function criarTicketComPersona(): TicketAtendimento
    {
        $tenant  = Tenant::factory()->create();
        $persona = SdrPersona::create([
            'tenant_id' => $tenant->id, 'nome_interno' => 'padrao', 'nome_display' => 'Joao',
            'system_prompt' => 'Você é um atendente.', 'ativo' => true, 'is_default' => true, 'tier' => 'simples',
        ]);
        $contato = Contato::factory()->create(['telefone' => '5511988887777']);

        return TicketAtendimento::create([
            'tenant_id' => $tenant->id, 'contato_id' => $contato->id,
            'coluna_kanban' => 'em_atendimento', 'agente_responsavel' => 'bot', 'etapa_ia' => 'etapa_1',
            'status' => 'aberto', 'aberto_em' => now(), 'sdr_persona_id' => $persona->id,
        ]);
    }

    public function test_bloco_de_objetivos_aparece_no_prompt_com_status_correto(): void
    {
        Http::fake(['*' => Http::response(['ok' => true], 200)]);
        $ticket = $this->criarTicketComPersona();

        $obj1 = KanbanColunaObjetivo::create([
            'tenant_id' => $ticket->tenant_id, 'coluna_kanban' => 'em_atendimento',
            'texto' => 'Endereço de origem confirmado', 'ordem' => 1, 'ativo' => true,
        ]);
        $obj2 = KanbanColunaObjetivo::create([
            'tenant_id' => $ticket->tenant_id, 'coluna_kanban' => 'em_atendimento',
            'texto' => 'Lista de itens coletada', 'ordem' => 2, 'ativo' => true,
        ]);

        $ticket->update(['objetivos_cumpridos' => [$obj1->id]]);

        $mensagensCapturadas = null;
        $this->mock(OpenRouterService::class, function ($mock) use (&$mensagensCapturadas) {
            $mock->shouldReceive('chat')
                ->once()
                ->withArgs(function ($messages) use (&$mensagensCapturadas) {
                    $mensagensCapturadas = $messages;
                    return true;
                })
                ->andReturn('Perfeito!');
        });

        app(SdrResponderService::class)->responder($ticket);

        $prompt = $mensagensCapturadas[0]['content'];
        $this->assertStringContainsString("✅ [id:{$obj1->id}] Endereço de origem confirmado", $prompt);
        $this->assertStringContainsString("❌ [id:{$obj2->id}] Lista de itens coletada: pendente", $prompt);
        $this->assertStringContainsString('OBJETIVO_CUMPRIDO', $prompt);
    }

    public function test_bloco_de_objetivos_expoe_o_id_numerico_real_de_cada_objetivo(): void
    {
        // Achado 1 da revisão final: sem o id explícito no prompt, o modelo não tem
        // como saber que número usar em [OBJETIVO_CUMPRIDO:<id>] — ele alucina ou
        // simplesmente não emite o token, e objetivos_cumpridos nunca é preenchido.
        Http::fake(['*' => Http::response(['ok' => true], 200)]);
        $ticket = $this->criarTicketComPersona();

        $objetivo = KanbanColunaObjetivo::create([
            'tenant_id' => $ticket->tenant_id, 'coluna_kanban' => 'em_atendimento',
            'texto' => 'Endereço de origem confirmado', 'ordem' => 1, 'ativo' => true,
        ]);

        $mensagensCapturadas = null;
        $this->mock(OpenRouterService::class, function ($mock) use (&$mensagensCapturadas) {
            $mock->shouldReceive('chat')
                ->once()
                ->withArgs(function ($messages) use (&$mensagensCapturadas) {
                    $mensagensCapturadas = $messages;
                    return true;
                })
                ->andReturn('Perfeito!');
        });

        app(SdrResponderService::class)->responder($ticket);

        $prompt = $mensagensCapturadas[0]['content'];
        $this->assertStringContainsString("[id:{$objetivo->id}]", $prompt);
    }

    public function test_bloco_de_objetivos_nao_aparece_quando_coluna_nao_tem_nenhum(): void
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
                ->andReturn('Perfeito!');
        });

        app(SdrResponderService::class)->responder($ticket);

        $this->assertStringNotContainsString('OBJETIVOS DESTA ETAPA', $mensagensCapturadas[0]['content']);
    }

    public function test_objetivo_inativo_nao_aparece_no_bloco(): void
    {
        Http::fake(['*' => Http::response(['ok' => true], 200)]);
        $ticket = $this->criarTicketComPersona();

        KanbanColunaObjetivo::create([
            'tenant_id' => $ticket->tenant_id, 'coluna_kanban' => 'em_atendimento',
            'texto' => 'Objetivo desativado', 'ordem' => 1, 'ativo' => false,
        ]);

        $mensagensCapturadas = null;
        $this->mock(OpenRouterService::class, function ($mock) use (&$mensagensCapturadas) {
            $mock->shouldReceive('chat')
                ->once()
                ->withArgs(function ($messages) use (&$mensagensCapturadas) {
                    $mensagensCapturadas = $messages;
                    return true;
                })
                ->andReturn('Perfeito!');
        });

        app(SdrResponderService::class)->responder($ticket);

        $this->assertStringNotContainsString('Objetivo desativado', $mensagensCapturadas[0]['content']);
    }
}
