<?php

namespace Tests\Feature;

use App\Models\Mensagem;
use App\Models\Tenant;
use App\Models\TicketAtendimento;
use App\Models\WhatsappCanal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Achado real 2026-09-30 (Leonardo, tenant "Lead Certo" #2): pedido pra
 * desativar um canal Uazapi sem apagar (pra poder reativar depois se a
 * conexão voltar a funcionar satisfatoriamente). Só marcar `status` !=
 * 'connected' no banco não bastava — handleMensagem() nunca checava o
 * status do canal antes de processar, então mesmo um canal "desativado" na
 * nossa base continuaria criando ticket/respondendo se a Uazapi ainda
 * mandasse o webhook (sessão ainda viva do lado deles).
 */
class UazapiWebhookCanalDesativadoTest extends TestCase
{
    use RefreshDatabase;

    private function criarCanal(Tenant $tenant, ?\Illuminate\Support\Carbon $desativadoEm): WhatsappCanal
    {
        return WhatsappCanal::factory()->create([
            'tenant_id'     => $tenant->id,
            'webhook_token' => 'token-canal-desativado',
            'status'        => 'connected',
            'desativado_em' => $desativadoEm,
            'config'        => ['instance_token' => 'instance-desativada'],
        ]);
    }

    public function test_mensagem_de_canal_desativado_manualmente_nao_cria_ticket_nem_mensagem(): void
    {
        Http::fake(['*' => Http::response(['ok' => true], 200)]);

        $tenant = Tenant::factory()->create();
        $this->criarCanal($tenant, now());

        $response = $this->postJson('/api/webhook/uazapi/token-canal-desativado', [
            'EventType' => 'messages',
            'message'   => [
                'fromMe'  => false,
                'isGroup' => false,
                'chatid'  => '5511911112222@s.whatsapp.net',
                'text'    => 'Olá, quero um orçamento',
            ],
        ]);

        $response->assertOk();
        $this->assertSame(0, TicketAtendimento::where('tenant_id', $tenant->id)->count());
        $this->assertSame(0, Mensagem::withoutGlobalScopes()->where('tenant_id', $tenant->id)->count());
    }

    public function test_mensagem_de_canal_nao_desativado_continua_processando_normalmente(): void
    {
        Http::fake(['*' => Http::response(['ok' => true], 200)]);

        $tenant = Tenant::factory()->create();
        $this->criarCanal($tenant, null);

        $this->postJson('/api/webhook/uazapi/token-canal-desativado', [
            'EventType' => 'messages',
            'message'   => [
                'fromMe'  => false,
                'isGroup' => false,
                'chatid'  => '5511911112222@s.whatsapp.net',
                'text'    => 'Olá, quero um orçamento',
            ],
        ])->assertOk();

        $this->assertSame(1, TicketAtendimento::where('tenant_id', $tenant->id)->count());
    }

    /**
     * Evento de conexão continua atualizando `status` mesmo com o canal
     * desativado (reflete a realidade da sessão do lado da Uazapi) — mas
     * NÃO reativa sozinho: `desativado_em` só é limpo por decisão manual,
     * nunca por um webhook de reconexão. Sem essa separação, a Uazapi podia
     * "reativar" um canal que o Leonardo desligou de propósito só porque a
     * sessão dela continuou viva no fundo.
     */
    public function test_evento_de_conexao_atualiza_status_mas_nao_reativa_canal_desativado(): void
    {
        $tenant = Tenant::factory()->create();
        $desativadoEm = now()->subHour();
        $canal  = $this->criarCanal($tenant, $desativadoEm);
        $canal->update(['status' => 'disconnected']);

        $this->postJson('/api/webhook/uazapi/token-canal-desativado', [
            'EventType' => 'connection',
            'data'      => ['status' => 'open'],
        ])->assertOk();

        $canal->refresh();
        $this->assertSame('connected', $canal->status);
        $this->assertNotNull($canal->desativado_em);
    }
}
