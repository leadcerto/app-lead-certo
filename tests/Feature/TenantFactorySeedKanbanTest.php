<?php

namespace Tests\Feature;

use App\Enums\PapelColunaKanban;
use App\Models\KanbanColuna;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TenantFactorySeedKanbanTest extends TestCase
{
    use RefreshDatabase;

    public function test_tenant_criado_via_factory_ja_tem_kanban_de_vendas_com_8_colunas_padrao(): void
    {
        $tenant = Tenant::factory()->create();

        $chaves = KanbanColuna::chavesDoTenant($tenant->id);

        $this->assertSame(
            ['lead_novo', 'em_atendimento', 'aguardando_orcamento', 'aguardando_lead', 'pagamento', 'servico_agendado', 'encerrado', 'outros'],
            $chaves
        );
        $this->assertSame(PapelColunaKanban::Entrada, KanbanColuna::papelDe($tenant->id, 'lead_novo'));
        $this->assertSame(PapelColunaKanban::Encerramento, KanbanColuna::papelDe($tenant->id, 'encerrado'));
        $this->assertSame(PapelColunaKanban::TransferenciaHumana, KanbanColuna::papelDe($tenant->id, 'outros'));
    }
}
