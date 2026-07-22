<?php

namespace Tests\Feature;

use App\Models\Contato;
use App\Models\GestorKanbanRelatorio;
use App\Models\Tenant;
use App\Models\TicketAtendimento;
use App\Services\GestorKanbanService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GestorKanbanServiceOrquestracaoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake(['*' => Http::response([
            'choices' => [['message' => ['content' =>
                "ANÁLISE:\nTudo bem por aqui.\n\nSUGESTÃO_PROMPT:\nContinue assim."
            ]]],
            'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 20],
        ], 200)]);
    }

    public function test_gera_relatorio_com_dados_por_coluna_e_sintese(): void
    {
        $tenant  = Tenant::factory()->create();
        $contato = Contato::factory()->create();
        $inicio  = Carbon::parse('2026-07-06 00:00:00');
        $fim     = Carbon::parse('2026-07-12 23:59:59');

        Carbon::setTestNow('2026-07-08 10:00:00');
        TicketAtendimento::create([
            'tenant_id' => $tenant->id, 'contato_id' => $contato->id,
            'coluna_kanban' => 'lead_novo', 'agente_responsavel' => 'bot',
            'status' => 'aberto', 'aberto_em' => now(),
        ]);
        Carbon::setTestNow();

        $relatorio = app(GestorKanbanService::class)->gerarRelatorioSemanal($tenant, $inicio, $fim);

        $this->assertNotNull($relatorio);
        $this->assertSame($tenant->id, $relatorio->tenant_id);
        $this->assertArrayHasKey('lead_novo', $relatorio->dados);
        $this->assertSame(1, $relatorio->dados['lead_novo']['entradas']);
        $this->assertSame('Tudo bem por aqui.', $relatorio->dados['lead_novo']['analise']);
        $this->assertNotNull($relatorio->sintese_geral);
    }

    public function test_coluna_sem_atividade_nao_chama_ia_e_fica_com_mensagem_padrao(): void
    {
        $tenant = Tenant::factory()->create();
        $inicio = Carbon::parse('2026-07-06 00:00:00');
        $fim    = Carbon::parse('2026-07-12 23:59:59');

        $contato = Contato::factory()->create();
        Carbon::setTestNow('2026-07-08 10:00:00');
        TicketAtendimento::create([
            'tenant_id' => $tenant->id, 'contato_id' => $contato->id,
            'coluna_kanban' => 'lead_novo', 'agente_responsavel' => 'bot',
            'status' => 'aberto', 'aberto_em' => now(),
        ]);
        Carbon::setTestNow();

        $relatorio = app(GestorKanbanService::class)->gerarRelatorioSemanal($tenant, $inicio, $fim);

        $this->assertSame('Sem atividade nesta coluna na semana.', $relatorio->dados['pagamento']['analise']);
        $this->assertNull($relatorio->dados['pagamento']['sugestao_prompt']);
    }

    public function test_tenant_sem_nenhuma_atividade_nao_gera_relatorio(): void
    {
        $tenant = Tenant::factory()->create();
        $inicio = Carbon::parse('2026-07-06 00:00:00');
        $fim    = Carbon::parse('2026-07-12 23:59:59');

        $relatorio = app(GestorKanbanService::class)->gerarRelatorioSemanal($tenant, $inicio, $fim);

        $this->assertNull($relatorio);
        $this->assertSame(0, GestorKanbanRelatorio::withoutGlobalScopes()->where('tenant_id', $tenant->id)->count());
    }

    public function test_rodar_de_novo_pra_mesma_semana_atualiza_em_vez_de_duplicar(): void
    {
        $tenant  = Tenant::factory()->create();
        $contato = Contato::factory()->create();
        $inicio  = Carbon::parse('2026-07-06 00:00:00');
        $fim     = Carbon::parse('2026-07-12 23:59:59');

        Carbon::setTestNow('2026-07-08 10:00:00');
        TicketAtendimento::create([
            'tenant_id' => $tenant->id, 'contato_id' => $contato->id,
            'coluna_kanban' => 'lead_novo', 'agente_responsavel' => 'bot',
            'status' => 'aberto', 'aberto_em' => now(),
        ]);
        Carbon::setTestNow();

        app(GestorKanbanService::class)->gerarRelatorioSemanal($tenant, $inicio, $fim);
        app(GestorKanbanService::class)->gerarRelatorioSemanal($tenant, $inicio, $fim);

        $this->assertSame(1, GestorKanbanRelatorio::withoutGlobalScopes()->where('tenant_id', $tenant->id)->count());
    }

    public function test_relatorio_inclui_coluna_customizada_criada_pelo_franqueado(): void
    {
        $tenant = Tenant::factory()->create();
        $kanban = \App\Models\Kanban::where('tenant_id', $tenant->id)->where('tipo', 'vendas')->firstOrFail();
        \App\Models\KanbanColuna::create([
            'tenant_id' => $tenant->id, 'kanban_id' => $kanban->id,
            'chave' => 'triagem_extra', 'label' => 'Triagem Extra',
            'papel' => \App\Enums\PapelColunaKanban::EmAndamento, 'ordem' => 99,
        ]);
        $inicio = Carbon::parse('2026-07-06 00:00:00');
        $fim    = Carbon::parse('2026-07-12 23:59:59');

        // Precisa de atividade em ALGUMA coluna do tenant na semana, senão
        // gerarRelatorioSemanal() retorna null antes mesmo de montar $dados
        // (mesmo comportamento coberto em test_tenant_sem_nenhuma_atividade_nao_gera_relatorio).
        $contato = Contato::factory()->create();
        Carbon::setTestNow('2026-07-08 10:00:00');
        TicketAtendimento::create([
            'tenant_id' => $tenant->id, 'contato_id' => $contato->id,
            'coluna_kanban' => 'lead_novo', 'agente_responsavel' => 'bot',
            'status' => 'aberto', 'aberto_em' => now(),
        ]);
        Carbon::setTestNow();

        $relatorio = app(GestorKanbanService::class)->gerarRelatorioSemanal($tenant, $inicio, $fim);

        $this->assertNotNull($relatorio);
        $this->assertArrayHasKey('triagem_extra', $relatorio->dados);
    }
}
