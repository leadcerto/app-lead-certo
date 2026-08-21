<?php

namespace Tests\Feature;

use App\Models\Contato;
use App\Models\Mensagem;
use App\Models\Tenant;
use App\Models\TicketAtendimento;
use App\Models\WhatsappCanal;
use App\Services\TraducaoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Item 11 do roteiro de 2026-08-20 — detecta o idioma do lead na primeira
 * mensagem de texto substancial e traduz pro português pro atendente ler.
 */
class UazapiWebhookDeteccaoIdiomaTest extends TestCase
{
    use RefreshDatabase;

    private function criarTenantComCanal(string $webhookToken, string $instanceToken): Tenant
    {
        // locale != 'pt-BR' de propósito: todo telefone usado neste arquivo
        // tem DDI 55 (Brasil). Desde a Task 4 (Camada 1 — DDI na criação do
        // ticket), um DDI que bate com o locale do tenant já preenche
        // idioma_lead/idioma_origem no create() do ticket, antes deste
        // bloco (Camada 3 — detecção por IA a partir da mensagem) rodar.
        // Um locale divergente do DDI mantém idioma_lead null na criação,
        // isolando o que este arquivo testa (Camada 3 sozinha) da Camada 1
        // — a interação entre as duas é responsabilidade da Task 5 (alvo
        // dinâmico + regra anti-oscilação), com seus próprios testes.
        $tenant = Tenant::factory()->create(['locale' => 'es-ES']);
        WhatsappCanal::factory()->create([
            'tenant_id'     => $tenant->id,
            'webhook_token' => $webhookToken,
            'config'        => ['instance_token' => $instanceToken],
        ]);
        return $tenant;
    }

    public function test_detecta_idioma_e_traduz_primeira_mensagem_do_lead(): void
    {
        Http::fake(['*' => Http::response(['ok' => true], 200)]);
        $this->mock(TraducaoService::class, function ($mock) {
            $mock->shouldReceive('detectarIdioma')->once()
                ->with('Do you deliver to São Paulo?')
                ->andReturn('en');
            $mock->shouldReceive('traduzir')->once()
                ->with('Do you deliver to São Paulo?', 'pt', 'en')
                ->andReturn('Vocês entregam em São Paulo?');
        });

        $tenant = $this->criarTenantComCanal('wh-idioma-1', 'inst-idioma-1');

        $this->postJson('/api/webhook/uazapi/wh-idioma-1', [
            'EventType' => 'messages',
            'message'   => [
                'fromMe' => false, 'isGroup' => false,
                'chatid' => '5511900001234@s.whatsapp.net',
                'messageid' => 'msg-idioma-1',
                'text' => 'Do you deliver to São Paulo?',
            ],
        ]);

        $contato = Contato::where('telefone', '5511900001234')->first();
        $ticket  = TicketAtendimento::where('contato_id', $contato->id)->first();
        $this->assertSame('en', $ticket->idioma_lead);

        $mensagem = Mensagem::where('ticket_id', $ticket->id)->where('remetente', 'lead')->first();
        $this->assertSame('en', $mensagem->idioma);
        $this->assertSame('Vocês entregam em São Paulo?', $mensagem->conteudo_pt);
    }

    public function test_nao_detecta_de_novo_quando_idioma_ja_esta_confirmado(): void
    {
        Http::fake(['*' => Http::response(['ok' => true], 200)]);
        $this->mock(TraducaoService::class, fn ($mock) => $mock->shouldNotReceive('detectarIdioma'));

        $tenant  = $this->criarTenantComCanal('wh-idioma-2', 'inst-idioma-2');
        $contato = Contato::factory()->create(['telefone' => '5511900005678']);
        TicketAtendimento::create([
            'tenant_id' => $tenant->id, 'contato_id' => $contato->id,
            'coluna_kanban' => 'em_atendimento', 'agente_responsavel' => 'bot',
            'status' => 'aberto', 'aberto_em' => now(), 'idioma_lead' => 'en',
        ]);

        $this->postJson('/api/webhook/uazapi/wh-idioma-2', [
            'EventType' => 'messages',
            'message'   => [
                'fromMe' => false, 'isGroup' => false,
                'chatid' => '5511900005678@s.whatsapp.net',
                'messageid' => 'msg-idioma-2',
                'text' => 'Thanks, see you tomorrow.',
            ],
        ]);

        $this->assertTrue(true); // a asserção real é o mock não ter sido chamado
    }

    public function test_nao_detecta_idioma_em_lead_que_escreve_portugues(): void
    {
        Http::fake(['*' => Http::response(['ok' => true], 200)]);
        $this->mock(TraducaoService::class, function ($mock) {
            $mock->shouldReceive('detectarIdioma')->once()->andReturn('pt');
            $mock->shouldNotReceive('traduzir');
        });

        $tenant = $this->criarTenantComCanal('wh-idioma-3', 'inst-idioma-3');

        $this->postJson('/api/webhook/uazapi/wh-idioma-3', [
            'EventType' => 'messages',
            'message'   => [
                'fromMe' => false, 'isGroup' => false,
                'chatid' => '5511900009999@s.whatsapp.net',
                'messageid' => 'msg-idioma-3',
                'text' => 'Olá, gostaria de um orçamento.',
            ],
        ]);

        $contato = Contato::where('telefone', '5511900009999')->first();
        $ticket  = TicketAtendimento::where('contato_id', $contato->id)->first();
        $this->assertSame('pt', $ticket->idioma_lead);

        $mensagem = Mensagem::where('ticket_id', $ticket->id)->where('remetente', 'lead')->first();
        $this->assertNull($mensagem->conteudo_pt);
    }
}
