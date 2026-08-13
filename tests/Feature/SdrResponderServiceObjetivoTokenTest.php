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
        // Segundo objetivo não mencionado no token — mantém a checklist da
        // coluna incompleta, senão o avanço automático (AvancoAutomaticoKanbanService,
        // já coberto por testes próprios) dispararia e zeraria objetivos_cumpridos
        // como efeito colateral, o que não é o que este teste quer verificar.
        KanbanColunaObjetivo::create([
            'tenant_id' => $ticket->tenant_id, 'coluna_kanban' => 'em_atendimento',
            'texto' => 'Lista de itens', 'ordem' => 2, 'ativo' => true,
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
        // Terceiro objetivo não mencionado em nenhum token — mantém a checklist
        // da coluna incompleta, senão marcar obj1+obj2 fecharia 100% dela e o
        // avanço automático (já coberto por testes próprios) dispararia,
        // zerando objetivos_cumpridos como efeito colateral — não é o que este
        // teste quer verificar (aqui o foco é o parsing de múltiplos tokens).
        KanbanColunaObjetivo::create([
            'tenant_id' => $ticket->tenant_id, 'coluna_kanban' => 'em_atendimento',
            'texto' => 'Confirmação de pagamento', 'ordem' => 3, 'ativo' => true,
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

    public function test_token_de_objetivo_desativado_e_ignorado_sem_quebrar(): void
    {
        // Achado 4 da revisão final: o prompt (montarBlocoObjetivos) só mostra
        // objetivos ativos, então a validação do token tem que concordar — um id
        // de objetivo desativado não pode ser aceito mesmo que o modelo alucine
        // ou reutilize um token de uma resposta anterior à desativação.
        Http::fake(['*' => Http::response(['ok' => true], 200)]);
        $ticket = $this->criarTicketComCanal();

        $objetivo = KanbanColunaObjetivo::create([
            'tenant_id' => $ticket->tenant_id, 'coluna_kanban' => 'em_atendimento',
            'texto' => 'Objetivo desativado', 'ordem' => 1, 'ativo' => false,
        ]);

        $this->mock(OpenRouterService::class, function ($mock) use ($objetivo) {
            $mock->shouldReceive('chat')->once()
                ->andReturn("Perfeito!\n[OBJETIVO_CUMPRIDO:{$objetivo->id}]");
        });

        $resposta = app(SdrResponderService::class)->responder($ticket);

        $this->assertSame('Perfeito!', $resposta);
        $this->assertSame([], $ticket->fresh()->objetivos_cumpridos ?? []);
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

    public function test_marcar_ultimo_objetivo_via_token_avanca_a_coluna(): void
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
        $ticket->update(['objetivos_cumpridos' => [$obj1->id]]);

        $this->mock(OpenRouterService::class, function ($mock) use ($obj2) {
            $mock->shouldReceive('chat')->once()
                ->andReturn("Perfeito, anotado!\n[OBJETIVO_CUMPRIDO:{$obj2->id}]");
        });

        app(SdrResponderService::class)->responder($ticket);

        $fresco = $ticket->fresh();
        $this->assertSame('aguardando_orcamento', $fresco->coluna_kanban);
        $this->assertSame([], $fresco->objetivos_cumpridos ?? []);
    }

    /**
     * Se a mesma resposta já incluir um token explícito de movimento de
     * coluna ([NOME_DA_COLUNA], seção "4" — roda antes da seção "4.5"), o
     * avanço automático por checklist não deve ser aplicado por cima —
     * o ticket já mudou de coluna por decisão explícita, e os ids do token
     * de objetivo se referem à coluna de ONDE ele veio, não a de destino.
     */
    public function test_token_de_movimento_explicito_impede_avanco_automatico_por_checklist(): void
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
        $ticket->update(['objetivos_cumpridos' => [$obj1->id]]);

        // A IA decide mover explicitamente pra 'pagamento' (pulando
        // aguardando_orcamento/aguardando_lead) E, na mesma resposta,
        // reporta o último objetivo de em_atendimento como cumprido.
        $this->mock(OpenRouterService::class, function ($mock) use ($obj2) {
            $mock->shouldReceive('chat')->once()
                ->andReturn("Combinado!\n[PAGAMENTO]\n[OBJETIVO_CUMPRIDO:{$obj2->id}]");
        });

        app(SdrResponderService::class)->responder($ticket);

        // Foi pra onde a IA mandou explicitamente, não pra próxima coluna
        // da ordem natural (aguardando_orcamento).
        $this->assertSame('pagamento', $ticket->fresh()->coluna_kanban);
    }
}
