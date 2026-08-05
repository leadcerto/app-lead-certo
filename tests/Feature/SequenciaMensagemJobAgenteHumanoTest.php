<?php

namespace Tests\Feature;

use App\Jobs\SequenciaMensagemJob;
use App\Models\Contato;
use App\Models\Tenant;
use App\Models\TicketAtendimento;
use App\Models\WhatsappCanal;
use App\Services\HumanizacaoService;
use App\Services\UazapiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Achado em 2026-08-05, investigando relato real: "desativei o Agente de IA
 * mas ele continua entrando na conversa". Causa raiz confirmada com dados de
 * produção — SequenciaMensagemJob nunca checava se um humano já tinha
 * assumido o ticket antes de disparar uma mensagem agendada. Como as
 * mensagens de uma sequência são todas enfileiradas de uma vez (com delay)
 * quando o lead entra na coluna, se o atendente assume a conversa no meio do
 * caminho, as mensagens que já estavam na fila continuavam disparando —
 * mesmo padrão de bug já corrigido em FollowupConversas em 30/07 (ver
 * comentário em FollowupConversas.php), nunca replicado aqui.
 */
class SequenciaMensagemJobAgenteHumanoTest extends TestCase
{
    use RefreshDatabase;

    private function criarTicket(string $agenteResponsavel): TicketAtendimento
    {
        $tenant  = Tenant::factory()->create(['uazapi_instance_token' => 'tok']);
        $canal   = WhatsappCanal::factory()->create([
            'tenant_id' => $tenant->id,
            'config'    => ['instance_token' => 'tok'],
        ]);
        $contato = Contato::factory()->create(['telefone' => '5511999999999']);

        return TicketAtendimento::create([
            'tenant_id' => $tenant->id, 'contato_id' => $contato->id,
            'whatsapp_canal_id' => $canal->id,
            'coluna_kanban' => 'lead_novo', 'agente_responsavel' => $agenteResponsavel,
            'status' => 'aberto', 'aberto_em' => now(),
        ]);
    }

    public function test_mensagem_de_sequencia_nao_e_enviada_se_humano_ja_assumiu_o_ticket(): void
    {
        Http::fake(['*' => Http::response(['ok' => true], 200)]);

        $ticket = $this->criarTicket('humano');

        (new SequenciaMensagemJob($ticket->id, 'Aguardo as informações para fazer seu orçamento', null, 'lead_novo', null, false))
            ->handle(app(HumanizacaoService::class), app(UazapiService::class));

        Http::assertNothingSent();
        $this->assertDatabaseMissing('mensagens', ['ticket_id' => $ticket->id]);
    }

    /**
     * "Envio obrigatório" existe pra ignorar o cancelamento por MUDANÇA DE
     * COLUNA (ver SequenciaMensagemJobObrigatorioTest) — não deve virar uma
     * forma de a automação atropelar um humano que já assumiu a conversa.
     */
    public function test_mensagem_obrigatoria_tambem_nao_e_enviada_se_humano_ja_assumiu(): void
    {
        Http::fake(['*' => Http::response(['ok' => true], 200)]);

        $ticket = $this->criarTicket('humano');

        (new SequenciaMensagemJob($ticket->id, 'Mensagem obrigatória', null, 'lead_novo', null, true))
            ->handle(app(HumanizacaoService::class), app(UazapiService::class));

        Http::assertNothingSent();
        $this->assertDatabaseMissing('mensagens', ['ticket_id' => $ticket->id]);
    }

    public function test_mensagem_de_sequencia_ainda_e_enviada_quando_bot_continua_responsavel(): void
    {
        Http::fake(['*' => Http::response(['ok' => true], 200)]);

        $ticket = $this->criarTicket('bot');

        (new SequenciaMensagemJob($ticket->id, 'Aguardo as informações para fazer seu orçamento', null, 'lead_novo', null, false))
            ->handle(app(HumanizacaoService::class), app(UazapiService::class));

        Http::assertSent(fn ($request) => true);
        $this->assertDatabaseHas('mensagens', ['ticket_id' => $ticket->id, 'conteudo' => 'Aguardo as informações para fazer seu orçamento']);
    }
}
