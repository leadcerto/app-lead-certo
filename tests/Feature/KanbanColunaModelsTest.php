<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class KanbanColunaModelsTest extends TestCase
{
    use RefreshDatabase;

    public function test_tabelas_kanbans_e_kanban_colunas_existem_com_as_colunas_esperadas(): void
    {
        $this->assertTrue(Schema::hasTable('kanbans'));
        $this->assertTrue(Schema::hasColumns('kanbans', ['tenant_id', 'tipo', 'nome', 'ordem']));

        $this->assertTrue(Schema::hasTable('kanban_colunas'));
        $this->assertTrue(Schema::hasColumns('kanban_colunas', [
            'tenant_id', 'kanban_id', 'chave', 'label', 'emoji', 'papel', 'ordem',
        ]));
    }

    public function test_kanban_tem_colunas_ordenadas_e_coluna_pertence_ao_kanban(): void
    {
        $tenant = \App\Models\Tenant::factory()->create();

        $kanban = \App\Models\Kanban::create([
            'tenant_id' => $tenant->id, 'tipo' => 'vendas', 'nome' => 'Vendas', 'ordem' => 0,
        ]);

        \App\Models\KanbanColuna::create([
            'tenant_id' => $tenant->id, 'kanban_id' => $kanban->id,
            'chave' => 'b', 'label' => 'B', 'papel' => \App\Enums\PapelColunaKanban::EmAndamento, 'ordem' => 2,
        ]);
        $primeira = \App\Models\KanbanColuna::create([
            'tenant_id' => $tenant->id, 'kanban_id' => $kanban->id,
            'chave' => 'a', 'label' => 'A', 'papel' => \App\Enums\PapelColunaKanban::Entrada, 'ordem' => 1,
        ]);

        $this->assertSame(['a', 'b'], $kanban->colunas->pluck('chave')->all());
        $this->assertTrue($primeira->kanban->is($kanban));
        $this->assertSame(\App\Enums\PapelColunaKanban::Entrada, $primeira->papel);
    }
}
