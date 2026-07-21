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
}
