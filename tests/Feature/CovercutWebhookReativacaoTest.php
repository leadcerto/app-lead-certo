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
 * Achado real (2026-08-12, caso do Eduardo Almada): o CovercutWebhookController
 * não tinha o equivalente da reabertura de ticket encerrado que já existia no
 * UazapiWebhookController — um lead que voltava a falar pelo canal Oficial após
 * o atendimento encerrado sempre ganhava um ticket novo, nunca reabria o
 * anterior. Corrigido extraindo a lógica pra TicketReaberturaService, reusada
 * pelos dois webhooks (regra fundamental de paridade entre canais, CLAUDE.md).
 * Espelha UazapiWebhookReativacaoTest.
 */
class CovercutWebhookReativacaoTest extends TestCase
{
    use RefreshDatabase;

    private function criarCanal(string $phoneNumberId, string $webhookSecret): array
    {
        $tenant = Tenant::factory()->create();
        $canal  = WhatsappCanal::factory()->create([
            'tenant_id' => $tenant->id, 'tipo' => 'oficial', 'provider' => 'covercut',
            'config'    => ['phone_number_id' => $phoneNumberId, 'webhook_secret' => $webhookSecret],
        ]);

        return [$tenant, $canal];
    }

    private function postComAssinatura(array $payload, string $segredo)
    {
        $body       = json_encode($payload);
        $assinatura = hash_hmac('sha256', $body, $segredo);

        return $this->call('POST', '/api/webhook/covercut', [], [], [], [
            'CONTENT_TYPE'         => 'application/json',
            'HTTP_X-BSP-Signature' => $assinatura,
        ], $body);
    }

    private function fakeOpenRouterClassificacao(string $resposta): void
    {
        Http::fake([
            'openrouter.ai/*' => Http::response([
                'choices' => [['message' => ['content' => $resposta]]],
                'usage'   => ['prompt_tokens' => 10, 'completion_tokens' => 2],
            ], 200),
            '*' => Http::response(['ok' => true], 200),
        ]);
    }

    public function test_ticket_encerrado_reabre_na_coluna_de_antes_de_encerrar(): void
    {
        [$tenant, $canal] = $this->criarCanal('950147584848138', 'segredo-reativa-1');
        $contato = Contato::factory()->create(['telefone' => '5521911112222']);
        $ticket  = TicketAtendimento::create([
            'tenant_id' => $tenant->id, 'contato_id' => $contato->id,
            'whatsapp_canal_id' => $canal->id,
            'coluna_kanban' => 'aguardando_orcamento', 'agente_responsavel' => 'humano',
            'status' => 'aberto', 'aberto_em' => now(),
        ]);
        $ticket->update($ticket->dadosParaEncerrar(['tag_desfecho' => 'sem_resposta', 'encerrado_em' => now()]));

        $this->assertSame('aguardando_orcamento', $ticket->fresh()->coluna_antes_encerrar);

        $payload = [
            'event' => 'message', 'direction' => 'inbound', 'from_number_id' => '950147584848138',
            'contact' => ['wa_id' => '5521911112222', 'name' => 'Eduardo'],
            'message' => ['id' => 'wamid.reativa1', 'type' => 'text', 'text' => 'Oi, ainda quero fazer a mudança'],
        ];

        $response = $this->postComAssinatura($payload, 'segredo-reativa-1');

        $response->assertOk();
        $ticket->refresh();
        $this->assertSame('aguardando_orcamento', $ticket->coluna_kanban);
        $this->assertSame('aberto', $ticket->status);
        $this->assertNull($ticket->coluna_antes_encerrar);
        // Continua um único ticket por lead — não duplicou.
        $this->assertSame(1, TicketAtendimento::withoutGlobalScopes()->where('contato_id', $contato->id)->count());
    }

