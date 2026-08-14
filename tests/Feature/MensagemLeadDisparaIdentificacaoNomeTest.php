<?php

namespace Tests\Feature;

use App\Jobs\IdentificarNomeConversaJob;
use App\Models\Contato;
use App\Models\Mensagem;
use App\Models\Tenant;
use App\Models\TicketAtendimento;
use App\Models\WhatsappCanal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Achado real (2026-08-14): quando o lead se identifica pelo próprio nome
 * dentro da conversa, sem o bot ter perguntado, nada capturava isso — o
 * contato ficava com o telefone como nome (placeholder da criação) até
 * alguém corrigir manualmente. Já existia contatos:identificar-nomes, mas
 * roda 1x/dia (00:05) processando só 20 contatos por vez — não pega a
 * tempo pra reduzir a confusão no card do Kanban logo depois da ligação.
 */
class MensagemLeadDisparaIdentificacaoNomeTest extends TestCase
{
    use RefreshDatabase;

    private function criarTicket(string $nomeContato): TicketAtendimento
    {
        $tenant  = Tenant::factory()->create();
        $contato = Contato::factory()->create(['telefone' => '5521988887777', 'nome' => $nomeContato]);

        return TicketAtendimento::create([
            'tenant_id' => $tenant->id, 'contato_id' => $contato->id,
            'coluna_kanban' => 'lead_novo', 'agente_responsavel' => 'bot',
            'status' => 'aberto', 'aberto_em' => now(),
        ]);
    }

    public function test_mensagem_de_lead_com_nome_invalido_despacha_o_job(): void
    {
        Queue::fake();
        $ticket = $this->criarTicket('5521988887777'); // placeholder = telefone

        Mensagem::create([
            'ticket_id' => $ticket->id, 'tenant_id' => $ticket->tenant_id,
            'remetente' => 'lead', 'tipo' => 'texto', 'conteudo' => 'Oi, meu nome é João',
            'enviado_em' => now(),
        ]);

        Queue::assertPushed(IdentificarNomeConversaJob::class);
    }

    public function test_mensagem_de_lead_com_nome_ja_valido_nao_despacha_o_job(): void
    {
        Queue::fake();
        $ticket = $this->criarTicket('Ana Já Validada');

        Mensagem::create([
            'ticket_id' => $ticket->id, 'tenant_id' => $ticket->tenant_id,
            'remetente' => 'lead', 'tipo' => 'texto', 'conteudo' => 'Oi, tudo bem?',
            'enviado_em' => now(),
        ]);

        Queue::assertNotPushed(IdentificarNomeConversaJob::class);
    }

    public function test_mensagem_de_bot_nao_despacha_o_job(): void
    {
        Queue::fake();
        $ticket = $this->criarTicket('5521988887777');

        Mensagem::create([
            'ticket_id' => $ticket->id, 'tenant_id' => $ticket->tenant_id,
            'remetente' => 'bot', 'tipo' => 'texto', 'conteudo' => 'Oi!',
            'enviado_em' => now(),
        ]);

        Queue::assertNotPushed(IdentificarNomeConversaJob::class);
    }

    public function test_mensagem_de_humano_nao_despacha_este_job(): void
    {
        Queue::fake();
        $ticket = $this->criarTicket('5521988887777');

        Mensagem::create([
            'ticket_id' => $ticket->id, 'tenant_id' => $ticket->tenant_id,
            'remetente' => 'humano', 'tipo' => 'texto', 'conteudo' => 'Oi!',
            'enviado_em' => now(),
        ]);

        Queue::assertNotPushed(IdentificarNomeConversaJob::class);
    }

    public function test_nome_sem_nome_tambem_despacha_o_job(): void
    {
        Queue::fake();
        $ticket = $this->criarTicket('Sem Nome');

        Mensagem::create([
            'ticket_id' => $ticket->id, 'tenant_id' => $ticket->tenant_id,
            'remetente' => 'lead', 'tipo' => 'texto', 'conteudo' => 'Meu nome é Roberto',
            'enviado_em' => now(),
        ]);

        Queue::assertPushed(IdentificarNomeConversaJob::class);
    }

    /**
     * Paridade entre canais (regra fundamental do CLAUDE.md): confirma que o
     * hook único cobre de fato os dois pontos reais de entrada de mensagem
     * do lead, não só documenta a intenção. Testa disparando o webhook real
     * de cada canal, não chamando Mensagem::create() direto.
     *
     * Frase escolhida de propósito pra NÃO bater em nenhum padrão de
     * NomeExtracaoService (sem "meu nome é"/"sou"/"aqui é"/começando com
     * "oi/olá") — prova que a camada de IA (este job) é o complemento real
     * do regex síncrono, não uma duplicata redundante dele.
     */
    public function test_mensagem_de_lead_via_uazapi_webhook_despacha_o_job(): void
    {
        Queue::fake();
        Http::fake(['*' => Http::response(['ok' => true], 200)]);

        $tenant = Tenant::factory()->create([
            'uazapi_webhook_token' => 'wh-nome-uazapi', 'uazapi_instance_token' => 'inst-nome-uazapi',
        ]);
        WhatsappCanal::factory()->create([
            'tenant_id' => $tenant->id, 'webhook_token' => 'wh-nome-uazapi',
            'config' => ['instance_token' => 'inst-nome-uazapi'],
        ]);

        $this->postJson('/api/webhook/uazapi/wh-nome-uazapi', [
            'EventType' => 'messages',
            'message'   => [
                'fromMe'  => false,
                'isGroup' => false,
                'chatid'  => '5521911112222@s.whatsapp.net',
                'text'    => 'Preciso de um orçamento urgente. Assinado, Patrícia Nunes.',
            ],
        ]);

        $ticket = TicketAtendimento::whereHas('contato', fn ($q) => $q->where('telefone', '5521911112222'))->first();
        $this->assertNotNull($ticket);
        Queue::assertPushed(IdentificarNomeConversaJob::class);
    }

    /**
     * Frase escolhida de propósito pra NÃO bater em nenhum padrão de
     * NomeExtracaoService — ver comentário do teste equivalente do Uazapi
     * acima.
     */
    public function test_mensagem_de_lead_via_covercut_webhook_despacha_o_job(): void
    {
        Queue::fake();
        Http::fake(['*' => Http::response(['ok' => true], 200)]);

        $tenant = Tenant::factory()->create();
        $canal  = WhatsappCanal::factory()->create([
            'tenant_id' => $tenant->id, 'tipo' => 'oficial', 'provider' => 'covercut',
            'config' => ['phone_number_id' => '111222', 'webhook_secret' => 'segredo-nome'],
        ]);

        $payload = [
            'event' => 'message', 'direction' => 'inbound', 'from_number_id' => '111222',
            'contact' => ['wa_id' => '5521933334444'],
            'message' => ['id' => 'wamid.nome1', 'type' => 'text', 'text' => 'Preciso de mudança urgente. Assinado, Ricardo Alves.'],
        ];
        $body       = json_encode($payload);
        $assinatura = hash_hmac('sha256', $body, 'segredo-nome');

        $this->call('POST', '/api/webhook/covercut', [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_X-BSP-Signature' => $assinatura,
        ], $body);

        $ticket = TicketAtendimento::whereHas('contato', fn ($q) => $q->where('telefone', '5521933334444'))->first();
        $this->assertNotNull($ticket);
        Queue::assertPushed(IdentificarNomeConversaJob::class);
    }
}
