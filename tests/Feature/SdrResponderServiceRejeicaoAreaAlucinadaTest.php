<?php

namespace Tests\Feature;

use App\Models\Contato;
use App\Models\SdrPersona;
use App\Models\Tenant;
use App\Models\TicketAtendimento;
use App\Models\WhatsappCanal;
use App\Services\OpenRouterService;
use App\Services\SdrResponderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Achado ao vivo 2026-08-19/20 (Leonardo): a IA mandou "Poxa, [infelizmente] a gente
 * atende só aqui no Rio e região" 5 vezes em respostas a perguntas que não tinham
 * nada a ver com área de atendimento (ex.: "vcs são de onde?", "posso visitar o
 * estabelecimento?") — em endereços que estavam DENTRO da área real (Belford Roxo,
 * Barra da Tijuca). O `ia_contexto` do tenant diz o oposto ("atende todo o Estado
 * do RJ e faz viagens para SP, MG e ES") — é alucinação pura do modelo, não
 * instrução seguida. A instrução de autovalidação (Regra 7, "[DUVIDA:...]") já
 * existe e pede pra nunca inventar informação, mas o modelo não segue de forma
 * confiável — por isso a trava entra no código, mesmo tratamento do [DUVIDA:]:
 * pausa o ticket, alerta o humano, nunca manda pro lead.
 */
class SdrResponderServiceRejeicaoAreaAlucinadaTest extends TestCase
{
    use RefreshDatabase;

    private function criarTicketComCanal(): TicketAtendimento
    {
        $tenant  = Tenant::factory()->create();
        $canal   = WhatsappCanal::factory()->create(['tenant_id' => $tenant->id, 'config' => ['instance_token' => 'tok']]);
        $persona = SdrPersona::create([
            'tenant_id' => $tenant->id, 'nome_interno' => 'padrao', 'nome_display' => 'Joao',
            'system_prompt' => 'Você é um atendente.', 'ativo' => true, 'is_default' => true, 'tier' => 'simples',
        ]);
        $contato = Contato::factory()->create(['telefone' => '5511988887777']);

        return TicketAtendimento::create([
            'tenant_id' => $tenant->id, 'contato_id' => $contato->id, 'whatsapp_canal_id' => $canal->id,
            'coluna_kanban' => 'em_atendimento', 'agente_responsavel' => 'bot', 'etapa_ia' => 'etapa_1',
            'status' => 'aberto', 'aberto_em' => now(), 'sdr_persona_id' => $persona->id,
        ]);
    }

    private function assertFraseBloqueiaEPausaOTicket(string $fraseReal): void
    {
        Http::fake(['*' => Http::response(['ok' => true], 200)]);
        $ticket = $this->criarTicketComCanal();

        $this->mock(OpenRouterService::class, function ($mock) use ($fraseReal) {
            $mock->shouldReceive('chat')->once()->andReturn($fraseReal);
        });

        $resposta = app(SdrResponderService::class)->responder($ticket);

        $this->assertNull($resposta);
        Http::assertNothingSent();
        $this->assertDatabaseMissing('mensagens', ['ticket_id' => $ticket->id, 'remetente' => 'bot']);
        $this->assertNotNull($ticket->fresh()->aguardando_orientacao_em);

        $this->assertDatabaseHas('alertas_internos', [
            'ticket_id' => $ticket->id, 'tenant_id' => $ticket->tenant_id, 'tipo' => 'rejeicao_area_alucinada',
        ]);
    }

    // As variações reais capturadas em produção (tickets 3131, 3162, 3178, 4207, 4208).

    public function test_bloqueia_variacao_com_infelizmente(): void
    {
        $this->assertFraseBloqueiaEPausaOTicket(
            'Poxa, infelizmente a gente atende só aqui no Rio e região. Mas se precisar de um frete aqui, pode chamar a qualquer hora!'
        );
    }

    public function test_bloqueia_variacao_sem_infelizmente(): void
    {
        $this->assertFraseBloqueiaEPausaOTicket(
            'Poxa, a gente atende só aqui no Rio e região. Mas se precisar de um frete aqui, pode chamar a qualquer hora! 😊'
        );
    }

    public function test_nao_bloqueia_resposta_normal_sobre_area_de_atendimento(): void
    {
        // A resposta CORRETA (confirmando que atende, conforme o ia_contexto real)
        // não pode ser pega pelo filtro — só a negação falsa é bloqueada.
        Http::fake(['*' => Http::response(['ok' => true], 200)]);
        $ticket = $this->criarTicketComCanal();

        $this->mock(OpenRouterService::class, function ($mock) {
            $mock->shouldReceive('chat')->once()
                ->andReturn('Sim! Atendemos todo o Rio, incluindo Belford Roxo. Pode mandar o endereço completo?');
        });

        $resposta = app(SdrResponderService::class)->responder($ticket);

        $this->assertNotNull($resposta);
        $this->assertNull($ticket->fresh()->aguardando_orientacao_em);
    }
}
