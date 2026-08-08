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

    private function criarTicketComUltimaMensagemHaXMinutos(int $minutosAtras, int $followupEstagioEnviado = 0, array $config = []): TicketAtendimento
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

        // ia_ativo=true por padrão — os testes deste arquivo cobrem a lógica de
        // TEMPO/estágio, não a checagem de IA ativa (coberta em arquivo próprio).
        KanbanColunaConfig::create(array_merge([
            'tenant_id' => $tenant->id, 'coluna_kanban' => 'lead_novo', 'ia_ativo' => true,
        ], $config));

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
        // Estágio 1 customizado pra 3h — 2h de silêncio ainda não deve disparar
        $ticket = $this->criarTicketComUltimaMensagemHaXMinutos(2 * 60, config: [
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

    /**
     * Bug real reportado pelo Leonardo (2026-07-30): desativar "Agente ativo
     * nesta coluna" (ia_ativo) só bloqueava a resposta AO VIVO (SdrResponderJob)
     * — os Estágios de silêncio continuavam disparando mesmo assim, porque
     * FollowupConversas chamava SdrResponderService::responder() direto, sem
     * checar ia_ativo. Corrigido pra respeitar o mesmo gate.
     */
    public function test_nao_dispara_quando_ia_ativo_e_falso(): void
    {
        $ticket = $this->criarTicketComUltimaMensagemHaXMinutos(90, config: ['ia_ativo' => false]);

        $this->mock(SdrResponderService::class, function ($mock) {
            $mock->shouldReceive('responder')->never();
        });

        $this->artisan('conversas:followup')->assertExitCode(0);

        $this->assertSame(0, $ticket->fresh()->followup_estagio_enviado);
    }

    /**
     * Achado Importante 3 da revisão final: quando responder() retorna null
     * (ticket pausado aguardando orientação humana, Regra 9), o comando não
     * pode avançar o estágio nem contar o envio — senão o ticket pausado
     * queima os estágios 1→2→3 sem nunca ter mandado mensagem nenhuma.
     */
    public function test_nao_avanca_estagio_quando_responder_retorna_null_por_ticket_pausado(): void
    {
        $ticket = $this->criarTicketComUltimaMensagemHaXMinutos(90);

        $this->mock(SdrResponderService::class, function ($mock) {
            $mock->shouldReceive('responder')->once()->withArgs(
                fn ($t, $origem, $gatilho) => $gatilho === 'estagio_1'
            )->andReturn(null);
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

    public function test_para_de_tentar_apos_3_falhas_seguidas_e_alerta_uma_vez(): void
    {
        $ticket = $this->criarTicketComUltimaMensagemHaXMinutos(90);
        $ticket->update(['tentativas_envio_falhas' => 3]);

        $this->mock(SdrResponderService::class, function ($mock) {
            $mock->shouldReceive('responder')->never();
        });

        $this->artisan('conversas:followup')->assertExitCode(0);

        $this->assertSame(0, $ticket->fresh()->followup_estagio_enviado);
        $this->assertDatabaseHas('alertas_internos', ['ticket_id' => $ticket->id, 'tipo' => 'envio_falhou']);
        $this->assertSame(
            1,
            \App\Models\AlertaInterno::where('ticket_id', $ticket->id)->where('tipo', 'envio_falhou')->count()
        );
    }

    public function test_nao_repete_alerta_envio_falhou_na_proxima_execucao(): void
    {
        $ticket = $this->criarTicketComUltimaMensagemHaXMinutos(90);
        $ticket->update(['tentativas_envio_falhas' => 3]);

        $this->mock(SdrResponderService::class, function ($mock) {
            $mock->shouldReceive('responder')->never();
        });

        $this->artisan('conversas:followup')->assertExitCode(0);
        $this->artisan('conversas:followup')->assertExitCode(0);

        $this->assertSame(
            1,
            \App\Models\AlertaInterno::where('ticket_id', $ticket->id)->where('tipo', 'envio_falhou')->count()
        );
    }

    public function test_menos_de_3_tentativas_ainda_chama_a_ia_normalmente(): void
    {
        $ticket = $this->criarTicketComUltimaMensagemHaXMinutos(90);
        $ticket->update(['tentativas_envio_falhas' => 2]);

        $this->mock(SdrResponderService::class, function ($mock) {
            $mock->shouldReceive('responder')->once()->andReturn('ok');
        });

        $this->artisan('conversas:followup')->assertExitCode(0);

        $this->assertSame(1, $ticket->fresh()->followup_estagio_enviado);
        $this->assertDatabaseMissing('alertas_internos', ['ticket_id' => $ticket->id, 'tipo' => 'envio_falhou']);
    }
}
