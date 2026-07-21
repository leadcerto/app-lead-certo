<?php

namespace Tests\Feature;

use App\Models\Contato;
use App\Models\Kanban;
use App\Models\KanbanColuna;
use App\Models\KanbanColunaConfig;
use App\Models\SdrPersona;
use App\Models\Tenant;
use App\Models\TicketAtendimento;
use App\Services\OpenRouterService;
use App\Services\SdrResponderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SdrResponderServiceTokenDinamicoTest extends TestCase
{
    use RefreshDatabase;

    public function test_token_de_coluna_renomeada_move_o_ticket_e_aplica_etapa_configurada(): void
    {
        Http::fake(['*' => Http::response(['ok' => true], 200)]);

        $tenant = Tenant::factory()->create(['uazapi_instance_token' => 'tok']);
        $kanban = Kanban::where('tenant_id', $tenant->id)->where('tipo', 'vendas')->firstOrFail();
        KanbanColuna::where('kanban_id', $kanban->id)->where('chave', 'aguardando_orcamento')
            ->update(['chave' => 'esperando_preco']);
        // O tenant de teste (via factory) só ganha as colunas do Kanban (kanban_colunas);
        // a config por coluna (kanban_coluna_configs) não é auto-seedada — precisa ser
        // criada explicitamente, como em outros testes deste projeto (ex: ListaItensImagemTest).
        KanbanColunaConfig::create([
            'tenant_id'         => $tenant->id,
            'coluna_kanban'     => 'esperando_preco',
            'etapa_ia_ao_mover' => 'handoff',
        ]);

        $persona = SdrPersona::create([
            'tenant_id' => $tenant->id, 'nome_interno' => 'padrao', 'nome_display' => 'Joao',
            'system_prompt' => 'Você é um atendente.', 'ativo' => true, 'is_default' => true, 'tier' => 'simples',
        ]);
        $contato = Contato::factory()->create(['telefone' => '5511988887777']);
        $ticket = TicketAtendimento::create([
            'tenant_id' => $tenant->id, 'contato_id' => $contato->id,
            'coluna_kanban' => 'em_atendimento', 'agente_responsavel' => 'bot', 'status' => 'aberto',
            'aberto_em' => now(), 'sdr_persona_id' => $persona->id, 'etapa_ia' => 'etapa_1',
        ]);

        $this->mock(OpenRouterService::class, function ($mock) {
            $mock->shouldReceive('chat')->once()->andReturn('Perfeito, já te retorno! [ESPERANDO_PRECO]');
        });

        app(SdrResponderService::class)->responder($ticket);

        $ticket->refresh();
        $this->assertSame('esperando_preco', $ticket->coluna_kanban);
        $this->assertSame('handoff', $ticket->etapa_ia);
    }
}
