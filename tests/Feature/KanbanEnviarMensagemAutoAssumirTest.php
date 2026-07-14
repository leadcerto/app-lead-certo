<?php

namespace Tests\Feature;

use App\Models\Contato;
use App\Models\Tenant;
use App\Models\TicketAtendimento;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class KanbanEnviarMensagemAutoAssumirTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake(['*' => Http::response(['ok' => true], 200)]);
    }

    public function test_enviar_mensagem_assume_o_ticket_automaticamente(): void
    {
        $tenant  = Tenant::factory()->create();
        $user    = User::factory()->create(['tenant_id' => $tenant->id, 'perfil' => 'dono', 'ativo' => true]);
        $contato = Contato::factory()->create();
        $ticket  = TicketAtendimento::create([
            'tenant_id' => $tenant->id, 'contato_id' => $contato->id,
            'coluna_kanban' => 'em_atendimento', 'agente_responsavel' => 'bot',
            'status' => 'aberto', 'aberto_em' => now(),
        ]);

        $response = $this->actingAs($user)->postJson("/api/painel/kanban/ticket/{$ticket->id}/mensagem", [
            'conteudo' => 'Oi, tudo bem?',
        ]);

        $response->assertCreated();
        $ticket->refresh();
        $this->assertSame('humano', $ticket->agente_responsavel);
        $this->assertSame($user->id, $ticket->vendedor_id);
    }

    public function test_enviar_mensagem_bloqueia_se_ja_assumido_por_outro_usuario(): void
    {
        $tenant  = Tenant::factory()->create();
        $dono    = User::factory()->create(['tenant_id' => $tenant->id, 'perfil' => 'dono', 'ativo' => true]);
        $outro   = User::factory()->create(['tenant_id' => $tenant->id, 'perfil' => 'dono', 'ativo' => true, 'nome' => 'Outro Vendedor']);
        $contato = Contato::factory()->create();
        $ticket  = TicketAtendimento::create([
            'tenant_id' => $tenant->id, 'contato_id' => $contato->id,
            'coluna_kanban' => 'em_atendimento', 'agente_responsavel' => 'humano',
            'vendedor_id' => $outro->id, 'status' => 'aberto', 'aberto_em' => now(),
        ]);

        $response = $this->actingAs($dono)->postJson("/api/painel/kanban/ticket/{$ticket->id}/mensagem", [
            'conteudo' => 'Oi, tudo bem?',
        ]);

        $response->assertStatus(409);
    }
}
