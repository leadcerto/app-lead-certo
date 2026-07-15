<?php

namespace Tests\Feature;

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
}
