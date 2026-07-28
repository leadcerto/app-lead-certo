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
 * Cobre a resolução de WhatsappCanal em handle(): o caminho normal (lookup
 * direto por whatsapp_canais.webhook_token), o fallback transitório pro token
 * legado em tenants.uazapi_webhook_token (Task 11, item 1 do review), e os 3
 * comportamentos introduzidos por este task que não tinham cobertura de teste
 * (Task 11, item 2 do review): whatsapp_canal_id em ambos os caminhos de
 * criação de ticket, e handleConexao atualizando o canal (não o tenant).
 */
class UazapiWebhookCanalResolutionTest extends TestCase
{
    use RefreshDatabase;

    private function criarCanal(Tenant $tenant, string $webhookToken, string $instanceToken): WhatsappCanal
    {
        return WhatsappCanal::factory()->create([
            'tenant_id'     => $tenant->id,
            'webhook_token' => $webhookToken,
            'config'        => ['instance_token' => $instanceToken],
        ]);
    }

    public function test_token_existente_apenas_em_whatsapp_canais_resolve_normalmente(): void
    {
        Http::fake(['*' => Http::response(['ok' => true], 200)]);

        $tenant = Tenant::factory()->create();
        $canal  = $this->criarCanal($tenant, 'token-canal-direto', 'instance-direto');

        $response = $this->postJson('/api/webhook/uazapi/token-canal-direto', [
            'EventType' => 'messages',
            'message'   => [
                'fromMe'  => false,
                'isGroup' => false,
                'chatid'  => '5511911112222@s.whatsapp.net',
                'text'    => 'Olá, quero um orçamento',
            ],
        ]);

        $response->assertOk();
        $ticket = TicketAtendimento::where('tenant_id', $tenant->id)->firstOrFail();
        $this->assertSame($canal->id, $ticket->whatsapp_canal_id);
    }

    public function test_token_legado_do_tenant_sem_canal_correspondente_resolve_via_fallback(): void
    {
        Http::fake(['*' => Http::response(['ok' => true], 200)]);

        // Tenant "pré-backfill": token legado salvo, mas sem row em whatsapp_canais
        // criada com esse webhook_token — simula lacuna no backfill do Task 3.
        $tenant = Tenant::factory()->create([
            'uazapi_webhook_token'  => 'token-legado-sem-canal',
            'uazapi_instance_token' => 'instance-legado',
        ]);
        $canal = WhatsappCanal::factory()->create([
            'tenant_id'     => $tenant->id,
            'provider'      => 'uazapi',
            'webhook_token' => 'token-diferente-do-legado',
            'config'        => ['instance_token' => 'instance-legado'],
        ]);

        $response = $this->postJson('/api/webhook/uazapi/token-legado-sem-canal', [
            'EventType' => 'messages',
            'message'   => [
                'fromMe'  => false,
                'isGroup' => false,
                'chatid'  => '5511933334444@s.whatsapp.net',
                'text'    => 'Oi, ainda quero fazer a mudança',
            ],
        ]);

        $response->assertOk();
        $ticket = TicketAtendimento::where('tenant_id', $tenant->id)->firstOrFail();
        // Resolveu via fallback pro canal uazapi do tenant, mesmo com webhook_token diferente.
        $this->assertSame($canal->id, $ticket->whatsapp_canal_id);
    }

    public function test_token_sem_match_em_canal_ou_tenant_legado_retorna_401(): void
    {
        $response = $this->postJson('/api/webhook/uazapi/token-que-nao-existe-em-lugar-nenhum', [
            'EventType' => 'messages',
            'message'   => [
                'fromMe'  => false,
                'isGroup' => false,
                'chatid'  => '5511900000000@s.whatsapp.net',
                'text'    => 'oi',
            ],
        ]);

        $response->assertStatus(401);
    }

    public function test_mensagem_lead_cria_ticket_com_whatsapp_canal_id(): void
    {
        Http::fake(['*' => Http::response(['ok' => true], 200)]);

        $tenant = Tenant::factory()->create();
        $canal  = $this->criarCanal($tenant, 'token-lead-canal', 'instance-lead-canal');

        $this->postJson('/api/webhook/uazapi/token-lead-canal', [
            'EventType' => 'messages',
            'message'   => [
                'fromMe'  => false,
                'isGroup' => false,
                'chatid'  => '5511922223333@s.whatsapp.net',
                'text'    => 'Olá, quero um orçamento de frete',
            ],
        ]);

        $ticket = TicketAtendimento::where('tenant_id', $tenant->id)->firstOrFail();
        $this->assertSame($canal->id, $ticket->whatsapp_canal_id);
    }

    public function test_chamada_perdida_cria_ticket_com_whatsapp_canal_id(): void
    {
        Http::fake(['*' => Http::response(['ok' => true], 200)]);

        $tenant = Tenant::factory()->create();
        $canal  = $this->criarCanal($tenant, 'token-chamada-canal', 'instance-chamada-canal');

        $this->postJson('/api/webhook/uazapi/token-chamada-canal', [
            'EventType' => 'messages',
            'message'   => [
                'fromMe'      => false,
                'isGroup'     => false,
                'chatid'      => '5511944445555@s.whatsapp.net',
                'messageType' => 'call_log',
                'senderName'  => 'Fulano',
            ],
        ]);

        $ticket = TicketAtendimento::where('tenant_id', $tenant->id)->firstOrFail();
        $this->assertSame($canal->id, $ticket->whatsapp_canal_id);
    }

    public function test_evento_connection_atualiza_status_do_canal_nao_do_tenant(): void
    {
        $tenant = Tenant::factory()->create();
        $canal  = $this->criarCanal($tenant, 'token-conexao', 'instance-conexao');
        $canal->update(['status' => 'disconnected', 'connected_since' => null]);

        $response = $this->postJson('/api/webhook/uazapi/token-conexao', [
            'EventType' => 'connection',
            'data'      => ['status' => 'open'],
        ]);

        $response->assertOk();
        $canal->refresh();
        $this->assertSame('connected', $canal->status);
        $this->assertNotNull($canal->connected_since);
    }

    public function test_evento_connection_close_marca_canal_desconectado(): void
    {
        $tenant = Tenant::factory()->create();
        $canal  = $this->criarCanal($tenant, 'token-conexao-close', 'instance-conexao-close');
        $canal->update(['status' => 'connected']);

        $this->postJson('/api/webhook/uazapi/token-conexao-close', [
            'EventType' => 'connection',
            'data'      => ['status' => 'close'],
        ]);

        $this->assertSame('disconnected', $canal->fresh()->status);
    }
}
