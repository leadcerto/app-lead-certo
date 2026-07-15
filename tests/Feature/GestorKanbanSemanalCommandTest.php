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
}
