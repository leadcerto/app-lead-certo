<?php
// tests/Feature/KanbanColunaObjetivoControllerTest.php
namespace Tests\Feature;

use App\Models\KanbanColunaObjetivo;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class KanbanColunaObjetivoControllerTest extends TestCase
{
    use RefreshDatabase;

    private function criarUsuarioDono(Tenant $tenant): User
    {
        return User::factory()->create(['tenant_id' => $tenant->id, 'perfil' => 'dono', 'ativo' => true]);
    }

    public function test_lista_objetivos_da_coluna_em_ordem(): void
    {
        $tenant = Tenant::factory()->create();
        $user   = $this->criarUsuarioDono($tenant);
        KanbanColunaObjetivo::create(['tenant_id' => $tenant->id, 'coluna_kanban' => 'em_atendimento', 'texto' => 'Segundo', 'ordem' => 2, 'ativo' => true]);
        KanbanColunaObjetivo::create(['tenant_id' => $tenant->id, 'coluna_kanban' => 'em_atendimento', 'texto' => 'Primeiro', 'ordem' => 1, 'ativo' => true]);

        $response = $this->actingAs($user)->getJson('/api/painel/kanban/coluna-objetivos/em_atendimento');

        $response->assertOk();
        $response->assertJsonPath('0.texto', 'Primeiro');
        $response->assertJsonPath('1.texto', 'Segundo');
    }

    public function test_cria_objetivo_com_ordem_incremental(): void
    {
        $tenant = Tenant::factory()->create();
        $user   = $this->criarUsuarioDono($tenant);
        KanbanColunaObjetivo::create(['tenant_id' => $tenant->id, 'coluna_kanban' => 'em_atendimento', 'texto' => 'Existente', 'ordem' => 1, 'ativo' => true]);

        $response = $this->actingAs($user)->postJson('/api/painel/kanban/coluna-objetivos/em_atendimento', [
            'texto' => 'Novo objetivo',
        ]);

        $response->assertCreated();
        $this->assertDatabaseHas('kanban_coluna_objetivos', [
            'tenant_id' => $tenant->id, 'coluna_kanban' => 'em_atendimento', 'texto' => 'Novo objetivo', 'ordem' => 2,
        ]);
    }

    public function test_atualiza_texto_e_ativo(): void
    {
        $tenant   = Tenant::factory()->create();
        $user     = $this->criarUsuarioDono($tenant);
        $objetivo = KanbanColunaObjetivo::create(['tenant_id' => $tenant->id, 'coluna_kanban' => 'em_atendimento', 'texto' => 'Antigo', 'ordem' => 1, 'ativo' => true]);

        $response = $this->actingAs($user)->putJson("/api/painel/kanban/coluna-objetivos/em_atendimento/{$objetivo->id}", [
            'texto' => 'Atualizado', 'ativo' => false,
        ]);

        $response->assertOk();
        $this->assertSame('Atualizado', $objetivo->fresh()->texto);
        $this->assertFalse($objetivo->fresh()->ativo);
    }

    public function test_exclui_e_reordena_os_restantes(): void
    {
        $tenant = Tenant::factory()->create();
        $user   = $this->criarUsuarioDono($tenant);
        $obj1 = KanbanColunaObjetivo::create(['tenant_id' => $tenant->id, 'coluna_kanban' => 'em_atendimento', 'texto' => 'Um', 'ordem' => 1, 'ativo' => true]);
        $obj2 = KanbanColunaObjetivo::create(['tenant_id' => $tenant->id, 'coluna_kanban' => 'em_atendimento', 'texto' => 'Dois', 'ordem' => 2, 'ativo' => true]);
        $obj3 = KanbanColunaObjetivo::create(['tenant_id' => $tenant->id, 'coluna_kanban' => 'em_atendimento', 'texto' => 'Três', 'ordem' => 3, 'ativo' => true]);

        $response = $this->actingAs($user)->deleteJson("/api/painel/kanban/coluna-objetivos/em_atendimento/{$obj1->id}");

        $response->assertOk();
        $this->assertDatabaseMissing('kanban_coluna_objetivos', ['id' => $obj1->id]);
        $this->assertSame(1, $obj2->fresh()->ordem);
        $this->assertSame(2, $obj3->fresh()->ordem);
    }

    public function test_reordenar_aplica_nova_ordem(): void
    {
        $tenant = Tenant::factory()->create();
        $user   = $this->criarUsuarioDono($tenant);
        $obj1 = KanbanColunaObjetivo::create(['tenant_id' => $tenant->id, 'coluna_kanban' => 'em_atendimento', 'texto' => 'Um', 'ordem' => 1, 'ativo' => true]);
        $obj2 = KanbanColunaObjetivo::create(['tenant_id' => $tenant->id, 'coluna_kanban' => 'em_atendimento', 'texto' => 'Dois', 'ordem' => 2, 'ativo' => true]);

        $response = $this->actingAs($user)->postJson('/api/painel/kanban/coluna-objetivos/em_atendimento/reordenar', [
            'ids' => [$obj2->id, $obj1->id],
        ]);

        $response->assertOk();
        $this->assertSame(1, $obj2->fresh()->ordem);
        $this->assertSame(2, $obj1->fresh()->ordem);
    }

    public function test_isolamento_por_tenant(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();
        $userA   = $this->criarUsuarioDono($tenantA);
        $objetivoB = KanbanColunaObjetivo::create(['tenant_id' => $tenantB->id, 'coluna_kanban' => 'em_atendimento', 'texto' => 'De outro tenant', 'ordem' => 1, 'ativo' => true]);

        $response = $this->actingAs($userA)->putJson("/api/painel/kanban/coluna-objetivos/em_atendimento/{$objetivoB->id}", [
            'texto' => 'Tentando alterar',
        ]);

        $response->assertStatus(404);
    }
}
