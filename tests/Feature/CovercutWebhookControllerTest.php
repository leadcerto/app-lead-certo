<?php

namespace Tests\Feature;

use App\Jobs\SdrResponderJob;
use App\Models\Contato;
use App\Models\Mensagem;
use App\Models\Sequencia;
use App\Models\SequenciaMensagem;
use App\Models\Tenant;
use App\Models\TicketAtendimento;
use App\Models\WhatsappCanal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
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

    /**
     * Achado Crítico 1 da revisão final: um lead novo pelo canal oficial nunca
     * recebia a primeira mensagem da sequência porque SequenciaMensagemJob resolvia
     * o token via tokenUazapi() (sempre null pra um canal Covercut). Este teste
     * prova o caminho fim-a-fim: webhook cria ticket novo → SequenciaService dispara
     * o job → o job efetivamente faz o POST em /messages/send (sem Bus::fake(),
     * pra rodar a fila sync de verdade).
     */
    public function test_lead_novo_via_covercut_dispara_sequencia_que_realmente_envia_a_mensagem(): void
    {
        Http::fake(['*/messages/send' => Http::response(['id' => 'wamid.seq'], 200)]);

        $tenant = Tenant::factory()->create();
        WhatsappCanal::factory()->create([
            'tenant_id' => $tenant->id, 'tipo' => 'oficial', 'provider' => 'covercut',
            'config' => ['phone_number_id' => '950147584848138', 'webhook_secret' => 'segredo-abc'],
        ]);

        $sequencia = Sequencia::create([
            'tenant_id' => $tenant->id, 'nome' => 'Boas-vindas', 'coluna_kanban' => 'lead_novo', 'ativo' => true,
        ]);
        SequenciaMensagem::create([
            'tenant_id' => $tenant->id, 'sequencia_id' => $sequencia->id, 'ordem' => 1,
            'conteudo' => 'Oi! Recebemos sua mensagem.', 'delay_segundos' => 0, 'ativo' => true,
        ]);

        $payload = [
            'event' => 'message', 'direction' => 'inbound', 'from_number_id' => '950147584848138',
            'contact' => ['wa_id' => '5521988887777', 'name' => 'Sandro'],
            'message' => ['id' => 'wamid.novolead', 'type' => 'text', 'text' => 'Oi'],
        ];

        $this->postComAssinatura($payload, 'segredo-abc')->assertOk();

        Http::assertSent(fn ($request) => str_contains($request->url(), '/messages/send')
            && $request['to'] === '5521988887777'
            && $request['text']['body'] === 'Oi! Recebemos sua mensagem.');
    }

    public function test_webhook_com_phone_number_id_desconhecido_retorna_401_e_nao_404(): void
    {
        $payload = [
            'event' => 'message', 'direction' => 'inbound', 'from_number_id' => 'inexistente',
            'contact' => ['wa_id' => '5511999999999'],
            'message' => ['id' => 'x', 'type' => 'text', 'text' => 'oi'],
        ];

        $response = $this->postComAssinatura($payload, 'nao-importa');

        $response->assertStatus(401);
    }

    public function test_mensagem_inbound_nao_textual_e_logada_e_ignorada(): void
    {
        Bus::fake();
        Log::spy();

        $tenant = Tenant::factory()->create();
        WhatsappCanal::factory()->create([
            'tenant_id' => $tenant->id, 'tipo' => 'oficial', 'provider' => 'covercut',
            'config' => ['phone_number_id' => '950147584848138', 'webhook_secret' => 'segredo-abc'],
        ]);

        $payload = [
            'event' => 'message', 'direction' => 'inbound', 'from_number_id' => '950147584848138',
            'contact' => ['wa_id' => '5521988887777', 'name' => 'Sandro'],
            'message' => ['id' => 'wamid.midia', 'type' => 'sticker'],
        ];

        $this->postComAssinatura($payload, 'segredo-abc')->assertOk();

        Log::shouldHaveReceived('info')
            ->withArgs(fn ($message) => str_contains($message, 'mensagem não-texto ignorada'))
            ->once();
    }

    /**
     * Achado Importante 3 da revisão final: antes do branch por tipo existir, a
     * leitura de message.text era INCONDICIONAL — lida independente de
     * message.type. Depois virou condicionada a `$tipo === 'text'`, o que
     * significa que uma mensagem de produção real com message.type ausente,
     * com grafia diferente, ou um valor não previsto, passaria a cair
     * silenciosamente em "mensagem não-texto ignorada" em vez de ser lida como
     * antes. Este teste prova que o fallback de restauração recupera
     * message.text mesmo quando message.type não é nenhum dos tipos
     * conhecidos (aqui: ausente).
     */
    public function test_texto_e_lido_mesmo_com_message_type_ausente(): void
    {
        Bus::fake();

        $tenant = Tenant::factory()->create();
        WhatsappCanal::factory()->create([
            'tenant_id' => $tenant->id, 'tipo' => 'oficial', 'provider' => 'covercut',
            'config' => ['phone_number_id' => '950147584848138', 'webhook_secret' => 'segredo-abc'],
        ]);

        $payload = [
            'event' => 'message', 'direction' => 'inbound', 'from_number_id' => '950147584848138',
            'contact' => ['wa_id' => '5521988887777', 'name' => 'Sandro'],
            // sem 'type' — payload real diferente do documentado
            'message' => ['id' => 'wamid.semtipo', 'text' => 'mensagem sem type declarado'],
        ];

        $this->postComAssinatura($payload, 'segredo-abc')->assertOk();

        $mensagem = Mensagem::withoutGlobalScopes()->where('provider_message_id', 'wamid.semtipo')->first();
        $this->assertNotNull($mensagem, 'Mensagem deveria ter sido salva mesmo sem message.type');
        $this->assertSame('texto', $mensagem->tipo);
        $this->assertSame('mensagem sem type declarado', $mensagem->conteudo);
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

    public function test_contato_soft_deletado_com_telefone_e_restaurado_sem_estourar_excecao(): void
    {
        Bus::fake();

        $tenant = Tenant::factory()->create();
        WhatsappCanal::factory()->create([
            'tenant_id' => $tenant->id, 'tipo' => 'oficial', 'provider' => 'covercut',
            'config' => ['phone_number_id' => '950147584848138', 'webhook_secret' => 'segredo-abc'],
        ]);

        $contatoApagado = Contato::factory()->create(['telefone' => '5521988887777']);
        $contatoApagado->delete();
        $this->assertTrue($contatoApagado->trashed());

        $payload = [
            'event' => 'message', 'direction' => 'inbound', 'from_number_id' => '950147584848138',
            'contact' => ['wa_id' => '5521988887777', 'name' => 'Fulano'],
            'message' => ['id' => 'wamid.restaura', 'type' => 'text', 'text' => 'voltei'],
        ];

        $response = $this->postComAssinatura($payload, 'segredo-abc');

        $response->assertOk();

        $this->assertSame(1, Contato::where('telefone', '5521988887777')->count());
        $contatoApagado->refresh();
        $this->assertFalse($contatoApagado->trashed());

        $ticket = TicketAtendimento::withoutGlobalScopes()->where('contato_id', $contatoApagado->id)->firstOrFail();
        $this->assertDatabaseHas('mensagens', ['ticket_id' => $ticket->id, 'conteudo' => 'voltei', 'provider_message_id' => 'wamid.restaura']);
    }

    public function test_dispara_sdr_responder_job_quando_ticket_existente_com_bot(): void
    {
        Bus::fake();

        $tenant  = Tenant::factory()->create();
        $canal   = WhatsappCanal::factory()->create([
            'tenant_id' => $tenant->id, 'tipo' => 'oficial', 'provider' => 'covercut',
            'config' => ['phone_number_id' => '950147584848138', 'webhook_secret' => 'segredo-abc'],
        ]);
        $contato = Contato::factory()->create(['telefone' => '5521988887777']);
        TicketAtendimento::create([
            'tenant_id' => $tenant->id, 'contato_id' => $contato->id,
            'whatsapp_canal_id' => $canal->id,
            'coluna_kanban' => 'em_atendimento', 'agente_responsavel' => 'bot',
            'status' => 'aberto', 'aberto_em' => now(),
            'janela_expira_em' => now()->addHours(10),
        ]);

        $payload = [
            'event' => 'message', 'direction' => 'inbound', 'from_number_id' => '950147584848138',
            'contact' => ['wa_id' => '5521988887777'],
            'message' => ['id' => 'wamid.bot', 'type' => 'text', 'text' => 'quero saber o valor'],
        ];

        $this->postComAssinatura($payload, 'segredo-abc')->assertOk();

        Bus::assertDispatched(SdrResponderJob::class);
    }
}
