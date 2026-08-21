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
            // Task 5 (2026-08-21): alvo de tradução da entrada não é mais fixo
            // em 'pt' — é o idioma do atendente atribuído, ou (sem atendente,
            // como aqui) o locale do tenant ('es-ES' nesta suíte, de propósito
            // — ver comentário de criarTenantComCanal()).
            $mock->shouldReceive('resolverIdiomaAtendente')->once()->andReturn('es-ES');
            $mock->shouldReceive('traduzir')->once()
                ->with('Do you deliver to São Paulo?', 'es-ES', 'en')
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

    /**
     * Task 5 do roteiro de idioma multilíngue (2026-08-21) substituiu o gate
     * antigo (`is_null($ticket->idioma_lead)`, que impedia qualquer nova
     * detecção depois da primeira) pela regra anti-oscilação — a detecção
     * roda em toda mensagem elegível, mas só ATUALIZA idioma_lead quando faz
     * sentido. Aqui a mensagem detectada bate com o idioma já confirmado,
     * então não há nada a decidir (a regra anti-oscilação nem é consultada)
     * e idioma_lead/idioma_origem seguem intactos — mas a tradução da
     * mensagem em si roda normalmente (não é mais "só a primeira vez").
     */
    public function test_mantem_idioma_lead_quando_deteccao_confirma_o_idioma_atual(): void
    {
        Http::fake(['*' => Http::response(['ok' => true], 200)]);
        $this->mock(TraducaoService::class, function ($mock) {
            $mock->shouldReceive('detectarIdioma')->once()
                ->with('Thanks, see you tomorrow.')
                ->andReturn('en');
            $mock->shouldNotReceive('deveAtualizarIdiomaLead');
            $mock->shouldReceive('resolverIdiomaAtendente')->once()->andReturn('es-ES');
            $mock->shouldReceive('traduzir')->once()
                ->with('Thanks, see you tomorrow.', 'es-ES', 'en')
                ->andReturn('Gracias, hasta mañana.');
        });

        $tenant  = $this->criarTenantComCanal('wh-idioma-2', 'inst-idioma-2');
        $contato = Contato::factory()->create(['telefone' => '5511900005678']);
        $ticket  = TicketAtendimento::create([
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

        $this->assertSame('en', $ticket->fresh()->idioma_lead);

        $mensagem = Mensagem::where('ticket_id', $ticket->id)->where('remetente', 'lead')->first();
        $this->assertSame('en', $mensagem->idioma);
        $this->assertSame('Gracias, hasta mañana.', $mensagem->conteudo_pt);
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
