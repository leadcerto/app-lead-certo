<?php

namespace Tests\Feature;

use App\Models\Contato;
use App\Models\KanbanColunaObjetivo;
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
 * Achado real 2026-08-20 (Leonardo, ticket do "Nargidei"/Sepetiba): a IA mandou a
 * frase fixa de encerramento ("Já peguei toda a visão... vou passar essa ficha
 * pro nosso setor de orçamento") com o checklist claramente incompleto — o lead
 * tinha mandado só 1 mensagem, nenhum endereço. Diferente do guardrail de área
 * (regex fixo), este confere contra o estado real (`objetivos_cumpridos` x
 * `KanbanColunaObjetivo` ativos da coluna) — não confia só na palavra do modelo.
 */
class SdrResponderServiceHandoffPrematuroTest extends TestCase
{
    use RefreshDatabase;

    private function criarTicket(string $coluna = 'lead_novo'): TicketAtendimento
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
            'coluna_kanban' => $coluna, 'agente_responsavel' => 'bot', 'etapa_ia' => 'etapa_1',
            'status' => 'aberto', 'aberto_em' => now(), 'sdr_persona_id' => $persona->id,
        ]);
    }

    private function criarObjetivo(TicketAtendimento $ticket, string $texto, int $ordem): KanbanColunaObjetivo
    {
        return KanbanColunaObjetivo::create([
            'tenant_id' => $ticket->tenant_id, 'coluna_kanban' => $ticket->coluna_kanban,
            'texto' => $texto, 'ordem' => $ordem, 'ativo' => true,
        ]);
    }

    private const FRASE_HANDOFF = 'Perfeito! Já peguei toda a visão do que você vai precisar. Vou passar essa ficha agora pro nosso setor de orçamento e eles te mandam o valor fechado rapidinho, tá bom? Um minutinho 🚚';

    public function test_bloqueia_handoff_com_checklist_incompleto(): void
    {
        Http::fake(['*' => Http::response(['ok' => true], 200)]);
        $ticket = $this->criarTicket();
        $this->criarObjetivo($ticket, 'Endereço de embarque completo', 1);
        $this->criarObjetivo($ticket, 'Lista de itens coletada', 2);
        // nenhum objetivo marcado como cumprido ainda

        $this->mock(OpenRouterService::class, function ($mock) {
            $mock->shouldReceive('chat')->once()->andReturn(self::FRASE_HANDOFF);
        });

        $resposta = app(SdrResponderService::class)->responder($ticket);

        $this->assertNull($resposta);
        Http::assertNothingSent();
        $this->assertDatabaseMissing('mensagens', ['ticket_id' => $ticket->id, 'remetente' => 'bot']);
        $this->assertNotNull($ticket->fresh()->aguardando_orientacao_em);
        $this->assertDatabaseHas('alertas_internos', [
            'ticket_id' => $ticket->id, 'tenant_id' => $ticket->tenant_id, 'tipo' => 'handoff_prematuro',
        ]);
    }

    public function test_permite_handoff_com_checklist_realmente_completo(): void
    {
        Http::fake(['*' => Http::response(['ok' => true], 200)]);
        $ticket    = $this->criarTicket();
        $objetivo1 = $this->criarObjetivo($ticket, 'Endereço de embarque completo', 1);
        $objetivo2 = $this->criarObjetivo($ticket, 'Lista de itens coletada', 2);
        $ticket->update(['objetivos_cumpridos' => [$objetivo1->id, $objetivo2->id]]);

        $this->mock(OpenRouterService::class, function ($mock) {
            $mock->shouldReceive('chat')->once()->andReturn(self::FRASE_HANDOFF);
        });

        $resposta = app(SdrResponderService::class)->responder($ticket);

        $this->assertNotNull($resposta);
        $this->assertNull($ticket->fresh()->aguardando_orientacao_em);
    }

    public function test_permite_handoff_quando_coluna_nao_tem_objetivos_configurados(): void
    {
        // Objetivos são opt-in — mesmo critério já usado em
        // objetivosCumpridosAoEncerrar() (TicketAtendimento): coluna sem
        // nenhum objetivo rastreado não dá pra julgar incompleta.
        Http::fake(['*' => Http::response(['ok' => true], 200)]);
        $ticket = $this->criarTicket('coluna_sem_checklist');

        $this->mock(OpenRouterService::class, function ($mock) {
            $mock->shouldReceive('chat')->once()->andReturn(self::FRASE_HANDOFF);
        });

        $resposta = app(SdrResponderService::class)->responder($ticket);

        $this->assertNotNull($resposta);
        $this->assertNull($ticket->fresh()->aguardando_orientacao_em);
    }

    public function test_nao_bloqueia_resposta_normal_sem_frase_de_handoff(): void
    {
        Http::fake(['*' => Http::response(['ok' => true], 200)]);
        $ticket = $this->criarTicket();
        $this->criarObjetivo($ticket, 'Endereço de embarque completo', 1);

        $this->mock(OpenRouterService::class, function ($mock) {
            $mock->shouldReceive('chat')->once()->andReturn('Show, e qual o endereço de destino?');
        });

        $resposta = app(SdrResponderService::class)->responder($ticket);

        $this->assertNotNull($resposta);
        $this->assertNull($ticket->fresh()->aguardando_orientacao_em);
    }
}
