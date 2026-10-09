<?php

namespace Tests\Feature;

use App\Enums\PapelColunaKanban;
use App\Models\Kanban;
use App\Models\KanbanColuna;
use App\Models\KanbanColunaObjetivo;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class KanbanColunaObjetivoControllerKanbanIdTest extends TestCase
{
    use RefreshDatabase;

    public function test_objetivos_sao_isolados_por_kanban_mesmo_com_chave_repetida(): void
    {
        $tenant = Tenant::factory()->create();
        $user   = User::factory()->create(['tenant_id' => $tenant->id, 'perfil' => 'dono', 'ativo' => true]);
        $kanbanFunil = Kanban::create([
            'tenant_id' => $tenant->id, 'tipo' => 'funil_teste', 'nome' => 'Funil',
            'nome_curto' => 'Funil', 'ordem' => 1,
        ]);
        KanbanColuna::create([
            'tenant_id' => $tenant->id, 'kanban_id' => $kanbanFunil->id,
            'chave' => 'em_atendimento', 'label' => 'Em Atendimento (funil)',
            'papel' => PapelColunaKanban::EmAndamento, 'ordem' => 1,
        ]);

        $this->actingAs($user)->postJson('/api/painel/kanban/coluna-objetivos/em_atendimento?kanban_id=' . $kanbanFunil->id, [
            'texto' => 'Objetivo só do funil',
        ])->assertCreated();

        $response = $this->actingAs($user)->getJson('/api/painel/kanban/coluna-objetivos/em_atendimento');
        $textos = collect($response->json())->pluck('texto')->all();
        $this->assertNotContains('Objetivo só do funil', $textos);

        $response2 = $this->actingAs($user)->getJson('/api/painel/kanban/coluna-objetivos/em_atendimento?kanban_id=' . $kanbanFunil->id);
        $textos2 = collect($response2->json())->pluck('texto')->all();
        $this->assertContains('Objetivo só do funil', $textos2);
    }

    public function test_backfill_da_migration_preenche_kanban_coluna_id_de_linha_ja_existente(): void
    {
        $tenant = Tenant::factory()->create();
        $kanban = Kanban::where('tenant_id', $tenant->id)->where('tipo', 'vendas')->firstOrFail();

        \Illuminate\Support\Facades\Schema::table('kanban_coluna_objetivos', function ($table) {
            $table->dropConstrainedForeignId('kanban_coluna_id');
        });

        $objetivo = KanbanColunaObjetivo::create([
            'tenant_id' => $tenant->id, 'coluna_kanban' => 'em_atendimento',
            'texto' => 'Objetivo pré-migration', 'ordem' => 1, 'ativo' => true,
        ]);

        (require base_path('database/migrations/2026_10_08_000003_add_kanban_coluna_id_to_kanban_coluna_objetivos_table.php'))->up();

        $colunaReal = KanbanColuna::where('kanban_id', $kanban->id)->where('chave', 'em_atendimento')->firstOrFail();
        $this->assertSame($colunaReal->id, $objetivo->fresh()->kanban_coluna_id);
    }
}
