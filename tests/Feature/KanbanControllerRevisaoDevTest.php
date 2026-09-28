<?php

namespace Tests\Feature;

use App\Models\Contato;
use App\Models\Tenant;
use App\Models\TicketAtendimento;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Pedido do Leonardo (24/09): botão "Auditoria" no ticket, pra um humano
 * sinalizar uma conversa pra revisão de desenvolvimento — nunca pra IA
 * analisar. Cria uma fila persistente em vez de depender de lembrar e passar
 * o número do ticket manualmente toda vez que um problema aparece (como
 * aconteceu hoje com os tickets #4907/#4908/#4913). Sem relação com
 * QaAuditoria (avaliação automática por IA ao encerrar) nem AuditoriaContato
 * (moderação de nome de contato) — nomes de campo deliberadamente diferentes.
 */
class KanbanControllerRevisaoDevTest extends TestCase
{
    use RefreshDatabase;

    private function criarTicket(Tenant $tenant): TicketAtendimento
    {
        $contato = Contato::factory()->create();

        return TicketAtendimento::create([
            'tenant_id' => $tenant->id, 'contato_id' => $contato->id,
            'coluna_kanban' => 'em_atendimento', 'agente_responsavel' => 'bot',
            'status' => 'aberto', 'aberto_em' => now(),
        ]);
    }

    public function test_marca_ticket_para_revisao_de_desenvolvimento_com_nota(): void
    {
        $tenant = Tenant::factory()->create();
        $user   = User::factory()->create(['tenant_id' => $tenant->id, 'perfil' => 'dono', 'ativo' => true]);
        $ticket = $this->criarTicket($tenant);

        $response = $this->actingAs($user)->postJson("/api/painel/kanban/ticket/{$ticket->id}/auditoria", [
            'nota' => 'IA ficou repetindo mensagem de lembrete várias vezes',
        ]);

        $response->assertOk();
        $ticket->refresh();
        $this->assertNotNull($ticket->revisao_dev_solicitada_em);
        $this->assertSame('IA ficou repetindo mensagem de lembrete várias vezes', $ticket->revisao_dev_nota);
        $this->assertSame($user->id, $ticket->revisao_dev_solicitada_por);
        $this->assertNull($ticket->revisao_dev_concluida_em);
    }

    public function test_marca_ticket_para_revisao_sem_nota(): void
    {
        $tenant = Tenant::factory()->create();
        $user   = User::factory()->create(['tenant_id' => $tenant->id, 'perfil' => 'dono', 'ativo' => true]);
        $ticket = $this->criarTicket($tenant);

        $response = $this->actingAs($user)->postJson("/api/painel/kanban/ticket/{$ticket->id}/auditoria", []);

        $response->assertOk();
        $this->assertNotNull($ticket->fresh()->revisao_dev_solicitada_em);
    }

    public function test_conclui_revisao_de_desenvolvimento(): void
    {
        $tenant = Tenant::factory()->create();
        $user   = User::factory()->create(['tenant_id' => $tenant->id, 'perfil' => 'dono', 'ativo' => true]);
        $ticket = $this->criarTicket($tenant);
        $ticket->update(['revisao_dev_solicitada_em' => now(), 'revisao_dev_solicitada_por' => $user->id]);

        $response = $this->actingAs($user)->postJson("/api/painel/kanban/ticket/{$ticket->id}/auditoria/concluir");

        $response->assertOk();
        $this->assertNotNull($ticket->fresh()->revisao_dev_concluida_em);
    }

    public function test_lista_de_auditorias_so_mostra_pendentes_nao_concluidas(): void
    {
        $tenant = Tenant::factory()->create();
        $user   = User::factory()->create(['tenant_id' => $tenant->id, 'perfil' => 'dono', 'ativo' => true]);

        $pendente = $this->criarTicket($tenant);
        $pendente->update(['revisao_dev_solicitada_em' => now(), 'revisao_dev_nota' => 'pendente', 'revisao_dev_solicitada_por' => $user->id]);

        $concluida = $this->criarTicket($tenant);
        $concluida->update([
            'revisao_dev_solicitada_em' => now()->subDay(), 'revisao_dev_nota' => 'ja resolvido',
            'revisao_dev_solicitada_por' => $user->id, 'revisao_dev_concluida_em' => now(),
        ]);

        $semAuditoria = $this->criarTicket($tenant);

        $response = $this->actingAs($user)->getJson('/api/painel/kanban/auditorias');

        $response->assertOk();
        $ids = collect($response->json('data'))->pluck('id');
        $this->assertTrue($ids->contains($pendente->id));
        $this->assertFalse($ids->contains($concluida->id));
        $this->assertFalse($ids->contains($semAuditoria->id));
    }

    public function test_lista_de_auditorias_e_restrita_a_admin_e_dono(): void
    {
        $tenant = Tenant::factory()->create();
        $vendedor = User::factory()->create(['tenant_id' => $tenant->id, 'perfil' => 'vendedor', 'ativo' => true]);

        $response = $this->actingAs($vendedor)->getJson('/api/painel/kanban/auditorias');

        $response->assertForbidden();
    }
}