    public function test_ticket_encerrado_sem_coluna_salva_reabre_em_atendimento(): void
    {
        [$tenant, $canal] = $this->criarCanal('950147584848139', 'segredo-reativa-2');
        $contato = Contato::factory()->create(['telefone' => '5521933334444']);
        TicketAtendimento::create([
            'tenant_id' => $tenant->id, 'contato_id' => $contato->id,
            'whatsapp_canal_id' => $canal->id,
            'coluna_kanban' => 'encerrado', 'agente_responsavel' => 'bot',
            'status' => 'encerrado', 'aberto_em' => now(),
        ]);

        $payload = [
            'event' => 'message', 'direction' => 'inbound', 'from_number_id' => '950147584848139',
            'contact' => ['wa_id' => '5521933334444', 'name' => 'Fulano'],
            'message' => ['id' => 'wamid.reativa2', 'type' => 'text', 'text' => 'Oi, tudo bem?'],
        ];

        $this->postComAssinatura($payload, 'segredo-reativa-2')->assertOk();

        $ticket = TicketAtendimento::withoutGlobalScopes()->where('contato_id', $contato->id)->first();
        $this->assertSame('em_atendimento', $ticket->coluna_kanban);
        $this->assertSame('aberto', $ticket->status);
    }

    public function test_mensagem_de_despedida_mantem_ticket_encerrado(): void
    {
        $this->fakeOpenRouterClassificacao('MANTER');

        [$tenant, $canal] = $this->criarCanal('950147584848140', 'segredo-reativa-3');
        $contato = Contato::factory()->create(['telefone' => '5521955556666']);
        $ticket  = TicketAtendimento::create([
            'tenant_id' => $tenant->id, 'contato_id' => $contato->id,
            'whatsapp_canal_id' => $canal->id,
            'coluna_kanban' => 'aguardando_orcamento', 'agente_responsavel' => 'humano',
            'status' => 'aberto', 'aberto_em' => now(),
        ]);
        $ticket->update($ticket->dadosParaEncerrar(['tag_desfecho' => 'vendido', 'encerrado_em' => now()]));

        $payload = [
            'event' => 'message', 'direction' => 'inbound', 'from_number_id' => '950147584848140',
            'contact' => ['wa_id' => '5521955556666', 'name' => 'Ciclano'],
            'message' => ['id' => 'wamid.reativa3', 'type' => 'text', 'text' => 'Boa noite, já consegui, obrigado!'],
        ];

        $response = $this->postComAssinatura($payload, 'segredo-reativa-3');

        $response->assertOk();
        $ticket->refresh();
        $this->assertSame('encerrado', $ticket->coluna_kanban);
        $this->assertSame('encerrado', $ticket->status);
    }

    public function test_mensagem_util_reabre_ticket_encerrado(): void
    {
        $this->fakeOpenRouterClassificacao('REABRIR');

        [$tenant, $canal] = $this->criarCanal('950147584848141', 'segredo-reativa-4');
        $contato = Contato::factory()->create(['telefone' => '5521966667777']);
        $ticket  = TicketAtendimento::create([
            'tenant_id' => $tenant->id, 'contato_id' => $contato->id,
            'whatsapp_canal_id' => $canal->id,
            'coluna_kanban' => 'em_atendimento', 'agente_responsavel' => 'humano',
            'status' => 'aberto', 'aberto_em' => now(),
        ]);
        $ticket->update($ticket->dadosParaEncerrar(['tag_desfecho' => 'sem_resposta', 'encerrado_em' => now()]));

        $payload = [
            'event' => 'message', 'direction' => 'inbound', 'from_number_id' => '950147584848141',
            'contact' => ['wa_id' => '5521966667777', 'name' => 'Beltrano'],
            'message' => ['id' => 'wamid.reativa4', 'type' => 'text', 'text' => '1 geladeira, 1 sofá e 1 sapateira'],
        ];

        $this->postComAssinatura($payload, 'segredo-reativa-4')->assertOk();

        $ticket->refresh();
        $this->assertSame('em_atendimento', $ticket->coluna_kanban);
        $this->assertSame('aberto', $ticket->status);
        // Reabertura via canal Oficial também renova a janela de conversa.
        $this->assertNotNull($ticket->janela_expira_em);
        $this->assertTrue($ticket->janela_expira_em->isFuture());
    }
}
