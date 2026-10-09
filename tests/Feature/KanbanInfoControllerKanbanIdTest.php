<?php

namespace Tests\Feature;

use App\Models\Kanban;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class KanbanInfoControllerKanbanIdTest extends TestCase
{
    use RefreshDatabase;

    public function test_show_com_kanban_id_retorna_nome_do_kanban_certo(): void
    {
        $tenant = Tenant::factory()->create();
        $user   = User::factory()->create(['tenant_id' => $tenant->id, 'perfil' => 'dono', 'ativo' => true]);
        $kanbanFunil = Kanban::create([
            'tenant_id' => $tenant->id, 'tipo' => 'funil_teste', 'nome' => 'Funil de Qualificação',
            'nome_curto' => 'Funil', 'ordem' => 1,
        ]);

        $response = $this->actingAs($user)->getJson('/api/painel/kanban/info?kanban_id=' . $kanbanFunil->id);

        $response->assertOk();
        $response->assertJson(['nome' => 'Funil de Qualificação']);
    }

    public function test_update_com_kanban_id_atualiza_o_kanban_certo(): void
    {
        $tenant = Tenant::factory()->create();
        $user   = User::factory()->create(['tenant_id' => $tenant->id, 'perfil' => 'dono', 'ativo' => true]);
        $kanbanFunil = Kanban::create([
            'tenant_id' => $tenant->id, 'tipo' => 'funil_teste', 'nome' => 'Funil',
            'nome_curto' => 'Funil', 'ordem' => 1,
        ]);
        $kanbanGeral = Kanban::where('tenant_id', $tenant->id)->where('tipo', 'vendas')->firstOrFail();

        $this->actingAs($user)->putJson('/api/painel/kanban/info?kanban_id=' . $kanbanFunil->id, [
            'nome' => 'Funil Renomeado',
        ])->assertOk();

        $this->assertSame('Funil Renomeado', $kanbanFunil->fresh()->nome);
        $this->assertNotSame('Funil Renomeado', $kanbanGeral->fresh()->nome);
    }

    public function test_show_retorna_nome_curto(): void
    {
        $tenant = Tenant::factory()->create();
        $user   = User::factory()->create(['tenant_id' => $tenant->id, 'perfil' => 'dono', 'ativo' => true]);
        $kanbanFunil = Kanban::create([
            'tenant_id' => $tenant->id, 'tipo' => 'funil_teste', 'nome' => 'Funil de Qualificação',
            'nome_curto' => 'Funil', 'ordem' => 1,
        ]);

        $response = $this->actingAs($user)->getJson('/api/painel/kanban/info?kanban_id=' . $kanbanFunil->id);

        $response->assertOk();
        $response->assertJson(['nome_curto' => 'Funil']);
    }

    public function test_update_altera_nome_curto_do_kanban_certo(): void
    {
        $tenant = Tenant::factory()->create();
        $user   = User::factory()->create(['tenant_id' => $tenant->id, 'perfil' => 'dono', 'ativo' => true]);
        $kanbanFunil = Kanban::create([
            'tenant_id' => $tenant->id, 'tipo' => 'funil_teste', 'nome' => 'Funil',
            'nome_curto' => 'Funil', 'ordem' => 1,
        ]);
        $kanbanGeral = Kanban::where('tenant_id', $tenant->id)->where('tipo', 'vendas')->firstOrFail();

        $this->actingAs($user)->putJson('/api/painel/kanban/info?kanban_id=' . $kanbanFunil->id, [
            'nome_curto' => 'Imersão',
        ])->assertOk();

        $this->assertSame('Imersão', $kanbanFunil->fresh()->nome_curto);
        $this->assertNotSame('Imersão', $kanbanGeral->fresh()->nome_curto);
    }

    public function test_update_rejeita_nome_curto_com_espaco(): void
    {
        $tenant = Tenant::factory()->create();
        $user   = User::factory()->create(['tenant_id' => $tenant->id, 'perfil' => 'dono', 'ativo' => true]);

        $this->actingAs($user)->putJson('/api/painel/kanban/info', [
            'nome_curto' => 'Nome Com Espaço',
        ])->assertStatus(422);
    }
}
