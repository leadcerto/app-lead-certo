<?php

namespace Tests\Feature;

use App\Models\Kanban;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class KanbanNomeCurtoTest extends TestCase
{
    use RefreshDatabase;

    public function test_kanban_tem_nome_curto_fillable(): void
    {
        $tenant = Tenant::factory()->create();
        $kanban = Kanban::where('tenant_id', $tenant->id)->where('tipo', 'vendas')->firstOrFail();

        $kanban->update(['nome_curto' => 'Imóveis']);

        $this->assertSame('Imóveis', $kanban->fresh()->nome_curto);
    }

    public function test_backfill_preenche_nome_curto_do_kanban_vendas_existente(): void
    {
        $tenant = Tenant::factory()->create();
        $kanban = Kanban::where('tenant_id', $tenant->id)->where('tipo', 'vendas')->firstOrFail();

        $this->assertSame('Atendimentos', $kanban->nome_curto);
    }
}
