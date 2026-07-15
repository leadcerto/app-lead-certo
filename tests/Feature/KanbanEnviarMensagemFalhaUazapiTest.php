<?php

namespace Tests\Feature;

use App\Models\Contato;
use App\Models\Mensagem;
use App\Models\Tenant;
use App\Models\TicketAtendimento;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class KanbanEnviarMensagemFalhaUazapiTest extends TestCase
{
    use RefreshDatabase;

    private function criarTicket(): TicketAtendimento
    {
        $tenant  = Tenant::factory()->create(['uazapi_instance_token' => 'token-teste']);
        $contato = Contato::factory()->create();

        return TicketAtendimento::create([
            'tenant_id' => $tenant->id, 'contato_id' => $contato->id,
            'coluna_kanban' => 'em_atendimento', 'agente_responsavel' => 'humano',
            'status' => 'aberto', 'aberto_em' => now(),
        ]);
    }

    public function test_falha_da_uazapi_retorna_erro_e_nao_salva_mensagem_como_enviada(): void
    {
        Http::fake(['*/send/text' => Http::response(['code' => 401, 'message' => 'Invalid token.'], 401)]);

        $ticket = $this->criarTicket();
        $user   = User::factory()->create(['tenant_id' => $ticket->tenant_id, 'perfil' => 'dono', 'ativo' => true]);

        $response = $this->actingAs($user)->postJson("/api/painel/kanban/ticket/{$ticket->id}/mensagem", [
            'conteudo' => 'Oi, tudo bem?',
        ]);

        $response->assertStatus(502);
        $this->assertSame(0, Mensagem::where('ticket_id', $ticket->id)->count());
    }

    public function test_sucesso_da_uazapi_continua_salvando_a_mensagem_normalmente(): void
    {
        Http::fake(['*/send/text' => Http::response(['id' => 'msg123'], 200)]);

        $ticket = $this->criarTicket();
        $user   = User::factory()->create(['tenant_id' => $ticket->tenant_id, 'perfil' => 'dono', 'ativo' => true]);

        $response = $this->actingAs($user)->postJson("/api/painel/kanban/ticket/{$ticket->id}/mensagem", [
            'conteudo' => 'Oi, tudo bem?',
        ]);

        $response->assertCreated();
        $this->assertSame(1, Mensagem::where('ticket_id', $ticket->id)->count());
    }
}
