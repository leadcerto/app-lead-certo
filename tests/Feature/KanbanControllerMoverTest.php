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

class KanbanControllerMoverTest extends TestCase
{
    use RefreshDatabase;

    public function test_move_o_ticket_para_a_coluna_escolhida(): void
    {
        $tenant  = Tenant::factory()->create();
        $user    = User::factory()->create(['tenant_id' => $tenant->id, 'perfil' => 'dono', 'ativo' => true]);
        $contato = Contato::factory()->create();
        $ticket  = TicketAtendimento::create([
            'tenant_id' => $tenant->id, 'contato_id' => $contato->id,
            'coluna_kanban' => 'lead_novo', 'agente_responsavel' => 'bot',
            'status' => 'aberto', 'aberto_em' => now(),
        ]);

        $response = $this->actingAs($user)->postJson("/api/painel/kanban/ticket/{$ticket->id}/mover", [
            'coluna' => 'encerrado',
        ]);

        $response->assertOk();
        $this->assertSame('encerrado', $ticket->fresh()->coluna_kanban);
    }

    public function test_mover_para_fora_de_encerrado_reabre_o_status(): void
    {
        $tenant  = Tenant::factory()->create();
        $user    = User::factory()->create(['tenant_id' => $tenant->id, 'perfil' => 'dono', 'ativo' => true]);
        $contato = Contato::factory()->create();
        $ticket  = TicketAtendimento::create([
            'tenant_id' => $tenant->id, 'contato_id' => $contato->id,
            'coluna_kanban' => 'encerrado', 'agente_responsavel' => 'humano',
            'status' => 'encerrado', 'aberto_em' => now(), 'encerrado_em' => now(),
        ]);

        $response = $this->actingAs($user)->postJson("/api/painel/kanban/ticket/{$ticket->id}/mover", [
            'coluna' => 'lead_novo',
        ]);

        $response->assertOk();
        $ticket->refresh();
        $this->assertSame('lead_novo', $ticket->coluna_kanban);
        $this->assertSame('aberto', $ticket->status);
    }

    public function test_mover_para_fora_de_encerrado_reabre_o_status_mesmo_com_a_coluna_renomeada(): void
    {
        $tenant  = Tenant::factory()->create();
        $user    = User::factory()->create(['tenant_id' => $tenant->id, 'perfil' => 'dono', 'ativo' => true]);
        $kanban  = Kanban::where('tenant_id', $tenant->id)->where('tipo', 'vendas')->firstOrFail();
        // Franqueado renomeou a coluna de Encerramento de 'encerrado' para 'finalizado'
        KanbanColuna::where('kanban_id', $kanban->id)->where('papel', PapelColunaKanban::Encerramento)
            ->update(['chave' => 'finalizado']);

        $contato = Contato::factory()->create();
        $ticket  = TicketAtendimento::create([
            'tenant_id' => $tenant->id, 'contato_id' => $contato->id,
            'coluna_kanban' => 'finalizado', 'agente_responsavel' => 'humano',
            'status' => 'encerrado', 'aberto_em' => now(), 'encerrado_em' => now(),
        ]);

        $response = $this->actingAs($user)->postJson("/api/painel/kanban/ticket/{$ticket->id}/mover", [
            'coluna' => 'lead_novo',
        ]);

        $response->assertOk();
        $ticket->refresh();
        $this->assertSame('lead_novo', $ticket->coluna_kanban);
        // Sem o fix, a comparação literal '=== encerrado' não reconhece 'finalizado' como
        // Encerramento e o status fica preso em 'encerrado', escondendo a caixa de mensagem.
        $this->assertSame('aberto', $ticket->status);
    }

    public function test_mover_entre_colunas_ativas_nao_mexe_no_status(): void
    {
        $tenant  = Tenant::factory()->create();
        $user    = User::factory()->create(['tenant_id' => $tenant->id, 'perfil' => 'dono', 'ativo' => true]);
        $contato = Contato::factory()->create();
        $ticket  = TicketAtendimento::create([
            'tenant_id' => $tenant->id, 'contato_id' => $contato->id,
            'coluna_kanban' => 'lead_novo', 'agente_responsavel' => 'bot',
            'status' => 'aguardando', 'aberto_em' => now(),
        ]);

        $this->actingAs($user)->postJson("/api/painel/kanban/ticket/{$ticket->id}/mover", [
            'coluna' => 'em_atendimento',
        ]);

        $this->assertSame('aguardando', $ticket->fresh()->status);
    }

    public function test_mover_zera_objetivos_cumpridos_da_coluna_anterior(): void
    {
        $tenant  = Tenant::factory()->create();
        $user    = User::factory()->create(['tenant_id' => $tenant->id, 'perfil' => 'dono', 'ativo' => true]);
        $contato = Contato::factory()->create();
        $ticket  = TicketAtendimento::create([
            'tenant_id' => $tenant->id, 'contato_id' => $contato->id,
            'coluna_kanban' => 'lead_novo', 'agente_responsavel' => 'bot',
            'status' => 'aberto', 'aberto_em' => now(), 'objetivos_cumpridos' => [1, 2],
        ]);

        $response = $this->actingAs($user)->postJson("/api/painel/kanban/ticket/{$ticket->id}/mover", [
            'coluna' => 'em_atendimento',
        ]);

        $response->assertOk();
        $this->assertSame([], $ticket->fresh()->objetivos_cumpridos);
    }

    public function test_rejeita_coluna_invalida(): void
    {
        $tenant  = Tenant::factory()->create();
        $user    = User::factory()->create(['tenant_id' => $tenant->id, 'perfil' => 'dono', 'ativo' => true]);
        $contato = Contato::factory()->create();
        $ticket  = TicketAtendimento::create([
            'tenant_id' => $tenant->id, 'contato_id' => $contato->id,
            'coluna_kanban' => 'lead_novo', 'agente_responsavel' => 'bot',
            'status' => 'aberto', 'aberto_em' => now(),
        ]);

        $response = $this->actingAs($user)->postJson("/api/painel/kanban/ticket/{$ticket->id}/mover", [
            'coluna' => 'coluna_que_nao_existe',
        ]);

        $response->assertStatus(422);
        $this->assertSame('lead_novo', $ticket->fresh()->coluna_kanban);
    }

    public function test_mover_manualmente_grava_origem_humano_no_historico(): void
    {
        $tenant  = Tenant::factory()->create();
        $user    = User::factory()->create(['tenant_id' => $tenant->id, 'perfil' => 'dono', 'ativo' => true]);
        $contato = Contato::factory()->create();
        $ticket  = TicketAtendimento::create([
            'tenant_id' => $tenant->id, 'contato_id' => $contato->id,
            'coluna_kanban' => 'lead_novo', 'agente_responsavel' => 'bot',
            'status' => 'aberto', 'aberto_em' => now(),
        ]);

        $this->actingAs($user)->postJson("/api/painel/kanban/ticket/{$ticket->id}/mover", [
            'coluna' => 'em_atendimento',
        ])->assertOk();

        $this->assertDatabaseHas('kanban_coluna_historico', [
            'ticket_id' => $ticket->id, 'coluna' => 'em_atendimento', 'origem' => 'humano',
        ]);
    }
}
