<?php

namespace Tests\Feature;

use App\Jobs\SequenciaMensagemJob;
use App\Models\Contato;
use App\Models\Sequencia;
use App\Models\SequenciaMensagem;
use App\Models\Tenant;
use App\Models\TicketAtendimento;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Achado real 2026-09-21 (ticket #4827, Carlos): o endpoint de mover pra
 * "Outros" (transferência humana manual, botão dedicado no Kanban) não
 * disparava a Sequência de Mensagens/Automação da coluna de destino — só o
 * endpoint de drag-and-drop (atualizarStatus) tinha esse gatilho.
 */
class KanbanControllerMoverParaOutrosDisparaSequenciaTest extends TestCase
{
    use RefreshDatabase;

    public function test_mover_para_outros_dispara_sequencia_configurada_na_coluna_destino(): void
    {
        Queue::fake();
        $tenant  = Tenant::factory()->create();
        $user    = User::factory()->create(['tenant_id' => $tenant->id, 'perfil' => 'dono', 'ativo' => true]);
        $contato = Contato::factory()->create();
        $ticket  = TicketAtendimento::create([
            'tenant_id' => $tenant->id, 'contato_id' => $contato->id,
            'coluna_kanban' => 'em_atendimento', 'agente_responsavel' => 'bot',
            'status' => 'aberto', 'aberto_em' => now(),
        ]);

        $sequencia = Sequencia::create([
            'tenant_id' => $tenant->id, 'nome' => 'Transferência',
            'coluna_kanban' => 'outros', 'ativo' => true,
        ]);
        SequenciaMensagem::create([
            'tenant_id' => $tenant->id, 'sequencia_id' => $sequencia->id, 'ordem' => 1,
            'conteudo' => 'Um atendente vai te chamar em instantes.', 'delay_segundos' => 0,
            'ativo' => true, 'obrigatorio' => true,
        ]);

        $this->actingAs($user)
            ->postJson("/api/painel/kanban/ticket/{$ticket->id}/outros")
            ->assertOk();

        $this->assertSame('outros', $ticket->fresh()->coluna_kanban);
        Queue::assertPushed(SequenciaMensagemJob::class, fn ($job) => $job->ticketId === $ticket->id);
    }
}
