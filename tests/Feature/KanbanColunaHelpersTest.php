<?php

namespace Tests\Feature;

use App\Enums\PapelColunaKanban;
use App\Models\Kanban;
use App\Models\KanbanColuna;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class KanbanColunaHelpersTest extends TestCase
{
    use RefreshDatabase;

    private function kanbanDoTenant(Tenant $tenant): Kanban
    {
        $kanban = Kanban::where('tenant_id', $tenant->id)->where('tipo', 'vendas')->firstOrFail();
        KanbanColuna::where('kanban_id', $kanban->id)->delete();
        KanbanColuna::limparCache($tenant->id);

        return $kanban;
    }

    private function criarColunasPadrao(Tenant $tenant): Kanban
    {
        $kanban = $this->kanbanDoTenant($tenant);

        $defs = [
            ['chave' => 'lead_novo', 'papel' => PapelColunaKanban::Entrada, 'ordem' => 1],
            ['chave' => 'em_atendimento', 'papel' => PapelColunaKanban::EmAndamento, 'ordem' => 2],
            ['chave' => 'encerrado', 'papel' => PapelColunaKanban::Encerramento, 'ordem' => 3],
            ['chave' => 'outros', 'papel' => PapelColunaKanban::TransferenciaHumana, 'ordem' => 4],
        ];

        foreach ($defs as $def) {
            KanbanColuna::create([
                'tenant_id' => $tenant->id, 'kanban_id' => $kanban->id,
                'chave' => $def['chave'], 'label' => $def['chave'], 'papel' => $def['papel'], 'ordem' => $def['ordem'],
            ]);
        }

        return $kanban;
    }

    public function test_chaves_do_tenant_retorna_todas_ordenadas(): void
    {
        $tenant = Tenant::factory()->create();
        $this->criarColunasPadrao($tenant);

        $this->assertSame(
            ['lead_novo', 'em_atendimento', 'encerrado', 'outros'],
            KanbanColuna::chavesDoTenant($tenant->id)
        );
    }

    public function test_papel_de_retorna_o_papel_correto_e_null_se_nao_existir(): void
    {
        $tenant = Tenant::factory()->create();
        $this->criarColunasPadrao($tenant);

        $this->assertSame(PapelColunaKanban::Encerramento, KanbanColuna::papelDe($tenant->id, 'encerrado'));
        $this->assertNull(KanbanColuna::papelDe($tenant->id, 'nao_existe'));
    }

    public function test_chave_de_entrada_retorna_a_unica_coluna_de_entrada(): void
    {
        $tenant = Tenant::factory()->create();
        $this->criarColunasPadrao($tenant);

        $this->assertSame('lead_novo', KanbanColuna::chaveDeEntrada($tenant->id));
    }

    public function test_chave_de_entrada_lanca_excecao_se_nao_houver_coluna_de_entrada(): void
    {
        $tenant = Tenant::factory()->create();
        $this->kanbanDoTenant($tenant); // remove as colunas semeadas pela factory, sem recriar nenhuma

        $this->expectException(\RuntimeException::class);
        KanbanColuna::chaveDeEntrada($tenant->id);
    }

    public function test_chaves_com_papel_e_primeira_chave_com_papel(): void
    {
        $tenant = Tenant::factory()->create();
        $this->criarColunasPadrao($tenant);

        $this->assertSame(['outros'], KanbanColuna::chavesComPapel($tenant->id, PapelColunaKanban::TransferenciaHumana));
        $this->assertSame('outros', KanbanColuna::primeiraChaveComPapel($tenant->id, PapelColunaKanban::TransferenciaHumana));
    }

    public function test_primeira_chave_com_papel_retorna_null_quando_nenhuma_coluna_tem_esse_papel(): void
    {
        $tenant = Tenant::factory()->create();
        $kanban = $this->kanbanDoTenant($tenant);
        KanbanColuna::create([
            'tenant_id' => $tenant->id, 'kanban_id' => $kanban->id,
            'chave' => 'lead_novo', 'label' => 'lead_novo', 'papel' => PapelColunaKanban::Entrada, 'ordem' => 1,
        ]);

        $this->assertNull(KanbanColuna::primeiraChaveComPapel($tenant->id, PapelColunaKanban::TransferenciaHumana));
    }

    public function test_proxima_chave_retorna_a_coluna_seguinte_por_ordem_ou_null_na_ultima(): void
    {
        $tenant = Tenant::factory()->create();
        $this->criarColunasPadrao($tenant);

        $this->assertSame('em_atendimento', KanbanColuna::proximaChave($tenant->id, 'lead_novo'));
        $this->assertNull(KanbanColuna::proximaChave($tenant->id, 'outros'));
        $this->assertNull(KanbanColuna::proximaChave($tenant->id, 'chave_que_nao_existe'));
    }

    public function test_cache_e_invalidado_ao_criar_editar_e_excluir_coluna(): void
    {
        $tenant = Tenant::factory()->create();
        $kanban = $this->criarColunasPadrao($tenant);

        $this->assertCount(4, KanbanColuna::chavesDoTenant($tenant->id));

        $nova = KanbanColuna::create([
            'tenant_id' => $tenant->id, 'kanban_id' => $kanban->id,
            'chave' => 'nova', 'label' => 'Nova', 'papel' => PapelColunaKanban::EmAndamento, 'ordem' => 5,
        ]);
        $this->assertCount(5, KanbanColuna::chavesDoTenant($tenant->id));

        $nova->update(['label' => 'Renomeada']);
        $this->assertCount(5, KanbanColuna::chavesDoTenant($tenant->id));

        $nova->delete();
        $this->assertCount(4, KanbanColuna::chavesDoTenant($tenant->id));
    }
}
