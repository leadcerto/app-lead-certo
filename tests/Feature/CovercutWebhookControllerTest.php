<?php

namespace Tests\Feature;

use App\Models\Contato;
use App\Models\Mensagem;
use App\Models\Tenant;
use App\Models\TicketAtendimento;
use App\Models\WhatsappCanal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

class CovercutWebhookControllerTest extends TestCase
{
    use RefreshDatabase;

    private function postComAssinatura(array $payload, string $segredo)
    {
        $body = json_encode($payload);
        $assinatura = hash_hmac('sha256', $body, $segredo);

        return $this->call('POST', '/api/webhook/covercut', [], [], [], [
            'CONTENT_TYPE'         => 'application/json',
            'HTTP_X-BSP-Signature' => $assinatura,
        ], $body);
    }

    public function test_mensagem_inbound_cria_ticket_novo_com_janela_de_24h(): void
    {
        Bus::fake();

        $tenant = Tenant::factory()->create();
        $canal  = WhatsappCanal::factory()->create([
            'tenant_id' => $tenant->id, 'tipo' => 'oficial', 'provider' => 'covercut',
            'config' => ['phone_number_id' => '950147584848138', 'webhook_secret' => 'segredo-abc'],
        ]);

        $payload = [
            'event' => 'message', 'direction' => 'inbound', 'from_number_id' => '950147584848138',
            'contact' => ['wa_id' => '5521988887777', 'name' => 'Sandro'],
            'message' => ['id' => 'wamid.HBgMNTU0N001', 'type' => 'text', 'text' => 'Ola'],
        ];

        $response = $this->postComAssinatura($payload, 'segredo-abc');

        $response->assertOk();

        $contato = Contato::where('telefone', '5521988887777')->firstOrFail();
        $ticket  = TicketAtendimento::withoutGlobalScopes()->where('contato_id', $contato->id)->firstOrFail();

        $this->assertSame($canal->id, $ticket->whatsapp_canal_id);
        $this->assertFalse($ticket->janela_origem_anuncio);
        $this->assertTrue($ticket->janela_expira_em->between(now()->addHours(23), now()->addHours(25)));
        $this->assertDatabaseHas('mensagens', ['ticket_id' => $ticket->id, 'conteudo' => 'Ola', 'provider_message_id' => 'wamid.HBgMNTU0N001']);
    }

    public function test_mensagem_com_referral_de_anuncio_usa_janela_de_72h(): void
    {
        Bus::fake();

        $tenant = Tenant::factory()->create();
        WhatsappCanal::factory()->create([
            'tenant_id' => $tenant->id, 'tipo' => 'oficial', 'provider' => 'covercut',
            'config' => ['phone_number_id' => '950147584848138', 'webhook_secret' => 'segredo-abc'],
        ]);

        $payload = [
            'event' => 'message', 'direction' => 'inbound', 'from_number_id' => '950147584848138',
            'contact' => ['wa_id' => '5521977776666', 'name' => 'Ciclana'],
            'message' => ['id' => 'wamid.002', 'type' => 'text', 'text' => 'Vim do anúncio', 'referral' => ['source_id' => 'ad123', 'ctwa_clid' => 'abc123']],
        ];

        $this->postComAssinatura($payload, 'segredo-abc')->assertOk();

        $ticket = TicketAtendimento::withoutGlobalScopes()->where('contato_id', Contato::where('telefone', '5521977776666')->value('id'))->firstOrFail();

        $this->assertTrue($ticket->janela_origem_anuncio);
        $this->assertTrue($ticket->janela_expira_em->between(now()->addHours(71), now()->addHours(73)));
    }

    public function test_rejeita_assinatura_invalida(): void
    {
        $tenant = Tenant::factory()->create();
        WhatsappCanal::factory()->create([
            'tenant_id' => $tenant->id, 'tipo' => 'oficial', 'provider' => 'covercut',
            'config' => ['phone_number_id' => '999', 'webhook_secret' => 'segredo-certo'],
        ]);

        $payload = ['event' => 'message', 'direction' => 'inbound', 'from_number_id' => '999', 'contact' => ['wa_id' => '5511999999999'], 'message' => ['id' => 'x', 'type' => 'text', 'text' => 'oi']];
        $response = $this->postComAssinatura($payload, 'segredo-errado');

        $response->assertStatus(401);
    }

    public function test_ignora_mensagem_duplicada(): void
    {
        Bus::fake();

        $tenant = Tenant::factory()->create();
        $canal  = WhatsappCanal::factory()->create([
            'tenant_id' => $tenant->id, 'tipo' => 'oficial', 'provider' => 'covercut',
            'config' => ['phone_number_id' => '950147584848138', 'webhook_secret' => 'segredo-abc'],
        ]);

        $payload = [
            'event' => 'message', 'direction' => 'inbound', 'from_number_id' => '950147584848138',
            'contact' => ['wa_id' => '5521988887777', 'name' => 'Sandro'],
            'message' => ['id' => 'wamid.dup', 'type' => 'text', 'text' => 'primeira'],
        ];

        $this->postComAssinatura($payload, 'segredo-abc')->assertOk();
        $this->postComAssinatura($payload, 'segredo-abc')->assertOk();

        $this->assertSame(1, Mensagem::withoutGlobalScopes()->where('provider_message_id', 'wamid.dup')->count());
    }

    public function test_ticket_ja_aberto_recebe_atualizacao_da_janela_sem_criar_ticket_novo(): void
    {
        Bus::fake();

        $tenant  = Tenant::factory()->create();
        $canal   = WhatsappCanal::factory()->create([
            'tenant_id' => $tenant->id, 'tipo' => 'oficial', 'provider' => 'covercut',
            'config' => ['phone_number_id' => '950147584848138', 'webhook_secret' => 'segredo-abc'],
        ]);
        $contato = Contato::factory()->create(['telefone' => '5521988887777']);
        $ticketExistente = TicketAtendimento::create([
            'tenant_id' => $tenant->id, 'contato_id' => $contato->id,
            'whatsapp_canal_id' => $canal->id,
            'coluna_kanban' => 'em_atendimento', 'agente_responsavel' => 'humano',
            'status' => 'aberto', 'aberto_em' => now(),
            'janela_expira_em' => now()->subMinutes(5),
        ]);

        $payload = [
            'event' => 'message', 'direction' => 'inbound', 'from_number_id' => '950147584848138',
            'contact' => ['wa_id' => '5521988887777'],
            'message' => ['id' => 'wamid.reabre', 'type' => 'text', 'text' => 'ainda estou aqui'],
        ];

        $this->postComAssinatura($payload, 'segredo-abc')->assertOk();

        $this->assertSame(1, TicketAtendimento::withoutGlobalScopes()->where('contato_id', $contato->id)->count());
        $ticketExistente->refresh();
        $this->assertTrue($ticketExistente->janela_expira_em->isFuture());
    }
}
