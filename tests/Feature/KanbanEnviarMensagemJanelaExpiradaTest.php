<?php

namespace Tests\Feature;

use App\Models\Contato;
use App\Models\Mensagem;
use App\Models\Tenant;
use App\Models\TicketAtendimento;
use App\Models\User;
use App\Models\WhatsappCanal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Achado real 2026-09-21 (Amanda, Frete Rio): o atendente tentou mandar uma
 * mensagem manual pra uma lead que não respondia há mais de 3 dias, pelo
 * canal oficial (Covercut/Meta). O envio falhou — corretamente, é a própria
 * Meta que bloqueia mensagem livre fora da janela de 24h — mas o painel
 * mostrava "Falha ao enviar pelo WhatsApp. Verifique a conexão do canal.",
 * mensagem enganosa (o canal estava conectado normalmente). Corrigido pra
 * mostrar o motivo real quando é especificamente a janela expirada.
 */
class KanbanEnviarMensagemJanelaExpiradaTest extends TestCase
{
    use RefreshDatabase;

    private function criarTicketOficial(?\Illuminate\Support\Carbon $janelaExpiraEm): TicketAtendimento
    {
        $tenant  = Tenant::factory()->create();
        $canal   = WhatsappCanal::factory()->create([
            'tenant_id' => $tenant->id, 'tipo' => 'oficial', 'provider' => 'covercut',
            'config'    => ['phone_number_id' => '123456', 'webhook_secret' => 'segredo'],
        ]);
        $contato = Contato::factory()->create(['telefone' => '5511988888888']);

        return TicketAtendimento::create([
            'tenant_id' => $tenant->id, 'contato_id' => $contato->id,
            'whatsapp_canal_id' => $canal->id,
            'coluna_kanban' => 'em_atendimento', 'agente_responsavel' => 'humano',
            'status' => 'aberto', 'aberto_em' => now(),
            'janela_expira_em' => $janelaExpiraEm,
        ]);
    }

    public function test_janela_expirada_retorna_mensagem_de_erro_especifica(): void
    {
        Http::fake(); // nenhuma chamada HTTP deve acontecer, janela já bloqueia antes

        $ticket = $this->criarTicketOficial(now()->subDays(3));
        $user   = User::factory()->create(['tenant_id' => $ticket->tenant_id, 'perfil' => 'dono', 'ativo' => true]);

        $response = $this->actingAs($user)->postJson("/api/painel/kanban/ticket/{$ticket->id}/mensagem", [
            'conteudo' => 'Amanda, bom dia... aguardo sua resposta',
        ]);

        $response->assertStatus(502);
        $response->assertJsonFragment(['message' => 'A janela de 24h do WhatsApp expirou — o lead precisa mandar uma mensagem primeiro pra você poder responder de novo.']);
        $this->assertSame(0, Mensagem::where('ticket_id', $ticket->id)->count());
    }

    public function test_outra_falha_continua_com_mensagem_generica(): void
    {
        Http::fake(['*/messages/send' => Http::response(['error' => ['message' => 'Internal error', 'code' => 500]], 500)]);

        $ticket = $this->criarTicketOficial(now()->addHours(10));
        $user   = User::factory()->create(['tenant_id' => $ticket->tenant_id, 'perfil' => 'dono', 'ativo' => true]);

        $response = $this->actingAs($user)->postJson("/api/painel/kanban/ticket/{$ticket->id}/mensagem", [
            'conteudo' => 'Oi!',
        ]);

        $response->assertStatus(502);
        $response->assertJsonFragment(['message' => 'Falha ao enviar pelo WhatsApp. Verifique a conexão do canal.']);
    }
}
