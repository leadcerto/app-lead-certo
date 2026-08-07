<?php
// tests/Feature/ReassumirAgenteTest.php
namespace Tests\Feature;

use App\Models\AlertaInterno;
use App\Models\Contato;
use App\Models\KanbanColunaConfig;
use App\Models\Mensagem;
use App\Models\Tenant;
use App\Models\TicketAtendimento;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class ReassumirAgenteTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-08-06 14:00:00'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function criarTicketAssumidoPeloHumano(int $minutosDeSilencio, string $coluna = 'em_atendimento'): TicketAtendimento
    {
        $tenant  = Tenant::factory()->create();
        $contato = Contato::factory()->create(['nome' => 'Marcos']);
        $ticket  = TicketAtendimento::create([
            'tenant_id' => $tenant->id, 'contato_id' => $contato->id,
            'coluna_kanban' => $coluna, 'agente_responsavel' => 'humano',
            'status' => 'aberto', 'aberto_em' => now(),
        ]);

        Mensagem::create([
            'ticket_id' => $ticket->id, 'tenant_id' => $tenant->id,
            'remetente' => 'humano', 'tipo' => 'texto', 'conteudo' => 'Já te retorno!',
            'enviado_em' => now()->subMinutes($minutosDeSilencio),
        ]);

        return $ticket;
    }

    public function test_reassume_quando_silencio_ultrapassa_o_timeout_configurado(): void
    {
        $ticket = $this->criarTicketAssumidoPeloHumano(70);

        KanbanColunaConfig::create([
            'tenant_id' => $ticket->tenant_id, 'coluna_kanban' => 'em_atendimento',
            'timeout_reassuncao_ativo' => true, 'timeout_reassuncao_segundos' => 3600, // 1h
        ]);

        $this->artisan('conversas:reassumir-agente')->assertExitCode(0);

        $ticket->refresh();
        $this->assertSame('bot', $ticket->agente_responsavel);
        $this->assertDatabaseHas('alertas_internos', [
            'ticket_id' => $ticket->id, 'tenant_id' => $ticket->tenant_id,
            'tipo' => 'reassuncao_automatica',
        ]);
    }

    public function test_nao_reassume_quando_silencio_ainda_nao_atingiu_o_timeout(): void
    {
        $ticket = $this->criarTicketAssumidoPeloHumano(30); // 30 min, limite é 60

        KanbanColunaConfig::create([
            'tenant_id' => $ticket->tenant_id, 'coluna_kanban' => 'em_atendimento',
            'timeout_reassuncao_ativo' => true, 'timeout_reassuncao_segundos' => 3600,
        ]);

        $this->artisan('conversas:reassumir-agente')->assertExitCode(0);

        $ticket->refresh();
        $this->assertSame('humano', $ticket->agente_responsavel);
        $this->assertDatabaseMissing('alertas_internos', ['ticket_id' => $ticket->id]);
    }

    public function test_nao_reassume_quando_toggle_esta_desativado(): void
    {
        $ticket = $this->criarTicketAssumidoPeloHumano(120);

        KanbanColunaConfig::create([
            'tenant_id' => $ticket->tenant_id, 'coluna_kanban' => 'em_atendimento',
            'timeout_reassuncao_ativo' => false, 'timeout_reassuncao_segundos' => 3600,
        ]);

        $this->artisan('conversas:reassumir-agente')->assertExitCode(0);

        $this->assertSame('humano', $ticket->fresh()->agente_responsavel);
    }

    public function test_nao_reassume_quando_nao_ha_config_para_a_coluna(): void
    {
        $ticket = $this->criarTicketAssumidoPeloHumano(120, 'coluna_sem_config');

        $this->artisan('conversas:reassumir-agente')->assertExitCode(0);

        $this->assertSame('humano', $ticket->fresh()->agente_responsavel);
    }

    public function test_isolamento_entre_tenants(): void
    {
        $ticketA = $this->criarTicketAssumidoPeloHumano(120);
        KanbanColunaConfig::create([
            'tenant_id' => $ticketA->tenant_id, 'coluna_kanban' => 'em_atendimento',
            'timeout_reassuncao_ativo' => true, 'timeout_reassuncao_segundos' => 3600,
        ]);

        $ticketB = $this->criarTicketAssumidoPeloHumano(120);
        // tenant B não tem config nenhuma pra essa coluna

        $this->artisan('conversas:reassumir-agente')->assertExitCode(0);

        $this->assertSame('bot', $ticketA->fresh()->agente_responsavel);
        $this->assertSame('humano', $ticketB->fresh()->agente_responsavel);
    }

    public function test_dry_run_nao_altera_nada(): void
    {
        $ticket = $this->criarTicketAssumidoPeloHumano(120);
        KanbanColunaConfig::create([
            'tenant_id' => $ticket->tenant_id, 'coluna_kanban' => 'em_atendimento',
            'timeout_reassuncao_ativo' => true, 'timeout_reassuncao_segundos' => 3600,
        ]);

        $this->artisan('conversas:reassumir-agente --dry-run')->assertExitCode(0);

        $this->assertSame('humano', $ticket->fresh()->agente_responsavel);
        $this->assertDatabaseMissing('alertas_internos', ['ticket_id' => $ticket->id]);
    }

    public function test_ticket_ja_reassumido_nao_gera_segundo_alerta_na_proxima_execucao(): void
    {
        $ticket = $this->criarTicketAssumidoPeloHumano(120);
        KanbanColunaConfig::create([
            'tenant_id' => $ticket->tenant_id, 'coluna_kanban' => 'em_atendimento',
            'timeout_reassuncao_ativo' => true, 'timeout_reassuncao_segundos' => 3600,
        ]);

        $this->artisan('conversas:reassumir-agente')->assertExitCode(0);
        $this->artisan('conversas:reassumir-agente')->assertExitCode(0);

        $this->assertSame(1, AlertaInterno::where('ticket_id', $ticket->id)->count());
    }

    public function test_ticket_sem_nenhuma_mensagem_nao_e_candidato(): void
    {
        $tenant  = Tenant::factory()->create();
        $contato = Contato::factory()->create();
        $ticket  = TicketAtendimento::create([
            'tenant_id' => $tenant->id, 'contato_id' => $contato->id,
            'coluna_kanban' => 'em_atendimento', 'agente_responsavel' => 'humano',
            'status' => 'aberto', 'aberto_em' => now(),
        ]);
        // Nenhuma Mensagem criada — o ticket não tem "última mensagem" nenhuma.

        KanbanColunaConfig::create([
            'tenant_id' => $tenant->id, 'coluna_kanban' => 'em_atendimento',
            'timeout_reassuncao_ativo' => true, 'timeout_reassuncao_segundos' => 60,
        ]);

        $this->artisan('conversas:reassumir-agente')->assertExitCode(0);

        $this->assertSame('humano', $ticket->fresh()->agente_responsavel);
    }

    public function test_falha_ao_criar_alerta_nao_impede_a_reassuncao(): void
    {
        $ticket = $this->criarTicketAssumidoPeloHumano(70);

        KanbanColunaConfig::create([
            'tenant_id' => $ticket->tenant_id, 'coluna_kanban' => 'em_atendimento',
            'timeout_reassuncao_ativo' => true, 'timeout_reassuncao_segundos' => 3600,
        ]);

        $this->mock(\App\Services\AlertaInternoService::class, function ($mock) {
            $mock->shouldReceive('criar')->once()->andThrow(new \Exception('falha simulada'));
        });

        $this->artisan('conversas:reassumir-agente')->assertExitCode(0);

        // A reassunção em si (o que mais importa pro lead não ficar esperando
        // pra sempre) não deve depender do alerta ter sido criado com sucesso.
        $this->assertSame('bot', $ticket->fresh()->agente_responsavel);
    }
}
