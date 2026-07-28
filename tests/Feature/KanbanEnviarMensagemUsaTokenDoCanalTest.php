<?php

namespace Tests\Feature;

use App\Models\Contato;
use App\Models\Tenant;
use App\Models\TicketAtendimento;
use App\Models\User;
use App\Models\WhatsappCanal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class KanbanEnviarMensagemUsaTokenDoCanalTest extends TestCase
{
    use RefreshDatabase;

    public function test_envio_de_texto_usa_o_token_do_canal_do_ticket_nao_o_legado_do_tenant(): void
    {
        Http::fake(['*/send/text' => Http::response(['id' => 'msg123'], 200)]);

        $tenant  = Tenant::factory()->create(['uazapi_instance_token' => 'token-legado-do-tenant']);
        $canal   = WhatsappCanal::factory()->create([
            'tenant_id' => $tenant->id,
            'config'    => ['instance_token' => 'token-do-canal-certo'],
        ]);
        $contato = Contato::factory()->create();
        $ticket  = TicketAtendimento::create([
            'tenant_id' => $tenant->id, 'contato_id' => $contato->id,
            'whatsapp_canal_id' => $canal->id,
            'coluna_kanban' => 'em_atendimento', 'agente_responsavel' => 'humano',
            'status' => 'aberto', 'aberto_em' => now(),
        ]);
        $user = User::factory()->create(['tenant_id' => $tenant->id, 'perfil' => 'dono', 'ativo' => true]);

        $response = $this->actingAs($user)->postJson("/api/painel/kanban/ticket/{$ticket->id}/mensagem", [
            'conteudo' => 'Oi, tudo bem?',
        ]);

        $response->assertCreated();

        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/send/text')
                && $request->hasHeader('token', 'token-do-canal-certo');
        });
    }
}
