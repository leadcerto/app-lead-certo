<?php

namespace Tests\Feature;

use App\Models\Contato;
use App\Models\Mensagem;
use App\Models\Tenant;
use App\Models\TicketAtendimento;
use App\Services\GestorKanbanService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class GestorKanbanServiceAmostraTest extends TestCase
{
    use RefreshDatabase;

    public function test_amostra_prioriza_os_mais_travados_ate_o_limite(): void
    {
        $tenant  = Tenant::factory()->create();
        $contato = Contato::factory()->create();

        Carbon::setTestNow('2026-06-01 10:00:00');
        $maisAntigo = TicketAtendimento::create([
            'tenant_id' => $tenant->id, 'contato_id' => $contato->id,
            'coluna_kanban' => 'em_atendimento', 'agente_responsavel' => 'bot',
            'status' => 'aberto', 'aberto_em' => now(),
        ]);

        Carbon::setTestNow('2026-07-10 10:00:00');
        $maisRecente = TicketAtendimento::create([
            'tenant_id' => $tenant->id, 'contato_id' => $contato->id,
            'coluna_kanban' => 'em_atendimento', 'agente_responsavel' => 'bot',
            'status' => 'aberto', 'aberto_em' => now(),
        ]);
        Carbon::setTestNow();

        $inicio = Carbon::parse('2026-07-06 00:00:00');
        $fim    = Carbon::parse('2026-07-12 23:59:59');

        $amostra = app(GestorKanbanService::class)->amostrarConversasColuna($tenant, 'em_atendimento', $inicio, $fim, 1);

        $this->assertCount(1, $amostra);
        $this->assertSame($maisAntigo->id, $amostra->first()->id);
        $this->assertTrue(true !== $amostra->contains('id', $maisRecente->id));
    }

    public function test_amostra_da_coluna_encerrado_inclui_fechados_na_semana(): void
    {
        $tenant  = Tenant::factory()->create();
        $contato = Contato::factory()->create();

        $ticket = TicketAtendimento::create([
            'tenant_id' => $tenant->id, 'contato_id' => $contato->id,
            'coluna_kanban' => 'encerrado', 'agente_responsavel' => 'humano',
            'status' => 'encerrado', 'aberto_em' => now(),
            'encerrado_em' => Carbon::parse('2026-07-08 10:00:00'),
        ]);

        $inicio = Carbon::parse('2026-07-06 00:00:00');
        $fim    = Carbon::parse('2026-07-12 23:59:59');

        $amostra = app(GestorKanbanService::class)->amostrarConversasColuna($tenant, 'encerrado', $inicio, $fim, 15);

        $this->assertTrue($amostra->contains('id', $ticket->id));
    }

    public function test_formatar_conversa_usa_resumo_ia_para_ticket_encerrado_com_resumo(): void
    {
        $tenant  = Tenant::factory()->create();
        $contato = Contato::factory()->create();

        $ticket = TicketAtendimento::create([
            'tenant_id' => $tenant->id, 'contato_id' => $contato->id,
            'coluna_kanban' => 'encerrado', 'agente_responsavel' => 'humano',
            'status' => 'encerrado', 'aberto_em' => now(),
            'resumo_ia' => 'Cliente pediu frete de SP para RJ, fechou negócio.',
        ]);

        $texto = app(GestorKanbanService::class)->formatarConversa($ticket);

        $this->assertStringContainsString('Cliente pediu frete de SP para RJ, fechou negócio.', $texto);
    }

    public function test_amostra_da_coluna_encerrado_nao_e_engolida_por_volume_antigo(): void
    {
        $tenant  = Tenant::factory()->create();
        $contato = Contato::factory()->create();
        $limite  = 2;

        // Mais tickets antigos "travados" em encerrado do que o $limite,
        // todos atualizados bem antes da semana do relatório.
        Carbon::setTestNow('2026-01-01 10:00:00');
        for ($i = 0; $i < $limite + 3; $i++) {
            TicketAtendimento::create([
                'tenant_id' => $tenant->id, 'contato_id' => $contato->id,
                'coluna_kanban' => 'encerrado', 'agente_responsavel' => 'humano',
                'status' => 'encerrado', 'aberto_em' => now(),
                'encerrado_em' => Carbon::parse('2026-01-02 10:00:00'),
            ]);
        }
        Carbon::setTestNow();

        // Um ticket fechado DENTRO da semana do relatório.
        $fechadoNaSemana = TicketAtendimento::create([
            'tenant_id' => $tenant->id, 'contato_id' => $contato->id,
            'coluna_kanban' => 'encerrado', 'agente_responsavel' => 'humano',
            'status' => 'encerrado', 'aberto_em' => now(),
            'encerrado_em' => Carbon::parse('2026-07-08 10:00:00'),
        ]);

        $inicio = Carbon::parse('2026-07-06 00:00:00');
        $fim    = Carbon::parse('2026-07-12 23:59:59');

        $amostra = app(GestorKanbanService::class)->amostrarConversasColuna($tenant, 'encerrado', $inicio, $fim, $limite);

        $this->assertTrue(
            $amostra->contains('id', $fechadoNaSemana->id),
            'O ticket fechado dentro da semana do relatório deve aparecer na amostra, mesmo com muito volume antigo travado na coluna.'
        );
        $this->assertCount($limite, $amostra);
    }

    public function test_formatar_conversa_monta_thread_quando_nao_tem_resumo(): void
    {
        $tenant  = Tenant::factory()->create();
        $contato = Contato::factory()->create();

        $ticket = TicketAtendimento::create([
            'tenant_id' => $tenant->id, 'contato_id' => $contato->id,
            'coluna_kanban' => 'em_atendimento', 'agente_responsavel' => 'bot',
            'status' => 'aberto', 'aberto_em' => now(),
        ]);

        Mensagem::create([
            'ticket_id' => $ticket->id, 'tenant_id' => $tenant->id,
            'remetente' => 'lead', 'tipo' => 'texto', 'conteudo' => 'Quanto custa o frete?',
            'enviado_em' => now(),
        ]);
        Mensagem::create([
            'ticket_id' => $ticket->id, 'tenant_id' => $tenant->id,
            'remetente' => 'bot', 'tipo' => 'texto', 'conteudo' => 'Me conta o endereço de origem.',
            'enviado_em' => now(),
        ]);

        $texto = app(GestorKanbanService::class)->formatarConversa($ticket->fresh('mensagens'));

        $this->assertStringContainsString('CLIENTE: Quanto custa o frete?', $texto);
        $this->assertStringContainsString('BOT: Me conta o endereço de origem.', $texto);
    }
}
