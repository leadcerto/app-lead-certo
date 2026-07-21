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

class KanbanColunaControllerTest extends TestCase
{
    use RefreshDatabase;

    private function usuarioDono(Tenant $tenant): User
    {
        return User::factory()->create(['tenant_id' => $tenant->id, 'perfil' => 'dono', 'ativo' => true]);
    }

    public function test_lista_papeis_disponiveis(): void
    {
        $tenant = Tenant::factory()->create();
        $user   = $this->usuarioDono($tenant);

        $response = $this->actingAs($user)->getJson('/api/painel/kanban/papeis');

        $response->assertOk();
        $this->assertCount(4, $response->json());
        $this->assertSame('entrada', $response->json('0.value'));
    }

    public function test_cria_coluna_nova(): void
    {
        $tenant = Tenant::factory()->create();
        $user   = $this->usuarioDono($tenant);

        $response = $this->actingAs($user)->postJson('/api/painel/kanban/colunas', [
            'label' => 'Minha Coluna', 'emoji' => '⭐', 'papel' => 'em_andamento',
        ]);

        $response->assertCreated();
        $this->assertDatabaseHas('kanban_colunas', [
            'tenant_id' => $tenant->id, 'label' => 'Minha Coluna', 'chave' => 'minha_coluna', 'papel' => 'em_andamento',
        ]);
    }

    public function test_edita_coluna_existente(): void
    {
        $tenant = Tenant::factory()->create();
        $user   = $this->usuarioDono($tenant);
        $coluna = KanbanColuna::where('tenant_id', $tenant->id)->where('chave', 'em_atendimento')->firstOrFail();

        $response = $this->actingAs($user)->putJson("/api/painel/kanban/colunas/{$coluna->id}", [
            'label' => 'Novo Nome', 'emoji' => '🆕', 'papel' => 'em_andamento',
        ]);

        $response->assertOk();
        $this->assertSame('Novo Nome', $coluna->fresh()->label);
    }

    public function test_reordena_colunas(): void
    {
        $tenant  = Tenant::factory()->create();
        $user    = $this->usuarioDono($tenant);
        $colunas = KanbanColuna::where('tenant_id', $tenant->id)->orderBy('ordem')->get();
        $idsInvertidos = $colunas->pluck('id')->reverse()->values()->all();

        $response = $this->actingAs($user)->postJson('/api/painel/kanban/colunas/reordenar', [
            'ids' => $idsInvertidos,
        ]);

        $response->assertOk();
        $this->assertSame($idsInvertidos, KanbanColuna::where('tenant_id', $tenant->id)->orderBy('ordem')->pluck('id')->all());
    }

    public function test_bloqueia_exclusao_de_coluna_com_ticket_ativo(): void
    {
        $tenant  = Tenant::factory()->create();
        $user    = $this->usuarioDono($tenant);
        $coluna  = KanbanColuna::where('tenant_id', $tenant->id)->where('chave', 'em_atendimento')->firstOrFail();
        $contato = Contato::factory()->create();
        TicketAtendimento::create([
            'tenant_id' => $tenant->id, 'contato_id' => $contato->id,
            'coluna_kanban' => 'em_atendimento', 'agente_responsavel' => 'bot', 'status' => 'aberto', 'aberto_em' => now(),
        ]);

        $response = $this->actingAs($user)->deleteJson("/api/painel/kanban/colunas/{$coluna->id}");

        $response->assertStatus(422);
        $this->assertDatabaseHas('kanban_colunas', ['id' => $coluna->id]);
    }

    public function test_exclui_coluna_sem_ticket(): void
    {
        $tenant = Tenant::factory()->create();
        $user   = $this->usuarioDono($tenant);
        $kanban = Kanban::where('tenant_id', $tenant->id)->where('tipo', 'vendas')->firstOrFail();
        $coluna = KanbanColuna::create([
            'tenant_id' => $tenant->id, 'kanban_id' => $kanban->id,
            'chave' => 'vazia', 'label' => 'Vazia', 'papel' => PapelColunaKanban::EmAndamento, 'ordem' => 99,
        ]);

        $response = $this->actingAs($user)->deleteJson("/api/painel/kanban/colunas/{$coluna->id}");

        $response->assertOk();
        $this->assertDatabaseMissing('kanban_colunas', ['id' => $coluna->id]);
    }

    public function test_bloqueia_criar_segunda_coluna_de_entrada(): void
    {
        $tenant = Tenant::factory()->create();
        $user   = $this->usuarioDono($tenant);

        $response = $this->actingAs($user)->postJson('/api/painel/kanban/colunas', [
            'label' => 'Outra Entrada', 'emoji' => '🟢', 'papel' => 'entrada',
        ]);

        $response->assertStatus(422);
        $this->assertDatabaseMissing('kanban_colunas', ['tenant_id' => $tenant->id, 'label' => 'Outra Entrada']);
    }

    public function test_bloqueia_editar_coluna_para_entrada_quando_ja_existe_uma(): void
    {
        $tenant = Tenant::factory()->create();
        $user   = $this->usuarioDono($tenant);
        $coluna = KanbanColuna::where('tenant_id', $tenant->id)->where('chave', 'em_atendimento')->firstOrFail();

        $response = $this->actingAs($user)->putJson("/api/painel/kanban/colunas/{$coluna->id}", [
            'label' => 'Em Atendimento', 'emoji' => '🔵', 'papel' => 'entrada',
        ]);

        $response->assertStatus(422);
        $this->assertSame('em_andamento', $coluna->fresh()->papel->value);
    }

    public function test_permite_resalvar_a_propria_coluna_de_entrada_sem_bloqueio(): void
    {
        $tenant = Tenant::factory()->create();
        $user   = $this->usuarioDono($tenant);
        $coluna = KanbanColuna::where('tenant_id', $tenant->id)->where('chave', 'lead_novo')->firstOrFail();

        $response = $this->actingAs($user)->putJson("/api/painel/kanban/colunas/{$coluna->id}", [
            'label' => 'Novo Lead', 'emoji' => '🟢', 'papel' => 'entrada',
        ]);

        $response->assertOk();
        $this->assertSame('Novo Lead', $coluna->fresh()->label);
        $this->assertSame('entrada', $coluna->fresh()->papel->value);
    }
}
