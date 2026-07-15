<?php

namespace Tests\Feature;

use App\Models\Contato;
use App\Models\GestorKanbanRelatorio;
use App\Models\Tenant;
use App\Models\TicketAtendimento;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GestorKanbanSemanalCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake(['*' => Http::response([
            'choices' => [['message' => ['content' =>
                "ANÁLISE:\nOk.\n\nSUGESTÃO_PROMPT:\nOk."
            ]]],
            'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 20],
        ], 200)]);
    }

    private function criarTenantComAtividade(string $status = 'ativo'): Tenant
    {
        $tenant  = Tenant::factory()->create(['status' => $status]);
        $contato = Contato::factory()->create();

        Carbon::setTestNow(now()->subDays(2));
        TicketAtendimento::create([
            'tenant_id' => $tenant->id, 'contato_id' => $contato->id,
            'coluna_kanban' => 'lead_novo', 'agente_responsavel' => 'bot',
            'status' => 'aberto', 'aberto_em' => now(),
        ]);
        Carbon::setTestNow();

        return $tenant;
    }

    public function test_roda_para_todos_os_tenants_ativos(): void
    {
        $tenantAtivo    = $this->criarTenantComAtividade('ativo');
        $tenantSuspenso = $this->criarTenantComAtividade('suspenso');

        $this->artisan('kanban:gestor-semanal')->assertExitCode(0);

        $this->assertSame(1, GestorKanbanRelatorio::withoutGlobalScopes()->where('tenant_id', $tenantAtivo->id)->count());
        $this->assertSame(0, GestorKanbanRelatorio::withoutGlobalScopes()->where('tenant_id', $tenantSuspenso->id)->count());
    }

    public function test_opcao_tenant_roda_so_para_um_tenant(): void
    {
        $tenantA = $this->criarTenantComAtividade('ativo');
        $tenantB = $this->criarTenantComAtividade('ativo');

        $this->artisan('kanban:gestor-semanal', ['--tenant' => $tenantA->id])->assertExitCode(0);

        $this->assertSame(1, GestorKanbanRelatorio::withoutGlobalScopes()->where('tenant_id', $tenantA->id)->count());
        $this->assertSame(0, GestorKanbanRelatorio::withoutGlobalScopes()->where('tenant_id', $tenantB->id)->count());
    }

    public function test_opcao_tenant_processa_mesmo_tenant_suspenso(): void
    {
        $tenantSuspenso = $this->criarTenantComAtividade('suspenso');

        $this->artisan('kanban:gestor-semanal', ['--tenant' => $tenantSuspenso->id])->assertExitCode(0);

        $this->assertSame(1, GestorKanbanRelatorio::withoutGlobalScopes()->where('tenant_id', $tenantSuspenso->id)->count());
    }

    public function test_dry_run_nao_persiste_nada(): void
    {
        $tenant = $this->criarTenantComAtividade('ativo');

        $this->artisan('kanban:gestor-semanal', ['--dry-run' => true])->assertExitCode(0);

        $this->assertSame(0, GestorKanbanRelatorio::withoutGlobalScopes()->where('tenant_id', $tenant->id)->count());
    }

    public function test_janela_de_7_dias_exclui_atividade_de_hoje(): void
    {
        // Sábado 00:00:00 — mesmo horário em que o schedule roda o comando.
        Carbon::setTestNow(Carbon::parse('2026-07-18 00:00:00'));

        $tenant  = Tenant::factory()->create(['status' => 'ativo']);
        $contato = Contato::factory()->create();

        // Única atividade do tenant, criada exatamente "hoje" (o dia em que o
        // comando roda). Numa janela correta de 7 dias terminando ONTEM,
        // atividade de "hoje" nunca deve entrar — então isso deve contar
        // como zero atividade na semana e nenhum relatório deve ser gerado.
        // Com o bug antigo (Carbon::now()->endOfDay() como $fim), "hoje"
        // entraria na janela e um relatório seria gerado — este teste falha
        // contra aquele código.
        TicketAtendimento::create([
            'tenant_id' => $tenant->id, 'contato_id' => $contato->id,
            'coluna_kanban' => 'lead_novo', 'agente_responsavel' => 'bot',
            'status' => 'aberto', 'aberto_em' => now(),
        ]);

        $this->artisan('kanban:gestor-semanal')->assertExitCode(0);

        $this->assertSame(0, GestorKanbanRelatorio::withoutGlobalScopes()->where('tenant_id', $tenant->id)->count());

        Carbon::setTestNow();
    }
}
