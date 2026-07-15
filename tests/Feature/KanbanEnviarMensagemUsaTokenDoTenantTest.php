<?php

namespace Tests\Feature;

use App\Models\Contato;
use App\Models\Tenant;
use App\Models\TicketAtendimento;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class KanbanEnviarMensagemUsaTokenDoTenantTest extends TestCase
{
    use RefreshDatabase;

    public function test_envio_de_texto_usa_o_token_da_instancia_do_tenant_nao_o_de_admin(): void
    {
        Http::fake(['*/send/text' => Http::response(['id' => 'msg123'], 200)]);

        $tenant  = Tenant::factory()->create(['uazapi_instance_token' => 'token-do-tenant-abc']);
        $contato = Contato::factory()->create();
        $ticket  = TicketAtendimento::create([
            'tenant_id' => $tenant->id, 'contato_id' => $contato->id,
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
                && $request->hasHeader('token', 'token-do-tenant-abc');
        });
    }
}
