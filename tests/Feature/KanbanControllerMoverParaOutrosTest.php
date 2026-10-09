<?php

namespace Tests\Feature;

use App\Enums\PapelColunaKanban;
use App\Models\Contato;
use App\Models\KanbanColuna;
use App\Models\Tenant;
use App\Models\TicketAtendimento;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class KanbanControllerMoverParaOutrosTest extends TestCase
{
    use RefreshDatabase;

    public function test_move_o_ticket_para_a_coluna_de_transferencia_humana_do_seed_padrao(): void
    {
        $tenant  = Tenant::factory()->create();
        $user    = User::factory()->create(['tenant_id' => $tenant->id, 'perfil' => 'dono', 'ativo' => true]);
        $contato = Contato::factory()->create();
        $ticket  = TicketAtendimento::create([
            'tenant_id' => $tenant->id, 'contato_id' => $contato->id,
            'coluna_kanban' => 'lead_novo', 'agente_responsavel' => 'bot',
            'status' => 'aberto', 'aberto_em' => now(),
        ]);

        $response = $this->actingAs($user)->postJson("/api/painel/kanban/ticket/{$ticket->id}/outros");

        $response->assertOk();
        $response->assertJson(['ticket_id' => $ticket->id, 'coluna_kanban' => 'outros']);

        $ticket->refresh();
        $this->assertSame('outros', $ticket->coluna_kanban);
        $this->assertSame('humano', $ticket->agente_responsavel);
        $this->assertSame($user->id, $ticket->vendedor_id);
    }

    /**
     * Achado da revisão final do plano de kanban_id (08/10/2026):
     * primeiraChaveComPapel(TransferenciaHumana) resolvia tenant-wide — um
     * ticket do Kanban geral podia ser movido pra coluna de Transferência
     * Humana do Kanban do funil, em vez da do próprio Kanban geral.
     */
    public function test_move_para_outros_do_proprio_kanban_mesmo_com_2_kanbans(): void
    {
        $tenant      = Tenant::factory()->create();
        $kanbanGeral = \App\Models\Kanban::where('tenant_id', $tenant->id)->where('tipo', 'vendas')->firstOrFail();
        $kanbanFunil = \App\Models\Kanban::create([
            'tenant_id' => $tenant->id, 'tipo' => 'funil_teste', 'nome' => 'Funil Teste', 'ordem' => 1,
        ]);
        KanbanColuna::create([
            'tenant_id' => $tenant->id, 'kanban_id' => $kanbanFunil->id,
            'chave' => 'funil_outros', 'label' => 'Outros do Funil',
            'papel' => PapelColunaKanban::TransferenciaHumana, 'ordem' => 1,
        ]);

        $user    = User::factory()->create(['tenant_id' => $tenant->id, 'perfil' => 'dono', 'ativo' => true]);
        $contato = Contato::factory()->create();
        $ticket  = TicketAtendimento::create([
            'tenant_id' => $tenant->id, 'kanban_id' => $kanbanGeral->id, 'contato_id' => $contato->id,
            'coluna_kanban' => 'lead_novo', 'agente_responsavel' => 'bot',
            'status' => 'aberto', 'aberto_em' => now(),
        ]);

        $response = $this->actingAs($user)->postJson("/api/painel/kanban/ticket/{$ticket->id}/outros");

        $response->assertOk();
        $this->assertSame('outros', $ticket->fresh()->coluna_kanban);
    }

    public function test_move_para_a_chave_renomeada_quando_a_coluna_de_transferencia_humana_nao_se_chama_outros(): void
    {
        $tenant  = Tenant::factory()->create();
        $user    = User::factory()->create(['tenant_id' => $tenant->id, 'perfil' => 'dono', 'ativo' => true]);
        $contato = Contato::factory()->create();
        $ticket  = TicketAtendimento::create([
            'tenant_id' => $tenant->id, 'contato_id' => $contato->id,
            'coluna_kanban' => 'lead_novo', 'agente_responsavel' => 'bot',
            'status' => 'aberto', 'aberto_em' => now(),
        ]);

        KanbanColuna::where('tenant_id', $tenant->id)
            ->where('papel', PapelColunaKanban::TransferenciaHumana)
            ->firstOrFail()
            ->update(['chave' => 'time_humano']);

        $response = $this->actingAs($user)->postJson("/api/painel/kanban/ticket/{$ticket->id}/outros");

        $response->assertOk();
        $response->assertJson(['ticket_id' => $ticket->id, 'coluna_kanban' => 'time_humano']);

        $ticket->refresh();
        $this->assertSame('time_humano', $ticket->coluna_kanban);
        $this->assertSame('humano', $ticket->agente_responsavel);
    }

    public function test_retorna_422_quando_nenhuma_coluna_tem_papel_transferencia_humana(): void
    {
        $tenant  = Tenant::factory()->create();
        $user    = User::factory()->create(['tenant_id' => $tenant->id, 'perfil' => 'dono', 'ativo' => true]);
        $contato = Contato::factory()->create();
        $ticket  = TicketAtendimento::create([
            'tenant_id' => $tenant->id, 'contato_id' => $contato->id,
            'coluna_kanban' => 'lead_novo', 'agente_responsavel' => 'bot',
            'status' => 'aberto', 'aberto_em' => now(),
        ]);

        KanbanColuna::where('tenant_id', $tenant->id)
            ->where('papel', PapelColunaKanban::TransferenciaHumana)
            ->delete();
        KanbanColuna::limparCache($tenant->id);

        $response = $this->actingAs($user)->postJson("/api/painel/kanban/ticket/{$ticket->id}/outros");

        $response->assertStatus(422);
        $response->assertJson(['message' => 'Nenhuma coluna de Transferência Humana configurada.']);

        $ticket->refresh();
        $this->assertSame('lead_novo', $ticket->coluna_kanban);
        $this->assertSame('bot', $ticket->agente_responsavel);
        $this->assertNull($ticket->vendedor_id);
    }

    public function test_mover_para_outros_grava_origem_humano_no_historico(): void
    {
        $tenant  = Tenant::factory()->create();
        $user    = User::factory()->create(['tenant_id' => $tenant->id, 'perfil' => 'dono', 'ativo' => true]);
        $contato = Contato::factory()->create();
        $ticket  = TicketAtendimento::create([
            'tenant_id' => $tenant->id, 'contato_id' => $contato->id,
            'coluna_kanban' => 'lead_novo', 'agente_responsavel' => 'bot',
            'status' => 'aberto', 'aberto_em' => now(),
        ]);

        $this->actingAs($user)->postJson("/api/painel/kanban/ticket/{$ticket->id}/outros")->assertOk();

        $this->assertDatabaseHas('kanban_coluna_historico', [
            'ticket_id' => $ticket->id, 'coluna' => 'outros', 'origem' => 'humano',
        ]);
    }
}
