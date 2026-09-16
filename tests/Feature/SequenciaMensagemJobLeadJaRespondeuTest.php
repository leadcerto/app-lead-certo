<?php

namespace Tests\Feature;

use App\Jobs\SequenciaMensagemJob;
use App\Models\Contato;
use App\Models\Mensagem;
use App\Models\Tenant;
use App\Models\TicketAtendimento;
use App\Models\WhatsappCanal;
use App\Services\HumanizacaoService;
use App\Services\UazapiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Achado real em 2026-09-16, ticket #4788 (Rodrigo/"Daniela"): o passo 2 da
 * sequência "Boas-vindas" ("... fico esperando as informações para poder
 * fazer o seu orçamento") disparou 1min42s DEPOIS do lead já ter respondido o
 * nome — mensagem redundante, com nome errado ({nome} ainda vazio porque a
 * IA não tinha processado a resposta), e o Leonardo teve que assumir na mão.
 * SequenciaMensagemJob já respeitava "humano assumiu" e "saiu da coluna", mas
 * nunca checava se o PRÓPRIO LEAD já tinha respondido desde a última
 * mensagem nossa — ele segue o roteiro fixo de qualquer jeito.
 */
class SequenciaMensagemJobLeadJaRespondeuTest extends TestCase
{
    use RefreshDatabase;

    private function criarTicket(): TicketAtendimento
    {
        $tenant  = Tenant::factory()->create(['uazapi_instance_token' => 'tok']);
        $canal   = WhatsappCanal::factory()->create([
            'tenant_id' => $tenant->id,
            'config'    => ['instance_token' => 'tok'],
        ]);
        // Nome real explícito (não o fake() aleatório da factory) — evita que
        // Contato::semNomeReal() dispare IdentificarNomeConversaJob de forma
        // não-determinística ao criar a mensagem "lead" abaixo, o que soma uma
        // chamada HTTP extra e deixa Http::assertNothingSent() flaky.
        $contato = Contato::factory()->create(['telefone' => '5511999999999', 'nome' => 'Rodrigo Dalmeida']);

        return TicketAtendimento::create([
            'tenant_id' => $tenant->id, 'contato_id' => $contato->id,
            'whatsapp_canal_id' => $canal->id,
            'coluna_kanban' => 'lead_novo', 'agente_responsavel' => 'bot',
            'status' => 'aberto', 'aberto_em' => now(),
        ]);
    }

    public function test_nao_envia_se_o_lead_ja_respondeu_depois_da_ultima_mensagem_do_bot(): void
    {
        Http::fake(['*' => Http::response(['ok' => true], 200)]);

        $ticket = $this->criarTicket();

        Mensagem::create([
            'ticket_id' => $ticket->id, 'tenant_id' => $ticket->tenant_id,
            'remetente' => 'bot', 'tipo' => 'texto', 'conteudo' => 'Qual o seu nome?',
            'enviado_em' => now()->subMinutes(2),
        ]);
        Mensagem::create([
            'ticket_id' => $ticket->id, 'tenant_id' => $ticket->tenant_id,
            'remetente' => 'lead', 'tipo' => 'texto', 'conteudo' => 'Rodrigo',
            'enviado_em' => now()->subMinute(),
        ]);

        (new SequenciaMensagemJob($ticket->id, 'Fico esperando as informações para o seu orçamento', null, 'lead_novo', null, false))
            ->handle(app(HumanizacaoService::class), app(UazapiService::class));

        Http::assertNothingSent();
        $this->assertDatabaseMissing('mensagens', ['ticket_id' => $ticket->id, 'conteudo' => 'Fico esperando as informações para o seu orçamento']);
    }

    public function test_envia_normalmente_quando_o_lead_nao_respondeu_desde_a_ultima_mensagem_do_bot(): void
    {
        Http::fake(['*' => Http::response(['ok' => true], 200)]);

        $ticket = $this->criarTicket();

        Mensagem::create([
            'ticket_id' => $ticket->id, 'tenant_id' => $ticket->tenant_id,
            'remetente' => 'bot', 'tipo' => 'texto', 'conteudo' => 'Qual o seu nome?',
            'enviado_em' => now()->subMinutes(2),
        ]);

        (new SequenciaMensagemJob($ticket->id, 'Fico esperando as informações para o seu orçamento', null, 'lead_novo', null, false))
            ->handle(app(HumanizacaoService::class), app(UazapiService::class));

        Http::assertSent(fn ($request) => true);
        $this->assertDatabaseHas('mensagens', ['ticket_id' => $ticket->id, 'conteudo' => 'Fico esperando as informações para o seu orçamento']);
    }

    public function test_primeira_mensagem_da_sequencia_envia_mesmo_sem_nenhuma_mensagem_do_bot_ainda(): void
    {
        Http::fake(['*' => Http::response(['ok' => true], 200)]);

        $ticket = $this->criarTicket();

        Mensagem::create([
            'ticket_id' => $ticket->id, 'tenant_id' => $ticket->tenant_id,
            'remetente' => 'lead', 'tipo' => 'texto', 'conteudo' => 'Boa noite, tudo bem?',
            'enviado_em' => now()->subSeconds(5),
        ]);

        (new SequenciaMensagemJob($ticket->id, 'Olá! Qual o seu nome?', null, 'lead_novo', null, false))
            ->handle(app(HumanizacaoService::class), app(UazapiService::class));

        Http::assertSent(fn ($request) => true);
        $this->assertDatabaseHas('mensagens', ['ticket_id' => $ticket->id, 'conteudo' => 'Olá! Qual o seu nome?']);
    }

    public function test_mensagem_obrigatoria_envia_mesmo_com_lead_ja_tendo_respondido(): void
    {
        Http::fake(['*' => Http::response(['ok' => true], 200)]);

        $ticket = $this->criarTicket();

        Mensagem::create([
            'ticket_id' => $ticket->id, 'tenant_id' => $ticket->tenant_id,
            'remetente' => 'bot', 'tipo' => 'texto', 'conteudo' => 'Qual o seu nome?',
            'enviado_em' => now()->subMinutes(2),
        ]);
        Mensagem::create([
            'ticket_id' => $ticket->id, 'tenant_id' => $ticket->tenant_id,
            'remetente' => 'lead', 'tipo' => 'texto', 'conteudo' => 'Rodrigo',
            'enviado_em' => now()->subMinute(),
        ]);

        (new SequenciaMensagemJob($ticket->id, 'Mensagem obrigatória', null, 'lead_novo', null, true))
            ->handle(app(HumanizacaoService::class), app(UazapiService::class));

        Http::assertSent(fn ($request) => true);
        $this->assertDatabaseHas('mensagens', ['ticket_id' => $ticket->id, 'conteudo' => 'Mensagem obrigatória']);
    }
}
