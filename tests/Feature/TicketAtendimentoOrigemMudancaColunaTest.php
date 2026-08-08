<?php
// tests/Feature/TicketAtendimentoOrigemMudancaColunaTest.php
namespace Tests\Feature;

use App\Models\Contato;
use App\Models\KanbanColunaHistorico;
use App\Models\Tenant;
use App\Models\TicketAtendimento;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TicketAtendimentoOrigemMudancaColunaTest extends TestCase
{
    use RefreshDatabase;

    private function criarTicket(Tenant $tenant, string $coluna = 'lead_novo'): TicketAtendimento
    {
        $contato = Contato::factory()->create();

        return TicketAtendimento::create([
            'tenant_id' => $tenant->id, 'contato_id' => $contato->id,
            'coluna_kanban' => $coluna, 'agente_responsavel' => 'bot',
            'status' => 'aberto', 'aberto_em' => now(),
        ]);
    }

    public function test_mudanca_de_coluna_sem_marcar_origem_grava_sistema_por_padrao(): void
    {
        $tenant = Tenant::factory()->create();
        $ticket = $this->criarTicket($tenant);

        // Bloco 5 — default mudou de 'ia' pra 'sistema': update direto, sem
        // passar por SdrResponderService, é política automática, não uma
        // decisão real da IA em tempo real.
        $ticket->update(['coluna_kanban' => 'em_atendimento']);

        $this->assertDatabaseHas('kanban_coluna_historico', [
            'ticket_id' => $ticket->id, 'coluna' => 'em_atendimento', 'origem' => 'sistema',
        ]);
    }

    public function test_mudanca_de_coluna_com_propriedade_marcada_grava_humano(): void
    {
        $tenant = Tenant::factory()->create();
        $ticket = $this->criarTicket($tenant);

        $ticket->origemMudancaColuna = 'humano';
        $ticket->update(['coluna_kanban' => 'em_atendimento']);

        $this->assertDatabaseHas('kanban_coluna_historico', [
            'ticket_id' => $ticket->id, 'coluna' => 'em_atendimento', 'origem' => 'humano',
        ]);
    }

    public function test_criacao_inicial_do_ticket_nao_grava_origem(): void
    {
        $tenant = Tenant::factory()->create();
        $ticket = $this->criarTicket($tenant);

        $this->assertNull(
            KanbanColunaHistorico::where('ticket_id', $ticket->id)->whereNull('coluna_anterior')->value('origem')
        );
    }

    public function test_propriedade_transiente_nao_e_persistida_no_proprio_ticket(): void
    {
        $tenant = Tenant::factory()->create();
        $ticket = $this->criarTicket($tenant);

        $ticket->origemMudancaColuna = 'humano';
        $ticket->update(['coluna_kanban' => 'em_atendimento']);

        $this->assertArrayNotHasKey('origem_mudanca_coluna', $ticket->fresh()->getAttributes());
        $this->assertArrayNotHasKey('origemMudancaColuna', $ticket->fresh()->getAttributes());
    }

    public function test_ordem_de_retorna_a_ordem_correta_e_null_se_a_coluna_nao_existir(): void
    {
        $tenant = Tenant::factory()->create();

        $this->assertSame(1, \App\Models\KanbanColuna::ordemDe($tenant->id, 'lead_novo'));
        $this->assertSame(5, \App\Models\KanbanColuna::ordemDe($tenant->id, 'pagamento'));
        $this->assertNull(\App\Models\KanbanColuna::ordemDe($tenant->id, 'nao_existe'));
    }

    public function test_movimento_adjacente_automatico_nao_gera_alerta(): void
    {
        $tenant = Tenant::factory()->create();
        $ticket = $this->criarTicket($tenant, 'lead_novo'); // ordem 1

        // Bloco 5 — origem não marcada, cai no default 'sistema'.
        $ticket->update(['coluna_kanban' => 'em_atendimento']); // ordem 2, adjacente

        $this->assertDatabaseMissing('alertas_internos', ['ticket_id' => $ticket->id]);
    }

    public function test_movimento_manual_adjacente_gera_alerta_migracao_atipica(): void
    {
        $tenant = Tenant::factory()->create();
        $ticket = $this->criarTicket($tenant, 'lead_novo');

        $ticket->origemMudancaColuna = 'humano';
        $ticket->update(['coluna_kanban' => 'em_atendimento']); // adjacente, mas manual

        $this->assertDatabaseHas('alertas_internos', [
            'ticket_id' => $ticket->id, 'tipo' => 'migracao_atipica',
        ]);
    }

    public function test_salto_de_mais_de_uma_coluna_gera_alerta_mesmo_automatico(): void
    {
        $tenant = Tenant::factory()->create();
        $ticket = $this->criarTicket($tenant, 'lead_novo'); // ordem 1

        // Bloco 5 — origem não marcada, cai no default 'sistema'; mesmo assim
        // alerta, porque nenhuma das colunas envolvidas é Encerramento/
        // TransferenciaHumana (a exclusão do guardrail não se aplica aqui).
        $ticket->update(['coluna_kanban' => 'pagamento']); // ordem 5, pula 3 colunas

        $this->assertDatabaseHas('alertas_internos', [
            'ticket_id' => $ticket->id, 'tipo' => 'migracao_atipica',
        ]);
        // A movimentação em si não é bloqueada.
        $this->assertSame('pagamento', $ticket->fresh()->coluna_kanban);
    }

    public function test_movimento_manual_com_salto_gera_apenas_um_alerta(): void
    {
        $tenant = Tenant::factory()->create();
        $ticket = $this->criarTicket($tenant, 'lead_novo');

        $ticket->origemMudancaColuna = 'humano';
        $ticket->update(['coluna_kanban' => 'pagamento']); // manual + salto

        $this->assertSame(
            1,
            \App\Models\AlertaInterno::where('ticket_id', $ticket->id)->where('tipo', 'migracao_atipica')->count()
        );
    }

    public function test_coluna_sem_registro_em_kanban_colunas_nao_calcula_salto_nem_falha(): void
    {
        $tenant = Tenant::factory()->create();
        // Nenhuma KanbanColuna cadastrada com essas chaves — mesmo padrão de
        // boa parte da suíte existente (coluna_kanban é só uma string solta).
        $ticket = $this->criarTicket($tenant, 'coluna_solta_a');

        $ticket->update(['coluna_kanban' => 'coluna_solta_b']);

        $this->assertDatabaseMissing('alertas_internos', ['ticket_id' => $ticket->id]);
    }

    public function test_encerramento_automatico_por_silencio_nao_gera_alerta_de_salto(): void
    {
        $tenant = Tenant::factory()->create();
        $ticket = $this->criarTicket($tenant, 'aguardando_lead'); // ordem 4

        // Simula FollowupConversas: encerramento automático (origem 'sistema'
        // por padrão), pulando direto pra "encerrado" (ordem 7, papel Encerramento).
        $ticket->update(['coluna_kanban' => 'encerrado']);

        $this->assertDatabaseMissing('alertas_internos', ['ticket_id' => $ticket->id]);
    }

    public function test_reabertura_automatica_de_encerrado_nao_gera_alerta_de_salto(): void
    {
        $tenant = Tenant::factory()->create();
        $ticket = $this->criarTicket($tenant, 'encerrado'); // ordem 7

        // Simula webhook (Uazapi/Covercut) reabrindo o ticket de volta pra uma
        // coluna bem anterior, automaticamente (origem 'sistema' por padrão).
        $ticket->update(['coluna_kanban' => 'em_atendimento']); // ordem 2

        $this->assertDatabaseMissing('alertas_internos', ['ticket_id' => $ticket->id]);
    }

    public function test_movimento_manual_de_e_para_encerrado_ainda_gera_alerta(): void
    {
        $tenant = Tenant::factory()->create();
        $ticket = $this->criarTicket($tenant, 'aguardando_lead'); // ordem 4

        $ticket->origemMudancaColuna = 'humano';
        $ticket->update(['coluna_kanban' => 'encerrado']); // manual, ainda que papel Encerramento esteja envolvido

        $this->assertDatabaseHas('alertas_internos', [
            'ticket_id' => $ticket->id, 'tipo' => 'migracao_atipica',
        ]);
    }

    public function test_falha_ao_criar_alerta_nao_impede_a_migracao(): void
    {
        $tenant = Tenant::factory()->create();
        $ticket = $this->criarTicket($tenant, 'lead_novo');

        $this->mock(\App\Services\AlertaInternoService::class, function ($mock) {
            $mock->shouldReceive('criar')->once()->andThrow(new \Exception('falha simulada'));
        });

        $ticket->origemMudancaColuna = 'humano';
        $ticket->update(['coluna_kanban' => 'em_atendimento']);

        $this->assertSame('em_atendimento', $ticket->fresh()->coluna_kanban);
    }

    public function test_mudar_de_coluna_com_pausa_pendente_fecha_o_alerta(): void
    {
        $tenant = Tenant::factory()->create();
        $ticket = $this->criarTicket($tenant, 'lead_novo');
        $ticket->update(['aguardando_orientacao_em' => now(), 'mensagem_espera_enviada' => true]);
        $alerta = \App\Models\AlertaInterno::create([
            'tenant_id' => $tenant->id, 'ticket_id' => $ticket->id,
            'tipo' => 'duvida_ia', 'titulo' => 'Dúvida', 'conteudo' => 'x',
        ]);

        $ticket->update(['coluna_kanban' => 'em_atendimento']);

        $alerta->refresh();
        $this->assertNotNull($alerta->resposta);
        $this->assertNotNull($alerta->respondido_em);
        $this->assertNull($ticket->fresh()->aguardando_orientacao_em);
    }

    public function test_mudar_de_coluna_sem_pausa_pendente_nao_mexe_em_alerta_nenhum(): void
    {
        $tenant = Tenant::factory()->create();
        $ticket = $this->criarTicket($tenant, 'lead_novo');
        // Ticket nunca foi pausado — sem aguardando_orientacao_em, sem alerta.

        $ticket->update(['coluna_kanban' => 'em_atendimento']);

        $this->assertSame(0, \App\Models\AlertaInterno::where('ticket_id', $ticket->id)->count());
    }

    public function test_movimento_automatico_sem_origem_marcada_grava_sistema(): void
    {
        $tenant = Tenant::factory()->create();
        $ticket = $this->criarTicket($tenant);

        // Simula um caminho automático (ex: FollowupConversas, webhook) —
        // nenhum código chama origemMudancaColuna, então cai no novo default.
        $ticket->update(['coluna_kanban' => 'em_atendimento']);

        $this->assertDatabaseHas('kanban_coluna_historico', [
            'ticket_id' => $ticket->id, 'coluna' => 'em_atendimento', 'origem' => 'sistema',
        ]);
    }

    public function test_token_de_coluna_no_sdr_responder_service_grava_origem_ia(): void
    {
        \Illuminate\Support\Facades\Http::fake([
            'openrouter.ai/*' => \Illuminate\Support\Facades\Http::response([
                'choices' => [['message' => ['content' => 'Combinado! [PAGAMENTO]']]],
            ], 200),
        ]);

        $tenant  = Tenant::factory()->create();
        $persona = \App\Models\SdrPersona::create([
            'tenant_id' => $tenant->id, 'nome_interno' => 'padrao', 'nome_display' => 'Joao',
            'system_prompt' => 'Você é um atendente.', 'ativo' => true, 'is_default' => true, 'tier' => 'simples',
        ]);
        $contato = \App\Models\Contato::factory()->create();
        $ticket  = TicketAtendimento::create([
            'tenant_id' => $tenant->id, 'contato_id' => $contato->id,
            'coluna_kanban' => 'aguardando_orcamento', 'agente_responsavel' => 'bot', 'etapa_ia' => 'etapa_1',
            'status' => 'aberto', 'aberto_em' => now(), 'sdr_persona_id' => $persona->id,
        ]);

        app(\App\Services\SdrResponderService::class)->responder($ticket);

        $this->assertDatabaseHas('kanban_coluna_historico', [
            'ticket_id' => $ticket->id, 'coluna' => 'pagamento', 'origem' => 'ia',
        ]);
    }

    public function test_sistema_pulando_para_encerrado_continua_sem_alerta(): void
    {
        $tenant = Tenant::factory()->create();
        $ticket = $this->criarTicket($tenant, 'lead_novo'); // ordem 1

        // origemMudancaColuna não setada — cai em 'sistema' (ex: auto-mover).
        $ticket->update(['coluna_kanban' => 'encerrado']); // papel Encerramento, salto grande

        $this->assertDatabaseMissing('alertas_internos', ['ticket_id' => $ticket->id, 'tipo' => 'migracao_atipica']);
    }

    public function test_ia_fecha_com_objetivos_pendentes_gera_alerta(): void
    {
        \Illuminate\Support\Facades\Http::fake([
            'openrouter.ai/*' => \Illuminate\Support\Facades\Http::response([
                'choices' => [['message' => ['content' => 'Tudo bem, até mais! [ENCERRADO]']]],
            ], 200),
        ]);

        $tenant  = Tenant::factory()->create();
        $persona = \App\Models\SdrPersona::create([
            'tenant_id' => $tenant->id, 'nome_interno' => 'padrao', 'nome_display' => 'Joao',
            'system_prompt' => 'Você é um atendente.', 'ativo' => true, 'is_default' => true, 'tier' => 'simples',
        ]);
        \App\Models\KanbanColunaObjetivo::create([
            'tenant_id' => $tenant->id, 'coluna_kanban' => 'lead_novo',
            'texto' => 'Coletar nome do lead', 'ativo' => true, 'ordem' => 1,
        ]);
        $contato = \App\Models\Contato::factory()->create();
        $ticket  = TicketAtendimento::create([
            'tenant_id' => $tenant->id, 'contato_id' => $contato->id,
            'coluna_kanban' => 'lead_novo', 'agente_responsavel' => 'bot', 'etapa_ia' => 'etapa_1',
            'status' => 'aberto', 'aberto_em' => now(), 'sdr_persona_id' => $persona->id,
            'objetivos_cumpridos' => [], // objetivo configurado, mas não marcado
        ]);

        app(\App\Services\SdrResponderService::class)->responder($ticket);

        $this->assertDatabaseHas('alertas_internos', [
            'ticket_id' => $ticket->id, 'tipo' => 'migracao_atipica',
        ]);
    }

    public function test_ia_fecha_com_objetivos_todos_cumpridos_nao_gera_alerta(): void
    {
        \Illuminate\Support\Facades\Http::fake([
            'openrouter.ai/*' => \Illuminate\Support\Facades\Http::response([
                'choices' => [['message' => ['content' => 'Tudo bem, até mais! [ENCERRADO]']]],
            ], 200),
        ]);

        $tenant  = Tenant::factory()->create();
        $persona = \App\Models\SdrPersona::create([
            'tenant_id' => $tenant->id, 'nome_interno' => 'padrao', 'nome_display' => 'Joao',
            'system_prompt' => 'Você é um atendente.', 'ativo' => true, 'is_default' => true, 'tier' => 'simples',
        ]);
        $objetivo = \App\Models\KanbanColunaObjetivo::create([
            'tenant_id' => $tenant->id, 'coluna_kanban' => 'lead_novo',
            'texto' => 'Coletar nome do lead', 'ativo' => true, 'ordem' => 1,
        ]);
        $contato = \App\Models\Contato::factory()->create();
        $ticket  = TicketAtendimento::create([
            'tenant_id' => $tenant->id, 'contato_id' => $contato->id,
            'coluna_kanban' => 'lead_novo', 'agente_responsavel' => 'bot', 'etapa_ia' => 'etapa_1',
            'status' => 'aberto', 'aberto_em' => now(), 'sdr_persona_id' => $persona->id,
            'objetivos_cumpridos' => [$objetivo->id], // já cumprido
        ]);

        app(\App\Services\SdrResponderService::class)->responder($ticket);

        $this->assertDatabaseMissing('alertas_internos', [
            'ticket_id' => $ticket->id, 'tipo' => 'migracao_atipica',
        ]);
    }

    public function test_ia_fecha_coluna_sem_checklist_configurado_nao_gera_alerta(): void
    {
        \Illuminate\Support\Facades\Http::fake([
            'openrouter.ai/*' => \Illuminate\Support\Facades\Http::response([
                'choices' => [['message' => ['content' => 'Tudo bem, até mais! [ENCERRADO]']]],
            ], 200),
        ]);

        $tenant  = Tenant::factory()->create();
        $persona = \App\Models\SdrPersona::create([
            'tenant_id' => $tenant->id, 'nome_interno' => 'padrao', 'nome_display' => 'Joao',
            'system_prompt' => 'Você é um atendente.', 'ativo' => true, 'is_default' => true, 'tier' => 'simples',
        ]);
        // Nenhum KanbanColunaObjetivo cadastrado pra 'lead_novo'.
        $contato = \App\Models\Contato::factory()->create();
        $ticket  = TicketAtendimento::create([
            'tenant_id' => $tenant->id, 'contato_id' => $contato->id,
            'coluna_kanban' => 'lead_novo', 'agente_responsavel' => 'bot', 'etapa_ia' => 'etapa_1',
            'status' => 'aberto', 'aberto_em' => now(), 'sdr_persona_id' => $persona->id,
        ]);

        app(\App\Services\SdrResponderService::class)->responder($ticket);

        $this->assertDatabaseMissing('alertas_internos', [
            'ticket_id' => $ticket->id, 'tipo' => 'migracao_atipica',
        ]);
    }
}
