<?php

namespace Tests\Feature;

use App\Models\Contato;
use App\Models\GestorKanbanConfig;
use App\Models\Tenant;
use App\Models\TicketAtendimento;
use App\Services\GestorKanbanService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GestorKanbanServiceAnaliseTest extends TestCase
{
    use RefreshDatabase;

    public function test_analisar_coluna_parseia_analise_e_sugestao_da_resposta_da_ia(): void
    {
        Http::fake(['*' => Http::response([
            'choices' => [['message' => ['content' =>
                "ANÁLISE:\nO gargalo é o preço não ser respondido rápido.\n\nSUGESTÃO_PROMPT:\nSempre responda o valor do frete em até 2 mensagens."
            ]]],
            'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 20],
        ], 200)]);

        $tenant  = Tenant::factory()->create();
        $config  = GestorKanbanConfig::first();
        $numeros = ['entradas' => 5, 'avancos' => 2, 'travados' => 3, 'tag_desfecho_breakdown' => []];

        $contato = Contato::factory()->create();
        $ticket  = TicketAtendimento::create([
            'tenant_id' => $tenant->id, 'contato_id' => $contato->id,
            'coluna_kanban' => 'em_atendimento', 'agente_responsavel' => 'bot',
            'status' => 'aberto', 'aberto_em' => now(),
        ]);
        $amostras = collect([$ticket]);

        $resultado = app(GestorKanbanService::class)->analisarColuna($tenant, 'em_atendimento', $numeros, $amostras, $config);

        $this->assertSame('O gargalo é o preço não ser respondido rápido.', $resultado['analise']);
        $this->assertSame('Sempre responda o valor do frete em até 2 mensagens.', $resultado['sugestao_prompt']);
    }

    public function test_analisar_coluna_retorna_nulos_quando_ia_falha(): void
    {
        Http::fake(['*' => Http::response(['error' => 'falha'], 500)]);

        $tenant  = Tenant::factory()->create();
        $config  = GestorKanbanConfig::first();
        $numeros = ['entradas' => 5, 'avancos' => 2, 'travados' => 3, 'tag_desfecho_breakdown' => []];

        $resultado = app(GestorKanbanService::class)->analisarColuna($tenant, 'em_atendimento', $numeros, collect(), $config);

        $this->assertNull($resultado['analise']);
        $this->assertNull($resultado['sugestao_prompt']);
    }
}
