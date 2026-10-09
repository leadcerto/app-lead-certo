<?php

namespace Tests\Feature;

use App\Models\Kanban;
use App\Models\KanbanColuna;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class KanbanColunaControllerKanbanIdTest extends TestCase
{
    use RefreshDatabase;

    private function criarKanbanFunil(Tenant $tenant): Kanban
    {
        return Kanban::create([
            'tenant_id' => $tenant->id, 'tipo' => 'funil_teste', 'nome' => 'Funil Teste',
            'nome_curto' => 'Funil', 'ordem' => 1,
        ]);
    }

    public function test_index_sem_kanban_id_continua_retornando_as_colunas_do_kanban_vendas(): void
    {
        $tenant = Tenant::factory()->create();
        $user   = User::factory()->create(['tenant_id' => $tenant->id, 'perfil' => 'dono', 'ativo' => true]);
        $this->criarKanbanFunil($tenant);

        $response = $this->actingAs($user)->getJson('/api/painel/kanban/colunas');

        $response->assertOk();
        $chaves = collect($response->json())->pluck('chave')->all();
        $this->assertContains('lead_novo', $chaves);
        $this->assertNotContains('funil_novo_lead', $chaves);
    }

    public function test_index_com_kanban_id_retorna_as_colunas_do_kanban_certo(): void
    {
        $tenant      = Tenant::factory()->create();
        $user        = User::factory()->create(['tenant_id' => $tenant->id, 'perfil' => 'dono', 'ativo' => true]);
        $kanbanFunil = $this->criarKanbanFunil($tenant);
        KanbanColuna::create([
            'tenant_id' => $tenant->id, 'kanban_id' => $kanbanFunil->id,
            'chave' => 'funil_novo_lead', 'label' => 'Novo Lead',
            'papel' => \App\Enums\PapelColunaKanban::Entrada, 'ordem' => 1,
        ]);

        $response = $this->actingAs($user)->getJson('/api/painel/kanban/colunas?kanban_id=' . $kanbanFunil->id);

        $response->assertOk();
        $chaves = collect($response->json())->pluck('chave')->all();
        $this->assertSame(['funil_novo_lead'], $chaves);
    }

    public function test_kanban_id_de_outro_tenant_nao_vaza_colunas(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();
        $user    = User::factory()->create(['tenant_id' => $tenantA->id, 'perfil' => 'dono', 'ativo' => true]);
        $kanbanDoB = Kanban::where('tenant_id', $tenantB->id)->where('tipo', 'vendas')->firstOrFail();

        $response = $this->actingAs($user)->getJson('/api/painel/kanban/colunas?kanban_id=' . $kanbanDoB->id);

        $response->assertStatus(404);
    }

    public function test_store_cria_coluna_no_kanban_informado(): void
    {
        $tenant      = Tenant::factory()->create();
        $user        = User::factory()->create(['tenant_id' => $tenant->id, 'perfil' => 'dono', 'ativo' => true]);
        $kanbanFunil = $this->criarKanbanFunil($tenant);

        $response = $this->actingAs($user)->postJson('/api/painel/kanban/colunas?kanban_id=' . $kanbanFunil->id, [
            'label' => 'Nova Coluna', 'papel' => 'em_andamento',
        ]);

        $response->assertStatus(201);
        $this->assertSame($kanbanFunil->id, KanbanColuna::findOrFail($response->json('id'))->kanban_id);
    }

    /**
     * Achado da revisão final (09/10/2026): a unicidade de chave em store()
     * checava só dentro do próprio Kanban (where('kanban_id', ...)) — um
     * 2º Kanban com uma coluna de nome natural ("Encerrado", "Pagamento")
     * colidia com a chave já usada pelo Kanban padrão do mesmo tenant.
     * `kanban_coluna_configs` tem UNIQUE(tenant_id, coluna_kanban), então
     * salvar a config dessa coluna colidida dava erro 500 — e pra chaves
     * sem UNIQUE (objetivos), a config/objetivo de um Kanban vazava pra
     * leitura em runtime do outro (serviços leem por tenant_id+chave, sem
     * kanban_id). Reproduzido aqui com setup real via TenantSetupService
     * (não TenantFactory — só esse gera as configs padrão de produção).
     */
    public function test_chave_de_coluna_e_unica_por_tenant_mesmo_entre_kanbans_diferentes(): void
    {
        $tenant = Tenant::factory()->create();
        app(\App\Services\TenantSetupService::class)->configurar($tenant);
        $user = User::factory()->create(['tenant_id' => $tenant->id, 'perfil' => 'dono', 'ativo' => true]);

        $kanbanFunil = $this->actingAs($user)->postJson('/api/painel/kanban', [
            'nome' => 'Funil', 'nome_curto' => 'Funil',
        ])->assertStatus(201)->json();

        // "Encerrado" é exatamente o nome de uma coluna padrão do Kanban
        // geral — o pior caso de colisão de chave.
        $response = $this->actingAs($user)->postJson('/api/painel/kanban/colunas?kanban_id=' . $kanbanFunil['id'], [
            'label' => 'Encerrado', 'papel' => 'encerramento',
        ]);
        $response->assertStatus(201);
        $chaveNova = $response->json('chave');
        $this->assertNotSame('encerrado', $chaveNova);

        // Salvar a config dessa coluna não pode dar 500 (UNIQUE
        // tenant_id+coluna_kanban colidindo com a config padrão de
        // 'encerrado' que o TenantSetupService já criou pro Kanban geral).
        $this->actingAs($user)->putJson("/api/painel/kanban/coluna-config/{$chaveNova}?kanban_id=" . $kanbanFunil['id'], [
            'objetivo' => 'Objetivo só do funil',
        ])->assertOk();

        $configGeral = \App\Models\KanbanColunaConfig::where('tenant_id', $tenant->id)
            ->where('coluna_kanban', 'encerrado')
            ->first();
        $this->assertNotSame('Objetivo só do funil', $configGeral?->objetivo);
    }
}
