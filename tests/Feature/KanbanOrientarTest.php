<?php
// tests/Feature/KanbanOrientarTest.php
namespace Tests\Feature;

use App\Models\AlertaInterno;
use App\Models\Contato;
use App\Models\Tenant;
use App\Models\TicketAtendimento;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

class KanbanOrientarTest extends TestCase
{
    use RefreshDatabase;

    private function criarTicketAguardandoOrientacao(Tenant $tenant): TicketAtendimento
    {
        $contato = Contato::factory()->create();

        return TicketAtendimento::create([
            'tenant_id' => $tenant->id, 'contato_id' => $contato->id,
            'coluna_kanban' => 'em_atendimento', 'agente_responsavel' => 'bot',
            'status' => 'aberto', 'aberto_em' => now(),
            'aguardando_orientacao_em' => now(), 'mensagem_espera_enviada' => true,
        ]);
    }

    public function test_orienta_limpa_estado_de_espera_e_redispara_o_agente(): void
    {
        Bus::fake();
        $tenant = Tenant::factory()->create();
        $user   = User::factory()->create(['tenant_id' => $tenant->id, 'perfil' => 'dono', 'ativo' => true]);
        $ticket = $this->criarTicketAguardandoOrientacao($tenant);
        $alerta = AlertaInterno::create([
            'tenant_id' => $tenant->id, 'ticket_id' => $ticket->id, 'tipo' => 'duvida_ia',
            'titulo' => 'Agente pediu orientação', 'conteudo' => 'Dúvida sobre preço',
        ]);

        $response = $this->actingAs($user)->postJson("/api/painel/kanban/ticket/{$ticket->id}/orientar", [
            'orientacao' => 'O preço desse serviço é R$ 250.',
        ]);

        $response->assertOk();

        $ticketFresco = $ticket->fresh();
        $this->assertNull($ticketFresco->aguardando_orientacao_em);
        $this->assertFalse($ticketFresco->mensagem_espera_enviada);

        $alertaFresco = $alerta->fresh();
        $this->assertSame('O preço desse serviço é R$ 250.', $alertaFresco->resposta);
        $this->assertNotNull($alertaFresco->respondido_em);

        Bus::assertDispatched(\App\Jobs\SdrResponderJob::class);
    }

    /**
     * Achado real 2026-08-20: só fechava alerta tipo 'duvida_ia' — pra um
     * ticket pausado pelos outros 2 guardrails (rejeicao_area_alucinada,
     * handoff_prematuro), o ticket despausava mas o alerta ficava com
     * resposta/respondido_em nulos pra sempre.
     */
    public function test_orienta_fecha_alerta_de_rejeicao_de_area_alucinada(): void
    {
        Bus::fake();
        $tenant = Tenant::factory()->create();
        $user   = User::factory()->create(['tenant_id' => $tenant->id, 'perfil' => 'dono', 'ativo' => true]);
        $ticket = $this->criarTicketAguardandoOrientacao($tenant);
        $alerta = AlertaInterno::create([
            'tenant_id' => $tenant->id, 'ticket_id' => $ticket->id, 'tipo' => 'rejeicao_area_alucinada',
            'titulo' => 'Agente recusou atendimento por área sem instrução pra isso', 'conteudo' => 'Resposta bloqueada: "..."',
        ]);

        $response = $this->actingAs($user)->postJson("/api/painel/kanban/ticket/{$ticket->id}/orientar", [
            'orientacao' => 'Atendemos sim essa região, pode seguir com o orçamento normal.',
        ]);

        $response->assertOk();

        $alertaFresco = $alerta->fresh();
        $this->assertNotNull($alertaFresco->resposta);
        $this->assertNotNull($alertaFresco->respondido_em);
    }

    public function test_orientar_ticket_que_nao_esta_aguardando_retorna_erro(): void
    {
        $tenant  = Tenant::factory()->create();
        $user    = User::factory()->create(['tenant_id' => $tenant->id, 'perfil' => 'dono', 'ativo' => true]);
        $contato = Contato::factory()->create();
        $ticket  = TicketAtendimento::create([
            'tenant_id' => $tenant->id, 'contato_id' => $contato->id,
            'coluna_kanban' => 'em_atendimento', 'agente_responsavel' => 'bot',
            'status' => 'aberto', 'aberto_em' => now(),
        ]);

        $response = $this->actingAs($user)->postJson("/api/painel/kanban/ticket/{$ticket->id}/orientar", [
            'orientacao' => 'Qualquer coisa',
        ]);

        $response->assertStatus(422);
    }

    public function test_orientacao_vazia_e_rejeitada(): void
    {
        $tenant = Tenant::factory()->create();
        $user   = User::factory()->create(['tenant_id' => $tenant->id, 'perfil' => 'dono', 'ativo' => true]);
        $ticket = $this->criarTicketAguardandoOrientacao($tenant);

        $response = $this->actingAs($user)->postJson("/api/painel/kanban/ticket/{$ticket->id}/orientar", [
            'orientacao' => '',
        ]);

        $response->assertStatus(422);
    }

    public function test_orientacao_so_com_espacos_e_rejeitada(): void
    {
        $tenant = Tenant::factory()->create();
        $user   = User::factory()->create(['tenant_id' => $tenant->id, 'perfil' => 'dono', 'ativo' => true]);
        $ticket = $this->criarTicketAguardandoOrientacao($tenant);

        $response = $this->actingAs($user)->postJson("/api/painel/kanban/ticket/{$ticket->id}/orientar", [
            'orientacao' => '   ',
        ]);

        $response->assertStatus(422);
    }

    public function test_orientar_ticket_ja_assumido_por_humano_retorna_erro_e_nao_muda_nada(): void
    {
        Bus::fake();
        $tenant  = Tenant::factory()->create();
        $user    = User::factory()->create(['tenant_id' => $tenant->id, 'perfil' => 'dono', 'ativo' => true]);
        $contato = Contato::factory()->create();
        $ticket  = TicketAtendimento::create([
            'tenant_id' => $tenant->id, 'contato_id' => $contato->id,
            'coluna_kanban' => 'em_atendimento', 'agente_responsavel' => 'humano',
            'status' => 'aberto', 'aberto_em' => now(),
            'aguardando_orientacao_em' => now(), 'mensagem_espera_enviada' => true,
        ]);
        $alerta = AlertaInterno::create([
            'tenant_id' => $tenant->id, 'ticket_id' => $ticket->id, 'tipo' => 'duvida_ia',
            'titulo' => 'Agente pediu orientação', 'conteudo' => 'Dúvida sobre preço',
        ]);

        $response = $this->actingAs($user)->postJson("/api/painel/kanban/ticket/{$ticket->id}/orientar", [
            'orientacao' => 'O preço desse serviço é R$ 250.',
        ]);

        $response->assertStatus(422);

        $ticketFresco = $ticket->fresh();
        $this->assertNotNull($ticketFresco->aguardando_orientacao_em);
        $this->assertTrue($ticketFresco->mensagem_espera_enviada);

        $alertaFresco = $alerta->fresh();
        $this->assertNull($alertaFresco->resposta);
        $this->assertNull($alertaFresco->respondido_em);

        Bus::assertNotDispatched(\App\Jobs\SdrResponderJob::class);
    }
}
