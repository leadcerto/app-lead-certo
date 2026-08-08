<?php
// tests/Feature/MonitorarKanbanTest.php
namespace Tests\Feature;

use App\Models\AlertaInterno;
use App\Models\Contato;
use App\Models\KanbanColunaConfig;
use App\Models\Tenant;
use App\Models\TicketAtendimento;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class MonitorarKanbanTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-08-07 10:00:00'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function criarTicket(Tenant $tenant, string $coluna = 'aguardando_orcamento'): TicketAtendimento
    {
        $contato = Contato::factory()->create(['nome' => 'Marcos']);

        return TicketAtendimento::create([
            'tenant_id' => $tenant->id, 'contato_id' => $contato->id,
            'coluna_kanban' => $coluna, 'agente_responsavel' => 'bot',
            'status' => 'aberto', 'aberto_em' => now(),
        ]);
    }

    public function test_alerta_ticket_travado_alem_do_tempo_maximo(): void
    {
        $tenant = Tenant::factory()->create();
        $ticket = $this->criarTicket($tenant);
        KanbanColunaConfig::create([
            'tenant_id' => $tenant->id, 'coluna_kanban' => 'aguardando_orcamento',
            'tempo_maximo_permanencia_minutos' => 60,
        ]);

        Carbon::setTestNow(Carbon::parse('2026-08-07 11:05:00')); // 65 min depois

        $this->artisan('kanban:monitorar')->assertExitCode(0);

        $this->assertDatabaseHas('alertas_internos', [
            'ticket_id' => $ticket->id, 'tenant_id' => $tenant->id, 'tipo' => 'ticket_travado',
        ]);
    }

    public function test_nao_alerta_antes_do_tempo_maximo(): void
    {
        $tenant = Tenant::factory()->create();
        $ticket = $this->criarTicket($tenant);
        KanbanColunaConfig::create([
            'tenant_id' => $tenant->id, 'coluna_kanban' => 'aguardando_orcamento',
            'tempo_maximo_permanencia_minutos' => 60,
        ]);

        Carbon::setTestNow(Carbon::parse('2026-08-07 10:30:00')); // 30 min depois

        $this->artisan('kanban:monitorar')->assertExitCode(0);

        $this->assertDatabaseMissing('alertas_internos', ['ticket_id' => $ticket->id]);
    }

    public function test_coluna_sem_tempo_maximo_configurado_nunca_alerta(): void
    {
        $tenant = Tenant::factory()->create();
        $ticket = $this->criarTicket($tenant);
        // Sem KanbanColunaConfig nenhuma pra essa coluna.

        Carbon::setTestNow(Carbon::parse('2026-08-10 10:00:00')); // 3 dias depois

        $this->artisan('kanban:monitorar')->assertExitCode(0);

        $this->assertDatabaseMissing('alertas_internos', ['ticket_id' => $ticket->id]);
    }

    public function test_ticket_encerrado_nao_e_candidato(): void
    {
        $tenant = Tenant::factory()->create();
        $ticket = $this->criarTicket($tenant);
        $ticket->update(['status' => 'encerrado']);
        KanbanColunaConfig::create([
            'tenant_id' => $tenant->id, 'coluna_kanban' => 'aguardando_orcamento',
            'tempo_maximo_permanencia_minutos' => 60,
        ]);

        Carbon::setTestNow(Carbon::parse('2026-08-07 11:05:00'));

        $this->artisan('kanban:monitorar')->assertExitCode(0);

        $this->assertDatabaseMissing('alertas_internos', ['ticket_id' => $ticket->id]);
    }

    public function test_nao_repete_alerta_na_proxima_execucao(): void
    {
        $tenant = Tenant::factory()->create();
        $ticket = $this->criarTicket($tenant);
        KanbanColunaConfig::create([
            'tenant_id' => $tenant->id, 'coluna_kanban' => 'aguardando_orcamento',
            'tempo_maximo_permanencia_minutos' => 60,
        ]);

        Carbon::setTestNow(Carbon::parse('2026-08-07 11:05:00'));
        $this->artisan('kanban:monitorar')->assertExitCode(0);
        $this->artisan('kanban:monitorar')->assertExitCode(0);

        $this->assertSame(1, AlertaInterno::where('ticket_id', $ticket->id)->count());
    }

    public function test_ticket_sai_e_volta_pra_mesma_coluna_pode_alertar_de_novo(): void
    {
        $tenant = Tenant::factory()->create();
        $ticket = $this->criarTicket($tenant);
        KanbanColunaConfig::create([
            'tenant_id' => $tenant->id, 'coluna_kanban' => 'aguardando_orcamento',
            'tempo_maximo_permanencia_minutos' => 60,
        ]);

        Carbon::setTestNow(Carbon::parse('2026-08-07 11:05:00'));
        $this->artisan('kanban:monitorar')->assertExitCode(0);
        $this->assertSame(1, AlertaInterno::where('ticket_id', $ticket->id)->count());

        // Sai e volta pra mesma coluna — nova linha de histórico, alertado_em nulo de novo.
        $ticket->update(['coluna_kanban' => 'em_atendimento']);
        $ticket->update(['coluna_kanban' => 'aguardando_orcamento']);

        Carbon::setTestNow(Carbon::parse('2026-08-07 12:10:00')); // mais 65min depois de reentrar

        $this->artisan('kanban:monitorar')->assertExitCode(0);

        $this->assertSame(2, AlertaInterno::where('ticket_id', $ticket->id)->count());
    }

    public function test_dry_run_nao_altera_nada(): void
    {
        $tenant = Tenant::factory()->create();
        $ticket = $this->criarTicket($tenant);
        KanbanColunaConfig::create([
            'tenant_id' => $tenant->id, 'coluna_kanban' => 'aguardando_orcamento',
            'tempo_maximo_permanencia_minutos' => 60,
        ]);

        Carbon::setTestNow(Carbon::parse('2026-08-07 11:05:00'));

        $this->artisan('kanban:monitorar --dry-run')->assertExitCode(0);

        $this->assertDatabaseMissing('alertas_internos', ['ticket_id' => $ticket->id]);
    }

    public function test_isolamento_entre_tenants(): void
    {
        $tenantA = Tenant::factory()->create();
        $ticketA = $this->criarTicket($tenantA);
        KanbanColunaConfig::create([
            'tenant_id' => $tenantA->id, 'coluna_kanban' => 'aguardando_orcamento',
            'tempo_maximo_permanencia_minutos' => 60,
        ]);

        $tenantB = Tenant::factory()->create();
        $ticketB = $this->criarTicket($tenantB);
        // tenant B não tem config nenhuma pra essa coluna

        Carbon::setTestNow(Carbon::parse('2026-08-07 11:05:00'));

        $this->artisan('kanban:monitorar')->assertExitCode(0);

        $this->assertDatabaseHas('alertas_internos', ['ticket_id' => $ticketA->id]);
        $this->assertDatabaseMissing('alertas_internos', ['ticket_id' => $ticketB->id]);
    }

    public function test_falha_ao_criar_alerta_nao_marca_alertado_em_tenta_de_novo_depois(): void
    {
        $tenant = Tenant::factory()->create();
        $ticket = $this->criarTicket($tenant);
        KanbanColunaConfig::create([
            'tenant_id' => $tenant->id, 'coluna_kanban' => 'aguardando_orcamento',
            'tempo_maximo_permanencia_minutos' => 60,
        ]);

        $this->mock(\App\Services\AlertaInternoService::class, function ($mock) {
            $mock->shouldReceive('criar')->once()->andThrow(new \Exception('falha simulada'));
        });

        Carbon::setTestNow(Carbon::parse('2026-08-07 11:05:00'));

        $this->artisan('kanban:monitorar')->assertExitCode(0);

        $this->assertDatabaseMissing('alertas_internos', ['ticket_id' => $ticket->id]);
        $this->assertNull(
            \App\Models\KanbanColunaHistorico::where('ticket_id', $ticket->id)->latest('id')->value('alertado_em')
        );
    }
}
