<?php
// tests/Feature/ExpirarPausaOrientacaoTest.php
namespace Tests\Feature;

use App\Models\AlertaInterno;
use App\Models\Contato;
use App\Models\KanbanColunaConfig;
use App\Models\Tenant;
use App\Models\TicketAtendimento;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class ExpirarPausaOrientacaoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-08-08 10:00:00'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function criarTicketPausado(Carbon $pausadoEm, string $coluna = 'em_atendimento'): TicketAtendimento
    {
        $tenant  = Tenant::factory()->create();
        $contato = Contato::factory()->create(['nome' => 'Marcos']);

        return TicketAtendimento::create([
            'tenant_id' => $tenant->id, 'contato_id' => $contato->id,
            'coluna_kanban' => $coluna, 'agente_responsavel' => 'bot',
            'status' => 'aberto', 'aberto_em' => now(),
            'aguardando_orientacao_em' => $pausadoEm,
            'mensagem_espera_enviada' => true,
        ]);
    }

    public function test_expira_pausa_alem_do_timeout_e_fecha_o_alerta(): void
    {
        $ticket = $this->criarTicketPausado(now());
        KanbanColunaConfig::create([
            'tenant_id' => $ticket->tenant_id, 'coluna_kanban' => 'em_atendimento',
            'duvida_timeout_ativo' => true, 'duvida_timeout_segundos' => 1800,
        ]);
        $alerta = AlertaInterno::create([
            'tenant_id' => $ticket->tenant_id, 'ticket_id' => $ticket->id,
            'tipo' => 'duvida_ia', 'titulo' => 'Dúvida', 'conteudo' => 'x',
        ]);

        Carbon::setTestNow(Carbon::parse('2026-08-08 10:35:00')); // 35min depois

        $this->artisan('conversas:expirar-pausa-orientacao')->assertExitCode(0);

        $ticket->refresh();
        $this->assertNull($ticket->aguardando_orientacao_em);
        $this->assertFalse($ticket->mensagem_espera_enviada);

        $alerta->refresh();
        $this->assertNotNull($alerta->resposta);
        $this->assertNotNull($alerta->respondido_em);
    }

    public function test_nao_expira_antes_do_timeout(): void
    {
        $ticket = $this->criarTicketPausado(now());
        KanbanColunaConfig::create([
            'tenant_id' => $ticket->tenant_id, 'coluna_kanban' => 'em_atendimento',
            'duvida_timeout_ativo' => true, 'duvida_timeout_segundos' => 1800,
        ]);

        Carbon::setTestNow(Carbon::parse('2026-08-08 10:20:00')); // 20min depois

        $this->artisan('conversas:expirar-pausa-orientacao')->assertExitCode(0);

        $this->assertNotNull($ticket->fresh()->aguardando_orientacao_em);
    }

    public function test_coluna_sem_timeout_configurado_nunca_expira(): void
    {
        $ticket = $this->criarTicketPausado(now());
        // Sem KanbanColunaConfig nenhuma pra essa coluna.

        Carbon::setTestNow(Carbon::parse('2026-08-10 10:00:00'));

        $this->artisan('conversas:expirar-pausa-orientacao')->assertExitCode(0);

        $this->assertNotNull($ticket->fresh()->aguardando_orientacao_em);
    }

    public function test_ticket_nao_pausado_nao_e_candidato(): void
    {
        $tenant  = Tenant::factory()->create();
        $contato = Contato::factory()->create();
        $ticket  = TicketAtendimento::create([
            'tenant_id' => $tenant->id, 'contato_id' => $contato->id,
            'coluna_kanban' => 'em_atendimento', 'agente_responsavel' => 'bot',
            'status' => 'aberto', 'aberto_em' => now(),
        ]);
        KanbanColunaConfig::create([
            'tenant_id' => $tenant->id, 'coluna_kanban' => 'em_atendimento',
            'duvida_timeout_ativo' => true, 'duvida_timeout_segundos' => 60,
        ]);

        Carbon::setTestNow(Carbon::parse('2026-08-08 11:00:00'));

        $this->artisan('conversas:expirar-pausa-orientacao')->assertExitCode(0);

        $this->assertSame('bot', $ticket->fresh()->agente_responsavel);
    }

    public function test_dry_run_nao_altera_nada(): void
    {
        $ticket = $this->criarTicketPausado(now());
        KanbanColunaConfig::create([
            'tenant_id' => $ticket->tenant_id, 'coluna_kanban' => 'em_atendimento',
            'duvida_timeout_ativo' => true, 'duvida_timeout_segundos' => 1800,
        ]);

        Carbon::setTestNow(Carbon::parse('2026-08-08 10:35:00'));

        $this->artisan('conversas:expirar-pausa-orientacao --dry-run')->assertExitCode(0);

        $this->assertNotNull($ticket->fresh()->aguardando_orientacao_em);
    }

    public function test_reassuncao_e_silenciosa_nenhuma_mensagem_enviada_ao_lead(): void
    {
        $ticket = $this->criarTicketPausado(now());
        KanbanColunaConfig::create([
            'tenant_id' => $ticket->tenant_id, 'coluna_kanban' => 'em_atendimento',
            'duvida_timeout_ativo' => true, 'duvida_timeout_segundos' => 1800,
        ]);

        $this->mock(\App\Services\SdrResponderService::class, function ($mock) {
            $mock->shouldReceive('responder')->never();
        });

        Carbon::setTestNow(Carbon::parse('2026-08-08 10:35:00'));

        $this->artisan('conversas:expirar-pausa-orientacao')->assertExitCode(0);

        $this->assertSame(0, \App\Models\Mensagem::where('ticket_id', $ticket->id)->count());
    }
}
