<?php

namespace Tests\Feature;

use App\Models\Contato;
use App\Models\Mensagem;
use App\Models\Tenant;
use App\Models\TicketAtendimento;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class KanbanControllerPrioridadeTest extends TestCase
{
    use RefreshDatabase;

    private function criarUsuarioDono(Tenant $tenant): User
    {
        return User::factory()->create(['tenant_id' => $tenant->id, 'perfil' => 'dono', 'ativo' => true]);
    }

    private function criarTicketComUltimaMensagem(Tenant $tenant, string $agenteResponsavel, string $remetente, array $extra = []): TicketAtendimento
    {
        $contato = Contato::factory()->create();
        $ticket  = TicketAtendimento::create(array_merge([
            'tenant_id' => $tenant->id, 'contato_id' => $contato->id,
            'coluna_kanban' => 'em_atendimento', 'agente_responsavel' => $agenteResponsavel,
            'status' => 'aberto', 'aberto_em' => now()->subHours(5),
        ], $extra));

        Mensagem::create([
            'ticket_id' => $ticket->id, 'tenant_id' => $tenant->id,
            'remetente' => $remetente, 'tipo' => 'texto', 'conteudo' => 'oi',
            'enviado_em' => now(),
        ]);

        return $ticket;
    }

    public function test_ticket_com_lead_esperando_humano_vem_marcado_e_primeiro(): void
    {
        $tenant = Tenant::factory()->create();
        $user   = $this->criarUsuarioDono($tenant);

        $normal    = $this->criarTicketComUltimaMensagem($tenant, 'humano', 'humano', ['aberto_em' => now()]);
        $esperando = $this->criarTicketComUltimaMensagem($tenant, 'humano', 'lead', ['aberto_em' => now()->subHours(10)]);

        $response = $this->actingAs($user)->getJson('/api/painel/kanban/tickets');

        $response->assertOk();
        $tickets = $response->json('em_atendimento.tickets');

        $this->assertSame($esperando->id, $tickets[0]['id']);
        $this->assertTrue($tickets[0]['precisa_resposta']);
        $this->assertFalse($tickets[1]['precisa_resposta']);
    }

    public function test_bot_com_lead_falando_por_ultimo_nao_e_marcado(): void
    {
        $tenant = Tenant::factory()->create();
        $user   = $this->criarUsuarioDono($tenant);

        $ticket = $this->criarTicketComUltimaMensagem($tenant, 'bot', 'lead');

        $response = $this->actingAs($user)->getJson('/api/painel/kanban/tickets');

        $tickets = $response->json('em_atendimento.tickets');
        $this->assertFalse($tickets[0]['precisa_resposta']);
    }

    /**
     * Achado real (2026-08-13): o card do board mostrava "Xd atrás" calculado a
     * partir de `aberto_em` (quando o ticket foi criado), não de quando a última
     * mensagem foi trocada — uma conversa ativa há dias, com mensagens todo dia,
     * aparecia como "parada" pro Leonardo só porque o ticket é antigo. O front
     * já tem `ultima_mensagem_em` disponível (usado no cálculo de prioridade);
     * este teste garante que o campo continua vindo na resposta da API e reflete
     * a mensagem mais recente, não a abertura do ticket.
     */
    public function test_ultima_mensagem_em_reflete_a_mensagem_mais_recente_nao_a_abertura_do_ticket(): void
    {
        $tenant = Tenant::factory()->create();
        $user   = $this->criarUsuarioDono($tenant);

        $ticket = $this->criarTicketComUltimaMensagem($tenant, 'bot', 'lead', ['aberto_em' => now()->subDays(8)]);
        Mensagem::create([
            'ticket_id' => $ticket->id, 'tenant_id' => $tenant->id,
            'remetente' => 'lead', 'tipo' => 'texto', 'conteudo' => 'mensagem mais recente',
            'enviado_em' => now()->subMinutes(5),
        ]);

        $response = $this->actingAs($user)->getJson('/api/painel/kanban/tickets');
        $tickets  = $response->json('em_atendimento.tickets');

        $ultimaMensagemEm = \Illuminate\Support\Carbon::parse($tickets[0]['ultima_mensagem_em']);
        $this->assertTrue($ultimaMensagemEm->diffInMinutes(now()) < 10);
    }

    public function test_ticket_pendente_ou_com_retorno_vem_antes_dos_demais(): void
    {
        $tenant = Tenant::factory()->create();
        $user   = $this->criarUsuarioDono($tenant);

        $normal   = $this->criarTicketComUltimaMensagem($tenant, 'humano', 'humano', ['aberto_em' => now()]);
        $pendente = $this->criarTicketComUltimaMensagem($tenant, 'humano', 'humano', [
            'aberto_em' => now()->subHours(10), 'pendente_desde' => now(),
        ]);

        $response = $this->actingAs($user)->getJson('/api/painel/kanban/tickets');
        $tickets  = $response->json('em_atendimento.tickets');

        $this->assertSame($pendente->id, $tickets[0]['id']);
        $this->assertSame($normal->id, $tickets[1]['id']);
    }

    public function test_ticket_ja_visualizado_depois_da_ultima_mensagem_nao_fica_marcado(): void
    {
        $tenant   = Tenant::factory()->create();
        $user     = $this->criarUsuarioDono($tenant);
        $visto    = $this->criarTicketComUltimaMensagem($tenant, 'humano', 'lead', ['visualizado_em' => now()->addMinute()]);
        $naoVisto = $this->criarTicketComUltimaMensagem($tenant, 'humano', 'lead');

        $response = $this->actingAs($user)->getJson('/api/painel/kanban/tickets');
        $tickets  = collect($response->json('em_atendimento.tickets'))->keyBy('id');

        $this->assertFalse($tickets[$visto->id]['precisa_resposta']);
        $this->assertTrue($tickets[$naoVisto->id]['precisa_resposta']);
    }

    public function test_visualizar_marca_o_ticket_como_lido(): void
    {
        $tenant = Tenant::factory()->create();
        $user   = $this->criarUsuarioDono($tenant);
        $ticket = $this->criarTicketComUltimaMensagem($tenant, 'humano', 'lead');

        $this->assertNull($ticket->visualizado_em);

        $response = $this->actingAs($user)->postJson("/api/painel/kanban/ticket/{$ticket->id}/visualizar");

        $response->assertOk();
        $this->assertNotNull($ticket->fresh()->visualizado_em);
    }
}
