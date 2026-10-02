<?php

namespace Tests\Feature;

use App\Models\Contato;
use App\Models\KanbanColunaConfig;
use App\Models\Mensagem;
use App\Models\Tenant;
use App\Models\TicketAtendimento;
use App\Services\SdrResponderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Cobre o follow-up CURTO (reaquecimento de 10 min, gatilho vacuo_10m) —
 * sem teste até 2026-07-30. Mesmo bug do estágios de silêncio: FollowupConversas
 * chamava SdrResponderService::responder() direto, sem checar ia_ativo.
 */
class FollowupConversasVacuoCurtoTest extends TestCase
{
    use RefreshDatabase;

    private function criarTicketComUltimaMensagemDoLead(int $minutosAtras, bool $iaAtivo): TicketAtendimento
    {
        $tenant  = Tenant::factory()->create();
        $contato = Contato::factory()->create();
        $ticket  = TicketAtendimento::create([
            'tenant_id' => $tenant->id, 'contato_id' => $contato->id,
            'coluna_kanban' => 'lead_novo', 'agente_responsavel' => 'bot', 'etapa_ia' => 'etapa_1',
            'status' => 'aberto', 'aberto_em' => now(),
        ]);

        Mensagem::create([
            'ticket_id' => $ticket->id, 'tenant_id' => $tenant->id,
            'remetente' => 'lead', 'tipo' => 'texto', 'conteudo' => 'oi, tudo bem?',
            'enviado_em' => now()->subMinutes($minutosAtras),
        ]);

        KanbanColunaConfig::create([
            'tenant_id' => $tenant->id, 'coluna_kanban' => 'lead_novo', 'ia_ativo' => $iaAtivo,
        ]);

        return $ticket;
    }

    public function test_dispara_reaquecimento_quando_ia_ativa(): void
    {
        $this->criarTicketComUltimaMensagemDoLead(30, iaAtivo: true);

        $this->mock(SdrResponderService::class, function ($mock) {
            $mock->shouldReceive('responder')->once()->withArgs(
                fn ($t, $origem, $gatilho) => $gatilho === 'vacuo_10m'
            )->andReturn('ok');
        });

        $this->artisan('conversas:followup')->assertExitCode(0);
    }

    public function test_nao_dispara_reaquecimento_quando_ia_ativo_e_falso(): void
    {
        $this->criarTicketComUltimaMensagemDoLead(30, iaAtivo: false);

        $this->mock(SdrResponderService::class, function ($mock) {
            $mock->shouldReceive('responder')->never();
        });

        $this->artisan('conversas:followup')->assertExitCode(0);
    }

    /**
     * Achado real 02/10 (Leonardo, tickets #4932 e #5001): sem trava, o cron
     * (a cada 5min) chamava a IA de novo em TODO ciclo dentro da janela de
     * 10-90min — até 16 vezes pro mesmo silêncio, porque [SEM_RESPOSTA] não
     * grava Mensagem nenhuma, então "última mensagem é do lead" continuava
     * verdadeiro indefinidamente.
     */
    public function test_marca_followup_curto_enviado_em_apos_disparar(): void
    {
        $ticket = $this->criarTicketComUltimaMensagemDoLead(30, iaAtivo: true);

        $this->mock(SdrResponderService::class, function ($mock) {
            $mock->shouldReceive('responder')->once()->andReturn(null); // [SEM_RESPOSTA]
        });

        $this->artisan('conversas:followup')->assertExitCode(0);

        $this->assertNotNull($ticket->fresh()->followup_curto_enviado_em);
    }

    public function test_nao_dispara_de_novo_pro_mesmo_silencio_ja_marcado(): void
    {
        $ticket = $this->criarTicketComUltimaMensagemDoLead(30, iaAtivo: true);
        // Já disparou alguns minutos atrás, depois da última mensagem do lead.
        $ticket->update(['followup_curto_enviado_em' => now()->subMinutes(5)]);

        $this->mock(SdrResponderService::class, function ($mock) {
            $mock->shouldReceive('responder')->never();
        });

        $this->artisan('conversas:followup')->assertExitCode(0);
    }

    public function test_dispara_de_novo_se_lead_mandou_mensagem_nova_depois_do_ultimo_disparo(): void
    {
        $ticket = $this->criarTicketComUltimaMensagemDoLead(90, iaAtivo: true);
        // Disparou pra uma leva de silêncio anterior...
        $ticket->update(['followup_curto_enviado_em' => now()->subMinutes(120)]);
        // ...mas o lead voltou a escrever depois disso, reiniciando o silêncio.
        Mensagem::create([
            'ticket_id' => $ticket->id, 'tenant_id' => $ticket->tenant_id,
            'remetente' => 'lead', 'tipo' => 'texto', 'conteudo' => 'oi, voltei',
            'enviado_em' => now()->subMinutes(30),
        ]);

        $this->mock(SdrResponderService::class, function ($mock) {
            $mock->shouldReceive('responder')->once()->withArgs(
                fn ($t, $origem, $gatilho) => $gatilho === 'vacuo_10m'
            )->andReturn('ok');
        });

        $this->artisan('conversas:followup')->assertExitCode(0);
    }
}
