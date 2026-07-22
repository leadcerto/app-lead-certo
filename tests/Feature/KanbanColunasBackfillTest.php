<?php

namespace Tests\Feature;

use App\Models\KanbanColuna;
use App\Models\KanbanColunaConfig;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class KanbanColunasBackfillTest extends TestCase
{
    use RefreshDatabase;

    public function test_backfill_recria_as_8_colunas_e_liga_configs_existentes_sem_perder_dado(): void
    {
        // Simula um tenant "antigo": cria via factory (que já semeia colunas — Task 6),
        // então apaga a estrutura nova pra simular o estado ANTES desta migration,
        // deixando só o config com conteúdo customizado (como estaria em produção).
        $tenant = Tenant::factory()->create();
        KanbanColuna::where('tenant_id', $tenant->id)->delete();
        DB::table('kanbans')->where('tenant_id', $tenant->id)->delete();

        KanbanColunaConfig::where('tenant_id', $tenant->id)->delete();
        KanbanColunaConfig::create([
            'tenant_id' => $tenant->id, 'coluna_kanban' => 'aguardando_orcamento',
            'ia_contexto' => 'PROMPT CUSTOMIZADO PELO FRANQUEADO — NÃO PODE SER SOBRESCRITO',
            'ia_ativo' => true,
        ]);

        // A suíte roda em sqlite :memory: e o RefreshDatabase migra o schema uma única vez
        // (reaproveitado entre testes via transação). Isso significa que, quando este teste
        // roda, esta migration JÁ foi executada uma vez para o schema global — logo, chamar
        // 'migrate' de novo seria um no-op (Laravel vê a migration como já aplicada). Por
        // isso damos rollback nela primeiro, colocando-a de volta em estado "pendente" pra
        // simular fielmente o cenário real: um tenant antigo, criado ANTES desta migration
        // já ter sido aplicada ao banco de produção.
        $this->artisan('migrate:rollback', ['--path' => 'database/migrations/2026_07_17_000004_backfill_kanbans_e_kanban_colunas.php', '--realpath' => false])
            ->run();

        $this->artisan('migrate', ['--path' => 'database/migrations/2026_07_17_000004_backfill_kanbans_e_kanban_colunas.php', '--realpath' => false])
            ->run();

        $chaves = KanbanColuna::chavesDoTenant($tenant->id);
        $this->assertSame(
            ['lead_novo', 'em_atendimento', 'aguardando_orcamento', 'aguardando_lead', 'pagamento', 'servico_agendado', 'encerrado', 'outros'],
            $chaves
        );

        $colunaAguardandoOrcamento = KanbanColuna::where('tenant_id', $tenant->id)->where('chave', 'aguardando_orcamento')->firstOrFail();
        $config = KanbanColunaConfig::where('tenant_id', $tenant->id)->where('coluna_kanban', 'aguardando_orcamento')->firstOrFail();

        $this->assertSame($colunaAguardandoOrcamento->id, $config->kanban_coluna_id);
        $this->assertSame('PROMPT CUSTOMIZADO PELO FRANQUEADO — NÃO PODE SER SOBRESCRITO', $config->ia_contexto);
    }

    public function test_backfill_nao_sobrescreve_kanban_coluna_id_ja_vinculado(): void
    {
        // Mesmo setup de "tenant antigo" do teste acima: apaga a estrutura nova
        // semeada pela factory pra simular o estado ANTES desta migration.
        $tenant = Tenant::factory()->create();
        KanbanColuna::where('tenant_id', $tenant->id)->delete();
        DB::table('kanbans')->where('tenant_id', $tenant->id)->delete();

        // Simula um config que já foi vinculado a uma coluna (execução anterior da
        // migration, ou vínculo manual). Usamos o id de uma kanban_coluna real de OUTRO
        // tenant (a FK de kanban_coluna_id exige um id existente em kanban_colunas) —
        // esse id certamente não é o que a migration calcularia pra 'aguardando_orcamento'
        // deste tenant, já que pertence a outra linha/tenant inteiramente. O guard
        // whereNull('kanban_coluna_id') deve impedir que esse valor seja reatribuído.
        $outroTenant = Tenant::factory()->create();
        $idArbitrarioJaVinculado = KanbanColuna::where('tenant_id', $outroTenant->id)
            ->where('chave', 'lead_novo')->value('id');

        KanbanColunaConfig::where('tenant_id', $tenant->id)->delete();
        $config = KanbanColunaConfig::create([
            'tenant_id' => $tenant->id, 'coluna_kanban' => 'aguardando_orcamento',
            'kanban_coluna_id' => $idArbitrarioJaVinculado,
            'ia_contexto' => 'PROMPT CUSTOMIZADO PELO FRANQUEADO — NÃO PODE SER SOBRESCRITO',
            'ia_ativo' => true,
        ]);

        // Mesmo motivo do teste acima: rollback + re-migrate pra simular a migration
        // rodando "pendente" contra o estado de tenant antigo já preparado.
        $this->artisan('migrate:rollback', ['--path' => 'database/migrations/2026_07_17_000004_backfill_kanbans_e_kanban_colunas.php', '--realpath' => false])
            ->run();

        $this->artisan('migrate', ['--path' => 'database/migrations/2026_07_17_000004_backfill_kanbans_e_kanban_colunas.php', '--realpath' => false])
            ->run();

        $config->refresh();

        $this->assertSame($idArbitrarioJaVinculado, $config->kanban_coluna_id);
    }
}
