<?php

namespace Tests\Feature;

use App\Models\Contato;
use App\Models\KanbanColunaObjetivo;
use App\Models\Mensagem;
use App\Models\SdrPersona;
use App\Models\Tenant;
use App\Models\TicketAtendimento;
use App\Models\WhatsappCanal;
use App\Services\OpenRouterService;
use App\Services\SdrResponderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SdrResponderServiceObjetivoTokenTest extends TestCase
{
    use RefreshDatabase;

    private function criarTicketComCanal(): TicketAtendimento
    {
        $tenant  = Tenant::factory()->create(['uazapi_instance_token' => 'tok']);
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

    public function test_token_de_objetivo_marca_progresso_e_e_removido_da_mensagem_final(): void
    {
        Http::fake(['*' => Http::response(['ok' => true], 200)]);
        $ticket = $this->criarTicketComCanal();

        $objetivo = KanbanColunaObjetivo::create([
            'tenant_id' => $ticket->tenant_id, 'coluna_kanban' => 'em_atendimento',
            'texto' => 'Endereço de origem confirmado', 'ordem' => 1, 'ativo' => true,
        ]);

        $this->mock(OpenRouterService::class, function ($mock) use ($objetivo) {
            $mock->shouldReceive('chat')->once()
                ->andReturn("Perfeito, endereço anotado!\n[OBJETIVO_CUMPRIDO:{$objetivo->id}]");
        });

        $resposta = app(SdrResponderService::class)->responder($ticket);

        $this->assertSame('Perfeito, endereço anotado!', $resposta);
        $this->assertSame([$objetivo->id], $ticket->fresh()->objetivos_cumpridos);
        $this->assertDatabaseHas('mensagens', ['ticket_id' => $ticket->id, 'conteudo' => 'Perfeito, endereço anotado!']);
    }

    public function test_multiplos_tokens_na_mesma_resposta_marcam_todos(): void
    {
        Http::fake(['*' => Http::response(['ok' => true], 200)]);
        $ticket = $this->criarTicketComCanal();

        $obj1 = KanbanColunaObjetivo::create([
            'tenant_id' => $ticket->tenant_id, 'coluna_kanban' => 'em_atendimento',
            'texto' => 'Endereço de origem', 'ordem' => 1, 'ativo' => true,
        ]);
        $obj2 = KanbanColunaObjetivo::create([
            'tenant_id' => $ticket->tenant_id, 'coluna_kanban' => 'em_atendimento',
            'texto' => 'Lista de itens', 'ordem' => 2, 'ativo' => true,
        ]);

        $this->mock(OpenRouterService::class, function ($mock) use ($obj1, $obj2) {
            $mock->shouldReceive('chat')->once()
                ->andReturn("Show, anotado tudo!\n[OBJETIVO_CUMPRIDO:{$obj1->id}]\n[OBJETIVO_CUMPRIDO:{$obj2->id}]");
        });

        app(SdrResponderService::class)->responder($ticket);

        $this->assertEqualsCanonicalizing([$obj1->id, $obj2->id], $ticket->fresh()->objetivos_cumpridos);
    }

    public function test_objetivos_cumpridos_e_zerado_ao_mudar_de_coluna(): void
    {
        Http::fake(['*' => Http::response(['ok' => true], 200)]);
        $ticket = $this->criarTicketComCanal();
        $ticket->update(['objetivos_cumpridos' => [999]]);

        $this->mock(OpenRouterService::class, function ($mock) {
            $mock->shouldReceive('chat')->once()->andReturn("Vamos seguir!\n[AGUARDANDO_ORCAMENTO]");
        });

        app(SdrResponderService::class)->responder($ticket);

        $ticketFresco = $ticket->fresh();
        $this->assertSame('aguardando_orcamento', $ticketFresco->coluna_kanban);
        $this->assertSame([], $ticketFresco->objetivos_cumpridos ?? []);
    }

    public function test_token_com_id_inexistente_e_ignorado_sem_quebrar(): void
    {
        Http::fake(['*' => Http::response(['ok' => true], 200)]);
        $ticket = $this->criarTicketComCanal();

        $this->mock(OpenRouterService::class, function ($mock) {
            $mock->shouldReceive('chat')->once()
                ->andReturn("Perfeito!\n[OBJETIVO_CUMPRIDO:999999]");
        });

        $resposta = app(SdrResponderService::class)->responder($ticket);

        $this->assertSame('Perfeito!', $resposta);
        $this->assertSame([], $ticket->fresh()->objetivos_cumpridos ?? []);
    }
}
