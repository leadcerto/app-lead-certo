<?php

namespace Tests\Feature;

use App\Models\Contato;
use App\Models\KanbanColunaConfig;
use App\Models\Mensagem;
use App\Models\Tenant;
use App\Models\TicketAtendimento;
use App\Services\SdrResponderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class FollowupConversasEstagiosTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Fixa um horário dentro do expediente (8h-20h) por padrão nos testes.
        Carbon::setTestNow(Carbon::parse('2026-07-10 14:00:00'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function criarTicketComUltimaMensagemHaXMinutos(int $minutosAtras, int $followupEstagioEnviado = 0): TicketAtendimento
    {
        $tenant  = Tenant::factory()->create();
        $contato = Contato::factory()->create();
        $ticket  = TicketAtendimento::create([
            'tenant_id' => $tenant->id, 'contato_id' => $contato->id,
            'coluna_kanban' => 'lead_novo', 'agente_responsavel' => 'bot', 'etapa_ia' => 'etapa_1',
            'status' => 'aberto', 'aberto_em' => now(),
            'followup_estagio_enviado' => $followupEstagioEnviado,
        ]);

        Mensagem::create([
            'ticket_id' => $ticket->id, 'tenant_id' => $tenant->id,
            'remetente' => 'bot', 'tipo' => 'texto', 'conteudo' => 'Oi!',
            'enviado_em' => now()->subMinutes($minutosAtras),
        ]);

        return $ticket;
    }

    public function test_dispara_estagio_1_apos_1_hora_de_silencio(): void
    {
        // 90min: além do limite padrão do estágio 1 (60min/3600s), aquém do estágio 2 (120min/7200s)
        $ticket = $this->criarTicketComUltimaMensagemHaXMinutos(90);

        $this->mock(SdrResponderService::class, function ($mock) {
            $mock->shouldReceive('responder')->once()->withArgs(
                fn ($t, $origem, $gatilho) => $gatilho === 'estagio_1'
            )->andReturn('ok');
        });

        $this->artisan('conversas:followup')->assertExitCode(0);

        $this->assertSame(1, $ticket->fresh()->followup_estagio_enviado);
    }

    public function test_nao_redispara_estagio_ja_enviado(): void
    {
        $this->criarTicketComUltimaMensagemHaXMinutos(90, followupEstagioEnviado: 1);

        $this->mock(SdrResponderService::class, function ($mock) {
            $mock->shouldReceive('responder')->never();
        });

        $this->artisan('conversas:followup')->assertExitCode(0);
    }

    public function test_pula_direto_para_estagio_3_quando_silencio_e_longo(): void
    {
        $ticket = $this->criarTicketComUltimaMensagemHaXMinutos(7 * 60, followupEstagioEnviado: 1); // > 6h (padrão estágio 3)

        $this->mock(SdrResponderService::class, function ($mock) {
            $mock->shouldReceive('responder')->once()->withArgs(
                fn ($t, $origem, $gatilho) => $gatilho === 'estagio_3'
            )->andReturn('ok [ENCERRADO]');
        });

        $this->artisan('conversas:followup')->assertExitCode(0);

        $this->assertSame(3, $ticket->fresh()->followup_estagio_enviado);
    }

    public function test_respeita_limites_customizados_por_coluna(): void
    {
        $ticket = $this->criarTicketComUltimaMensagemHaXMinutos(2 * 60); // 2h de silêncio

        KanbanColunaConfig::create([
            'tenant_id'     => $ticket->tenant_id,
            'coluna_kanban' => 'lead_novo',
            // Estágio 1 customizado pra 3h — 2h de silêncio ainda não deve disparar
            'followup_estagio1_segundos' => 3 * 3600,
            'followup_estagio2_segundos' => 5 * 3600,
            'followup_estagio3_segundos' => 8 * 3600,
        ]);

        $this->mock(SdrResponderService::class, function ($mock) {
            $mock->shouldReceive('responder')->never();
        });

        $this->artisan('conversas:followup')->assertExitCode(0);

        $this->assertSame(0, $ticket->fresh()->followup_estagio_enviado);
    }

    public function test_fora_do_horario_comercial_nao_dispara(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-10 23:00:00'));
        $ticket = $this->criarTicketComUltimaMensagemHaXMinutos(7 * 60);

        $this->mock(SdrResponderService::class, function ($mock) {
            $mock->shouldReceive('responder')->never();
        });

        $this->artisan('conversas:followup')->assertExitCode(0);

        $this->assertSame(0, $ticket->fresh()->followup_estagio_enviado);
    }

    public function test_dry_run_nao_persiste_nada(): void
    {
        $ticket = $this->criarTicketComUltimaMensagemHaXMinutos(90);

        $this->mock(SdrResponderService::class, function ($mock) {
            $mock->shouldReceive('responder')->never();
        });

        $this->artisan('conversas:followup --dry-run')->assertExitCode(0);

        $this->assertSame(0, $ticket->fresh()->followup_estagio_enviado);
    }
}
