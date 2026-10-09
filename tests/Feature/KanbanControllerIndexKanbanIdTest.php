<?php

namespace Tests\Feature;

use App\Enums\PapelColunaKanban;
use App\Models\Contato;
use App\Models\Kanban;
use App\Models\KanbanColuna;
use App\Models\Tenant;
use App\Models\TicketAtendimento;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class KanbanControllerIndexKanbanIdTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_com_kanban_id_mostra_so_os_tickets_e_colunas_daquele_kanban(): void
    {
        $tenant = Tenant::factory()->create();
        $user   = User::factory()->create(['tenant_id' => $tenant->id, 'perfil' => 'dono', 'ativo' => true]);
        $kanbanGeral = Kanban::where('tenant_id', $tenant->id)->where('tipo', 'vendas')->firstOrFail();
        $kanbanFunil = Kanban::create([
            'tenant_id' => $tenant->id, 'tipo' => 'funil_teste', 'nome' => 'Funil',
            'nome_curto' => 'Funil', 'ordem' => 1,
        ]);
        // Chave DELIBERADAMENTE igual à de um papel distinto do Kanban geral,
        // mas com um nome de coluna que só existe no funil, pra provar que o
        // board não mistura as colunas/tickets dos dois Kanbans.
        KanbanColuna::create([
            'tenant_id' => $tenant->id, 'kanban_id' => $kanbanFunil->id,
            'chave' => 'funil_novo', 'label' => 'Novo do Funil',
            'papel' => PapelColunaKanban::Entrada, 'ordem' => 1,
        ]);

        $contatoGeral = Contato::factory()->create();
        TicketAtendimento::create([
            'tenant_id' => $tenant->id, 'kanban_id' => $kanbanGeral->id, 'contato_id' => $contatoGeral->id,
            'coluna_kanban' => 'lead_novo', 'agente_responsavel' => 'bot', 'status' => 'aberto', 'aberto_em' => now(),
        ]);
        $contatoFunil = Contato::factory()->create();
        TicketAtendimento::create([
            'tenant_id' => $tenant->id, 'kanban_id' => $kanbanFunil->id, 'contato_id' => $contatoFunil->id,
            'coluna_kanban' => 'funil_novo', 'agente_responsavel' => 'bot', 'status' => 'aberto', 'aberto_em' => now(),
        ]);

        $response = $this->actingAs($user)->getJson('/api/painel/kanban/tickets?kanban_id=' . $kanbanFunil->id);

        $response->assertOk();
        $corpo = $response->json();

        $this->assertArrayNotHasKey('lead_novo', $corpo);
        $this->assertArrayHasKey('funil_novo', $corpo);
        $this->assertCount(1, $corpo['funil_novo']['tickets']);

        $colunasRetornadas = collect($corpo['colunas'])->pluck('chave')->all();
        $this->assertSame(['funil_novo'], $colunasRetornadas);
    }

    public function test_index_sem_kanban_id_continua_mostrando_o_kanban_vendas(): void
    {
        $tenant = Tenant::factory()->create();
        $user   = User::factory()->create(['tenant_id' => $tenant->id, 'perfil' => 'dono', 'ativo' => true]);
        Kanban::create([
            'tenant_id' => $tenant->id, 'tipo' => 'funil_teste', 'nome' => 'Funil',
            'nome_curto' => 'Funil', 'ordem' => 1,
        ]);

        $response = $this->actingAs($user)->getJson('/api/painel/kanban/tickets');

        $response->assertOk();
        $this->assertArrayHasKey('lead_novo', $response->json());
    }
}
