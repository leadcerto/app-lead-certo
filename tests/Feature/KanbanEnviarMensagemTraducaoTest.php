<?php

namespace Tests\Feature;

use App\Models\Contato;
use App\Models\Mensagem;
use App\Models\Tenant;
use App\Models\TicketAtendimento;
use App\Models\User;
use App\Models\WhatsappCanal;
use App\Services\TraducaoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Item 11 do roteiro de 2026-08-20 — cenário exato descrito pelo Leonardo:
 * "eu falo com você em português e você traduz pra ele no inglês".
 */
class KanbanEnviarMensagemTraducaoTest extends TestCase
{
    use RefreshDatabase;

    private function criarTicket(Tenant $tenant, ?string $idiomaLead): TicketAtendimento
    {
        $canal   = WhatsappCanal::factory()->create(['tenant_id' => $tenant->id, 'config' => ['instance_token' => 'tok']]);
        $contato = Contato::factory()->create();

        return TicketAtendimento::create([
            'tenant_id' => $tenant->id, 'contato_id' => $contato->id,
            'whatsapp_canal_id' => $canal->id,
            'coluna_kanban' => 'em_atendimento', 'agente_responsavel' => 'humano',
            'status' => 'aberto', 'aberto_em' => now(), 'idioma_lead' => $idiomaLead,
        ]);
    }

    public function test_traduz_mensagem_do_atendente_pro_idioma_do_lead(): void
    {
        Http::fake(['*/send/text' => Http::response(['id' => 'msg123'], 200)]);
        $tenant = Tenant::factory()->create();
        $ticket = $this->criarTicket($tenant, 'en');
        $user   = User::factory()->create(['tenant_id' => $tenant->id, 'perfil' => 'dono', 'ativo' => true]);

        // O usuário de teste não define 'idioma' explícito, caindo no default
        // 'pt-BR' da migration da Task 1 — truncado pros 2 primeiros chars
        // antes de virar o $idiomaOrigem passado pra traduzir(), então 'pt'.
        $this->mock(TraducaoService::class, function ($mock) {
            $mock->shouldReceive('traduzir')
                ->once()
                ->with('Oi, tudo bem?', 'en', 'pt')
                ->andReturn('Hi, how are you?');
        });

        $response = $this->actingAs($user)->postJson("/api/painel/kanban/ticket/{$ticket->id}/mensagem", [
            'conteudo' => 'Oi, tudo bem?',
        ]);

        $response->assertCreated();

        Http::assertSent(fn ($request) => str_contains($request->url(), '/send/text')
            && str_contains(json_encode($request->data()), 'Hi, how are you?'));

        $mensagem = Mensagem::where('ticket_id', $ticket->id)->latest()->first();
        $this->assertSame('Hi, how are you?', $mensagem->conteudo);
        $this->assertSame('en', $mensagem->idioma);
        $this->assertSame('Oi, tudo bem?', $mensagem->conteudo_pt);
    }

    public function test_nao_traduz_quando_lead_fala_portugues(): void
    {
        Http::fake(['*/send/text' => Http::response(['id' => 'msg123'], 200)]);
        $tenant = Tenant::factory()->create();
        $ticket = $this->criarTicket($tenant, 'pt');
        $user   = User::factory()->create(['tenant_id' => $tenant->id, 'perfil' => 'dono', 'ativo' => true]);

        $this->mock(TraducaoService::class, fn ($mock) => $mock->shouldNotReceive('traduzir'));

        $response = $this->actingAs($user)->postJson("/api/painel/kanban/ticket/{$ticket->id}/mensagem", [
            'conteudo' => 'Oi, tudo bem?',
        ]);

        $response->assertCreated();
        $mensagem = Mensagem::where('ticket_id', $ticket->id)->latest()->first();
        $this->assertSame('Oi, tudo bem?', $mensagem->conteudo);
        $this->assertNull($mensagem->conteudo_pt);
    }
}
