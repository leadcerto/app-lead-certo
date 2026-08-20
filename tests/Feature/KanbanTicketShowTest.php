<?php

namespace Tests\Feature;

use App\Models\AlertaInterno;
use App\Models\Contato;
use App\Models\Tenant;
use App\Models\TicketAtendimento;
use App\Models\User;
use App\Models\VinculoContatoTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class KanbanTicketShowTest extends TestCase
{
    use RefreshDatabase;

    public function test_retorna_o_estado_atual_do_ticket_direto_pelo_id(): void
    {
        $tenant  = Tenant::factory()->create();
        $contato = Contato::factory()->create(['nome' => 'Fulano']);
        $ticket  = TicketAtendimento::create([
            'tenant_id' => $tenant->id, 'contato_id' => $contato->id,
            'coluna_kanban' => 'encerrado', 'agente_responsavel' => 'humano',
            'status' => 'encerrado', 'aberto_em' => now(),
        ]);
        $user = User::factory()->create(['tenant_id' => $tenant->id, 'perfil' => 'dono', 'ativo' => true]);

        $response = $this->actingAs($user)->getJson("/api/painel/kanban/ticket/{$ticket->id}");

        $response->assertOk();
        $response->assertJsonFragment(['coluna_kanban' => 'encerrado']);
        $response->assertJsonFragment(['nome' => 'Fulano']);
    }

    public function test_inclui_nome_local_quando_ha_vinculo_com_nome_sugerido(): void
    {
        $tenant  = Tenant::factory()->create();
        $contato = Contato::factory()->create(['nome' => 'Sem Nome']);
        $ticket  = TicketAtendimento::create([
            'tenant_id' => $tenant->id, 'contato_id' => $contato->id,
            'coluna_kanban' => 'lead_novo', 'agente_responsavel' => 'bot',
            'status' => 'aberto', 'aberto_em' => now(),
        ]);
        VinculoContatoTenant::create([
            'contato_id' => $contato->id, 'tenant_id' => $tenant->id,
            'nome_sugerido' => 'Ciclano', 'auditoria_pendente' => true,
        ]);
        $user = User::factory()->create(['tenant_id' => $tenant->id, 'perfil' => 'dono', 'ativo' => true]);

        $response = $this->actingAs($user)->getJson("/api/painel/kanban/ticket/{$ticket->id}");

        $response->assertOk();
        $response->assertJsonFragment(['nome_local' => 'Ciclano']);
        $response->assertJsonFragment(['auditoria_pendente' => true]);
    }

    /**
     * Achado real 2026-08-20 (Leonardo): o painel "Aguardando orientação"
     * só mostrava um campo vazio, sem dizer qual foi a dúvida real do
     * agente. Anexa aqui o alerta que causou a pausa.
     */
    public function test_inclui_alerta_pendente_quando_ticket_esta_aguardando_orientacao(): void
    {
        $tenant  = Tenant::factory()->create();
        $contato = Contato::factory()->create();
        $ticket  = TicketAtendimento::create([
            'tenant_id' => $tenant->id, 'contato_id' => $contato->id,
            'coluna_kanban' => 'lead_novo', 'agente_responsavel' => 'bot',
            'status' => 'aberto', 'aberto_em' => now(),
            'aguardando_orientacao_em' => now(),
        ]);
        AlertaInterno::create([
            'tenant_id' => $tenant->id, 'ticket_id' => $ticket->id, 'tipo' => 'rejeicao_area_alucinada',
            'titulo' => 'Agente recusou atendimento por área sem instrução pra isso',
            'conteudo' => 'Resposta bloqueada antes de enviar: "Poxa, atende só aqui..."',
        ]);
        $user = User::factory()->create(['tenant_id' => $tenant->id, 'perfil' => 'dono', 'ativo' => true]);

        $response = $this->actingAs($user)->getJson("/api/painel/kanban/ticket/{$ticket->id}");

        $response->assertOk();
        $response->assertJsonPath('alerta_pendente.tipo', 'rejeicao_area_alucinada');
        $response->assertJsonPath('alerta_pendente.titulo', 'Agente recusou atendimento por área sem instrução pra isso');
    }

    public function test_nao_inclui_alerta_pendente_quando_ticket_nao_esta_aguardando(): void
    {
        $tenant  = Tenant::factory()->create();
        $contato = Contato::factory()->create();
        $ticket  = TicketAtendimento::create([
            'tenant_id' => $tenant->id, 'contato_id' => $contato->id,
            'coluna_kanban' => 'lead_novo', 'agente_responsavel' => 'bot',
            'status' => 'aberto', 'aberto_em' => now(),
        ]);
        $user = User::factory()->create(['tenant_id' => $tenant->id, 'perfil' => 'dono', 'ativo' => true]);

        $response = $this->actingAs($user)->getJson("/api/painel/kanban/ticket/{$ticket->id}");

        $response->assertOk();
        $response->assertJsonMissingPath('alerta_pendente');
    }
}
