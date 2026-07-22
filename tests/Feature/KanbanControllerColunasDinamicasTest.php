<?php

namespace Tests\Feature;

use App\Enums\PapelColunaKanban;
use App\Models\KanbanColuna;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class KanbanControllerColunasDinamicasTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_retorna_colunas_do_tenant_com_label_emoji_e_papel(): void
    {
        $tenant = Tenant::factory()->create();
        $user   = User::factory()->create(['tenant_id' => $tenant->id, 'perfil' => 'dono', 'ativo' => true]);

        $response = $this->actingAs($user)->getJson('/api/painel/kanban/tickets');

        $response->assertOk();
        $colunas = collect($response->json('colunas'));
        $this->assertSame('lead_novo', $colunas->first()['chave']);
        $this->assertSame('Novo', $colunas->first()['label']);
        $this->assertSame('entrada', $colunas->first()['papel']);
    }

    public function test_mover_aceita_coluna_customizada_criada_pelo_franqueado(): void
    {
        $tenant = Tenant::factory()->create();
        $user   = User::factory()->create(['tenant_id' => $tenant->id, 'perfil' => 'dono', 'ativo' => true]);
        $kanban = \App\Models\Kanban::where('tenant_id', $tenant->id)->where('tipo', 'vendas')->firstOrFail();
        KanbanColuna::create([
            'tenant_id' => $tenant->id, 'kanban_id' => $kanban->id,
            'chave' => 'coluna_customizada', 'label' => 'Minha Coluna', 'papel' => PapelColunaKanban::EmAndamento, 'ordem' => 99,
        ]);
        $contato = \App\Models\Contato::factory()->create();
        $ticket  = \App\Models\TicketAtendimento::create([
            'tenant_id' => $tenant->id, 'contato_id' => $contato->id,
            'coluna_kanban' => 'lead_novo', 'agente_responsavel' => 'bot', 'status' => 'aberto', 'aberto_em' => now(),
        ]);

        $response = $this->actingAs($user)->postJson("/api/painel/kanban/ticket/{$ticket->id}/mover", [
            'coluna' => 'coluna_customizada',
        ]);

        $response->assertOk();
        $this->assertSame('coluna_customizada', $ticket->fresh()->coluna_kanban);
    }
}
