<?php

namespace Tests\Feature;

use App\Enums\PapelColunaKanban;
use App\Models\Kanban;
use App\Models\KanbanColuna;
use App\Models\KanbanColunaConfig;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class KanbanColunaConfigControllerKanbanIdTest extends TestCase
{
    use RefreshDatabase;

    public function test_update_com_kanban_id_grava_config_no_kanban_certo_mesmo_com_chave_repetida(): void
    {
        $tenant = Tenant::factory()->create();
        $user   = User::factory()->create(['tenant_id' => $tenant->id, 'perfil' => 'admin', 'ativo' => true]);
        $kanbanGeral = Kanban::where('tenant_id', $tenant->id)->where('tipo', 'vendas')->firstOrFail();
        $kanbanFunil = Kanban::create([
            'tenant_id' => $tenant->id, 'tipo' => 'funil_teste', 'nome' => 'Funil',
            'nome_curto' => 'Funil', 'ordem' => 1,
        ]);
        // Chave DELIBERADAMENTE igual nos 2 Kanbans — o pior caso de ambiguidade.
        KanbanColuna::create([
            'tenant_id' => $tenant->id, 'kanban_id' => $kanbanFunil->id,
            'chave' => 'em_atendimento', 'label' => 'Em Atendimento (funil)',
            'papel' => PapelColunaKanban::EmAndamento, 'ordem' => 1,
        ]);

        $this->actingAs($user)->putJson('/api/painel/kanban/coluna-config/em_atendimento?kanban_id=' . $kanbanFunil->id, [
            'objetivo' => 'Objetivo só do funil',
        ])->assertOk();

        $colunaFunilReal = KanbanColuna::where('kanban_id', $kanbanFunil->id)->where('chave', 'em_atendimento')->firstOrFail();
        $configFunil = KanbanColunaConfig::where('tenant_id', $tenant->id)
            ->where('kanban_coluna_id', $colunaFunilReal->id)
            ->first();
        $this->assertNotNull($configFunil);
        $this->assertSame('Objetivo só do funil', $configFunil->objetivo);

        // O Kanban geral (mesma chave 'em_atendimento') não pode ter sido afetado.
        $colunaGeralReal = KanbanColuna::where('tenant_id', $tenant->id)->where('kanban_id', $kanbanGeral->id)->where('chave', 'em_atendimento')->firstOrFail();
        $configGeral = KanbanColunaConfig::where('tenant_id', $tenant->id)
            ->where('kanban_coluna_id', $colunaGeralReal->id)
            ->first();
        $this->assertNull($configGeral?->objetivo);
    }

    public function test_atualiza_no_lugar_uma_config_legada_sem_kanban_coluna_id_em_vez_de_duplicar(): void
    {
        $tenant = Tenant::factory()->create();
        $user   = User::factory()->create(['tenant_id' => $tenant->id, 'perfil' => 'admin', 'ativo' => true]);

        KanbanColunaConfig::create([
            'tenant_id' => $tenant->id, 'coluna_kanban' => 'encerrado',
            'exclusao_definitiva_ativo' => true, 'exclusao_definitiva_dias' => 60,
        ]);

        $this->actingAs($user)->putJson('/api/painel/kanban/coluna-config/encerrado', [
            'exclusao_definitiva_ativo' => false,
        ])->assertOk();

        $this->assertSame(
            1,
            KanbanColunaConfig::where('tenant_id', $tenant->id)->where('coluna_kanban', 'encerrado')->count()
        );
        $config = KanbanColunaConfig::where('tenant_id', $tenant->id)->where('coluna_kanban', 'encerrado')->first();
        $this->assertFalse($config->exclusao_definitiva_ativo);
        $this->assertSame(60, $config->exclusao_definitiva_dias);
        $this->assertNotNull($config->kanban_coluna_id);
    }
}
