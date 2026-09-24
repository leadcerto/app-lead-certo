<?php

namespace Tests\Feature;

use App\Models\Contato;
use App\Models\Tenant;
use App\Models\TicketAtendimento;
use App\Models\WhatsappCanal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Fase 4 do plano do canal WhatsApp Messenger próprio (23/09) — espelha os
 * testes de UazapiWebhookReativacaoTest/UazapiWebhookColunaDinamicaTest, mas
 * pro payload já normalizado que o microserviço próprio manda (ver
 * MessengerProprioWebhookController).
 */
class MessengerProprioWebhookTest extends TestCase
{
    use RefreshDatabase;

    private function criarCanal(Tenant $tenant, string $webhookToken, string $sessionId = 'sessao-1'): WhatsappCanal
    {
        return WhatsappCanal::factory()->create([
            'tenant_id'     => $tenant->id,
            'provider'      => 'messenger_proprio',
            'webhook_token' => $webhookToken,
            'config'        => ['session_id' => $sessionId],
        ]);
    }

    public function test_token_invalido_retorna_401(): void
    {
        $response = $this->postJson('/api/webhook/messenger-proprio/token-que-nao-existe', ['tipo' => 'mensagem']);

        $response->assertStatus(401);
    }

    public function test_mensagem_de_lead_novo_cria_contato_ticket_e_mensagem(): void
    {
        Http::fake(['*' => Http::response(['ok' => true], 200)]);

        $tenant = Tenant::factory()->create();
        $canal  = $this->criarCanal($tenant, 'wh-messenger-1');

        $response = $this->postJson('/api/webhook/messenger-proprio/wh-messenger-1', [
            'tipo'      => 'mensagem',
            'sessionId' => 'sessao-1',
            'messageId' => 'msg-1',
            'fromMe'    => false,
            'numero'    => '5511911112222',
            'pushName'  => 'João Silva',
            'texto'     => 'Oi, quero fazer uma mudança',
            'timestamp' => now()->timestamp,
        ]);

        $response->assertOk();

        $contato = Contato::where('telefone', '5511911112222')->first();
        $this->assertNotNull($contato);

        $ticket = TicketAtendimento::withoutGlobalScopes()->where('contato_id', $contato->id)->first();
        $this->assertNotNull($ticket);
        $this->assertSame($canal->id, $ticket->whatsapp_canal_id);
        $this->assertSame('aberto', $ticket->status);

        $this->assertDatabaseHas('mensagens', [
            'ticket_id'           => $ticket->id,
            'conteudo'            => 'Oi, quero fazer uma mudança',
            'provider_message_id' => 'msg-1',
            'remetente'           => 'lead',
        ]);
    }

    public function test_mensagem_duplicada_pelo_messageid_e_ignorada(): void
    {
        Http::fake(['*' => Http::response(['ok' => true], 200)]);

        $tenant = Tenant::factory()->create();
        $this->criarCanal($tenant, 'wh-messenger-dup');

        $payload = [
            'tipo' => 'mensagem', 'sessionId' => 'sessao-1', 'messageId' => 'msg-dup',
            'fromMe' => false, 'numero' => '5511922223333', 'pushName' => 'Ana',
            'texto' => 'Primeira mensagem', 'timestamp' => now()->timestamp,
        ];

        $this->postJson('/api/webhook/messenger-proprio/wh-messenger-dup', $payload)->assertOk();

        $payload['texto'] = 'Segunda mensagem (mesmo messageId, não devia entrar)';
        $this->postJson('/api/webhook/messenger-proprio/wh-messenger-dup', $payload)->assertOk();

        $this->assertSame(1, \App\Models\Mensagem::withoutGlobalScopes()->where('provider_message_id', 'msg-dup')->count());
    }

