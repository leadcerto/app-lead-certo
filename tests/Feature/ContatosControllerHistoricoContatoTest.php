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

class ContatosControllerHistoricoContatoTest extends TestCase
{
    use RefreshDatabase;

    public function test_historico_mostra_o_label_real_de_uma_coluna_customizada(): void
    {
        $tenant  = Tenant::factory()->create();
        $user    = User::factory()->create(['tenant_id' => $tenant->id, 'perfil' => 'dono', 'ativo' => true]);
        $kanban  = Kanban::where('tenant_id', $tenant->id)->where('tipo', 'vendas')->firstOrFail();
        KanbanColuna::create([
            'tenant_id' => $tenant->id, 'kanban_id' => $kanban->id,
            'chave' => 'triagem_extra', 'label' => 'Minha Triagem Especial',
            'papel' => PapelColunaKanban::EmAndamento, 'ordem' => 99,
        ]);
        $contato = Contato::factory()->create();
        TicketAtendimento::create([
            'tenant_id' => $tenant->id, 'contato_id' => $contato->id,
            'coluna_kanban' => 'triagem_extra', 'agente_responsavel' => 'bot', 'status' => 'aberto', 'aberto_em' => now(),
        ]);

        $response = $this->actingAs($user)->getJson("/api/painel/contato/{$contato->id}/historico");

        $response->assertOk();
        $this->assertSame('Minha Triagem Especial', $response->json('0.coluna'));
    }
}
