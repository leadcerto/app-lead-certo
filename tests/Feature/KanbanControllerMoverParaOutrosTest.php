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
}
