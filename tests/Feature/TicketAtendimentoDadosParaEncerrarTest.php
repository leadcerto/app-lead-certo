<?php

namespace Tests\Feature;

use App\Enums\PapelColunaKanban;
use App\Models\Contato;
use App\Models\Kanban;
use App\Models\KanbanColuna;
use App\Models\Tenant;
use App\Models\TicketAtendimento;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TicketAtendimentoDadosParaEncerrarTest extends TestCase
{
    use RefreshDatabase;

    public function test_dados_para_encerrar_usa_a_coluna_de_papel_encerramento_do_tenant(): void
    {
        $tenant = Tenant::factory()->create();
        $contato = Contato::factory()->create();
        $ticket = TicketAtendimento::create([
            'tenant_id' => $tenant->id, 'contato_id' => $contato->id,
            'coluna_kanban' => 'em_atendimento', 'agente_responsavel' => 'bot', 'status' => 'aberto', 'aberto_em' => now(),
        ]);

        $dados = $ticket->dadosParaEncerrar();

        $this->assertSame('encerrado', $dados['coluna_kanban']);
        $this->assertSame('encerrado', $dados['status']);
        $this->assertSame('em_atendimento', $dados['coluna_antes_encerrar']);
    }

    public function test_dados_para_encerrar_aceita_coluna_destino_explicita_quando_ha_mais_de_uma_de_encerramento(): void
    {
        $tenant = Tenant::factory()->create();
        $kanban = Kanban::where('tenant_id', $tenant->id)->where('tipo', 'vendas')->firstOrFail();
        KanbanColuna::create([
            'tenant_id' => $tenant->id, 'kanban_id' => $kanban->id,
            'chave' => 'perdido', 'label' => 'Perdido', 'papel' => PapelColunaKanban::Encerramento, 'ordem' => 99,
        ]);
        $contato = Contato::factory()->create();
        $ticket = TicketAtendimento::create([
            'tenant_id' => $tenant->id, 'contato_id' => $contato->id,
            'coluna_kanban' => 'em_atendimento', 'agente_responsavel' => 'bot', 'status' => 'aberto', 'aberto_em' => now(),
        ]);

        $dados = $ticket->dadosParaEncerrar([], 'perdido');

        $this->assertSame('perdido', $dados['coluna_kanban']);
    }
}
