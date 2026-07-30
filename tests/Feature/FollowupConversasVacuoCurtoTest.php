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
}