    public function test_mensagem_fromme_transfere_ticket_para_humano_sem_criar_ticket_novo(): void
    {
        Http::fake(['*' => Http::response(['ok' => true], 200)]);

        $tenant  = Tenant::factory()->create();
        $canal   = $this->criarCanal($tenant, 'wh-messenger-frommme');
        $contato = Contato::factory()->create(['telefone' => '5511933334444']);
        $ticket  = TicketAtendimento::create([
            'tenant_id' => $tenant->id, 'contato_id' => $contato->id,
            'coluna_kanban' => 'em_atendimento', 'agente_responsavel' => 'bot',
            'status' => 'aberto', 'aberto_em' => now(),
        ]);

        $response = $this->postJson('/api/webhook/messenger-proprio/wh-messenger-frommme', [
            'tipo' => 'mensagem', 'sessionId' => 'sessao-1', 'messageId' => 'msg-fromme-1',
            'fromMe' => true, 'numero' => '5511933334444', 'texto' => 'Já te respondo por aqui',
            'timestamp' => now()->timestamp,
        ]);

        $response->assertOk();
        $ticket->refresh();
        $this->assertSame('humano', $ticket->agente_responsavel);
        $this->assertSame($canal->id, $ticket->whatsapp_canal_id);
        $this->assertSame(1, TicketAtendimento::withoutGlobalScopes()->where('contato_id', $contato->id)->count());
    }

    public function test_ticket_encerrado_reabre_na_coluna_de_antes_de_encerrar(): void
    {
        Http::fake([
            'openrouter.ai/*' => Http::response([
                'choices' => [['message' => ['content' => 'REABRIR']]],
                'usage'   => ['prompt_tokens' => 10, 'completion_tokens' => 2],
            ], 200),
            '*' => Http::response(['ok' => true], 200),
        ]);

        $tenant  = Tenant::factory()->create();
        $this->criarCanal($tenant, 'wh-messenger-reativa');
        $contato = Contato::factory()->create(['telefone' => '5511944445555']);
        $ticket  = TicketAtendimento::create([
            'tenant_id' => $tenant->id, 'contato_id' => $contato->id,
            'coluna_kanban' => 'aguardando_orcamento', 'agente_responsavel' => 'humano',
            'status' => 'aberto', 'aberto_em' => now(),
        ]);
        $ticket->update($ticket->dadosParaEncerrar(['tag_desfecho' => 'sem_resposta', 'encerrado_em' => now()]));

        $response = $this->postJson('/api/webhook/messenger-proprio/wh-messenger-reativa', [
            'tipo' => 'mensagem', 'sessionId' => 'sessao-1', 'messageId' => 'msg-reativa-1',
            'fromMe' => false, 'numero' => '5511944445555', 'pushName' => 'Carlos',
            'texto' => 'Oi, ainda quero fazer a mudança', 'timestamp' => now()->timestamp,
        ]);

        $response->assertOk();
        $ticket->refresh();
        $this->assertSame('aguardando_orcamento', $ticket->coluna_kanban);
        $this->assertSame('aberto', $ticket->status);
    }

    public function test_evento_de_conexao_atualiza_status_e_telefone_do_canal(): void
    {
        $tenant = Tenant::factory()->create();
        $canal  = $this->criarCanal($tenant, 'wh-messenger-conexao');

        $response = $this->postJson('/api/webhook/messenger-proprio/wh-messenger-conexao', [
            'tipo' => 'conexao', 'sessionId' => 'sessao-1', 'status' => 'connected', 'phone' => '5511999998888',
        ]);

        $response->assertOk();
        $this->assertDatabaseHas('whatsapp_canais', [
            'id' => $canal->id, 'status' => 'connected', 'phone' => '5511999998888',
        ]);
    }

    public function test_evento_de_desconexao_atualiza_status_do_canal(): void
    {
        $tenant = Tenant::factory()->create();
        $canal  = $this->criarCanal($tenant, 'wh-messenger-desconexao');
        $canal->update(['status' => 'connected']);

        $response = $this->postJson('/api/webhook/messenger-proprio/wh-messenger-desconexao', [
            'tipo' => 'conexao', 'sessionId' => 'sessao-1', 'status' => 'disconnected', 'motivo' => 'loggedOut',
        ]);

        $response->assertOk();
        $this->assertDatabaseHas('whatsapp_canais', ['id' => $canal->id, 'status' => 'disconnected']);
    }
}
