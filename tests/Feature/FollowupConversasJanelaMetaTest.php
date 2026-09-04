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

class FollowupConversasJanelaMetaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Fixa horário comercial (10:00 da manhã)
        Carbon::setTestNow(Carbon::parse('2026-07-10 10:00:00'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function criarTicketComJanelaMeta(
        int $horasAteExpirarJanela = 3,
        int $minutosSilencio = 120,
        bool $followupEnviado = false,
        bool $iaAtivo = true
    ): TicketAtendimento {
        $tenant  = Tenant::factory()->create();
        $contato = Contato::factory()->create();
        $ticket  = TicketAtendimento::create([
            'tenant_id'                => $tenant->id,
            'contato_id'               => $contato->id,
            'coluna_kanban'            => 'lead_novo',
            'agente_responsavel'       => 'bot',
            'etapa_ia'                 => 'etapa_1',
            'status'                   => 'aberto',
            'aberto_em'                => now()->subHours(18),
            'janela_expira_em'         => now()->addHours($horasAteExpirarJanela),
            'followup_enviado'         => $followupEnviado,
            'followup_estagio_enviado' => 0,
        ]);

        Mensagem::create([
            'ticket_id'  => $ticket->id,
            'tenant_id'  => $tenant->id,
            'remetente'  => 'lead',
            'tipo'       => 'texto',
            'conteudo'   => 'Oi, quanto custa o frete?',
            'enviado_em' => now()->subMinutes($minutosSilencio),
        ]);

        KanbanColunaConfig::create([
            'tenant_id'     => $tenant->id,
            'coluna_kanban' => 'lead_novo',
            'ia_ativo'      => $iaAtivo,
        ]);

        return $ticket;
    }

    public function test_dispara_followup_quando_janela_meta_expira_em_menos_de_6h(): void
    {
        $ticket = $this->criarTicketComJanelaMeta(horasAteExpirarJanela: 4, minutosSilencio: 120);

        $this->mock(SdrResponderService::class, function ($mock) {
            $mock->shouldReceive('responder')->once()->withArgs(
                fn ($t, $origem, $gatilho) => $gatilho === 'janela_meta_6h'
            )->andReturn('Olá! Tudo bem? Passando para saber se conseguiu avaliar nossa mensagem anterior.');
        });

        $this->artisan('conversas:followup')->assertExitCode(0);

        $this->assertTrue($ticket->fresh()->followup_enviado);
    }

    public function test_nao_dispara_quando_janela_meta_tem_mais_de_6h_restantes(): void
    {
        $ticket = $this->criarTicketComJanelaMeta(horasAteExpirarJanela: 12, minutosSilencio: 120);

        $this->mock(SdrResponderService::class, function ($mock) {
            // Pode disparar estágios normais se bater tempo, mas com 120min padrão estágio 1 e 2
            $mock->shouldReceive('responder')->never()->withArgs(
                fn ($t, $origem, $gatilho) => $gatilho === 'janela_meta_6h'
            );
        });

        $this->artisan('conversas:followup')->assertExitCode(0);

        $this->assertFalse($ticket->fresh()->followup_enviado);
    }

    public function test_nao_dispara_duplicado_se_followup_ja_foi_enviado(): void
    {
        $ticket = $this->criarTicketComJanelaMeta(horasAteExpirarJanela: 3, minutosSilencio: 120, followupEnviado: true);

        $this->mock(SdrResponderService::class, function ($mock) {
            $mock->shouldReceive('responder')->never()->withArgs(
                fn ($t, $origem, $gatilho) => $gatilho === 'janela_meta_6h'
            );
        });

        $this->artisan('conversas:followup')->assertExitCode(0);
    }

    public function test_nao_dispara_fora_do_horario_comercial(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-10 23:30:00'));
        $ticket = $this->criarTicketComJanelaMeta(horasAteExpirarJanela: 2, minutosSilencio: 120);

        $this->mock(SdrResponderService::class, function ($mock) {
            $mock->shouldReceive('responder')->never();
        });

        $this->artisan('conversas:followup')->assertExitCode(0);

        $this->assertFalse($ticket->fresh()->followup_enviado);
    }
}
