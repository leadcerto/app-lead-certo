<?php

namespace Tests\Feature;

use App\Models\Contato;
use App\Models\Tenant;
use App\Models\TicketAtendimento;
use App\Services\GestorKanbanService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class GestorKanbanServiceNumerosTest extends TestCase
{
    use RefreshDatabase;

    private function criarTicket(int $tenantId, string $coluna, ?string $tagDesfecho = null, ?Carbon $encerradoEm = null): TicketAtendimento
    {
        $contato = Contato::factory()->create();

        return TicketAtendimento::create([
            'tenant_id' => $tenantId, 'contato_id' => $contato->id,
            'coluna_kanban' => $coluna, 'agente_responsavel' => 'bot',
            'status' => $coluna === 'encerrado' ? 'encerrado' : 'aberto',
            'aberto_em' => now(), 'tag_desfecho' => $tagDesfecho, 'encerrado_em' => $encerradoEm,
        ]);
    }

    public function test_conta_entradas_na_semana(): void
    {
        $tenant = Tenant::factory()->create();
        $inicio = Carbon::parse('2026-07-06 00:00:00');
        $fim    = Carbon::parse('2026-07-12 23:59:59');

        Carbon::setTestNow('2026-07-08 10:00:00');
        $this->criarTicket($tenant->id, 'lead_novo');
        $this->criarTicket($tenant->id, 'lead_novo');

        Carbon::setTestNow('2026-07-01 10:00:00'); // fora da semana
        $this->criarTicket($tenant->id, 'lead_novo');
        Carbon::setTestNow();

        $numeros = app(GestorKanbanService::class)->coletarNumerosColuna($tenant, 'lead_novo', $inicio, $fim);

        $this->assertSame(2, $numeros['entradas']);
    }

    public function test_conta_avancos_na_semana(): void
    {
        $tenant = Tenant::factory()->create();
        $inicio = Carbon::parse('2026-07-06 00:00:00');
        $fim    = Carbon::parse('2026-07-12 23:59:59');

        Carbon::setTestNow('2026-07-01 10:00:00');
        $ticket = $this->criarTicket($tenant->id, 'lead_novo');
        Carbon::setTestNow('2026-07-08 10:00:00');
        $ticket->update(['coluna_kanban' => 'em_atendimento']);
        Carbon::setTestNow();

        $numeros = app(GestorKanbanService::class)->coletarNumerosColuna($tenant, 'lead_novo', $inicio, $fim);

        $this->assertSame(1, $numeros['avancos']);
    }

    public function test_conta_travados_como_quem_esta_na_coluna_desde_antes_da_semana(): void
    {
        $tenant = Tenant::factory()->create();
        $inicio = Carbon::parse('2026-07-06 00:00:00');
        $fim    = Carbon::parse('2026-07-12 23:59:59');

        Carbon::setTestNow('2026-06-20 10:00:00');
        $this->criarTicket($tenant->id, 'em_atendimento'); // travado: entrou bem antes da semana

        Carbon::setTestNow('2026-07-08 10:00:00');
        $this->criarTicket($tenant->id, 'em_atendimento'); // não travado: entrou durante a semana
        Carbon::setTestNow();

        $numeros = app(GestorKanbanService::class)->coletarNumerosColuna($tenant, 'em_atendimento', $inicio, $fim);

        $this->assertSame(1, $numeros['travados']);
    }

    public function test_breakdown_de_tag_desfecho_so_para_coluna_encerrado(): void
    {
        $tenant = Tenant::factory()->create();
        $inicio = Carbon::parse('2026-07-06 00:00:00');
        $fim    = Carbon::parse('2026-07-12 23:59:59');

        $this->criarTicket($tenant->id, 'encerrado', 'preco', Carbon::parse('2026-07-08 10:00:00'));
        $this->criarTicket($tenant->id, 'encerrado', 'preco', Carbon::parse('2026-07-09 10:00:00'));
        $this->criarTicket($tenant->id, 'encerrado', 'vendido', Carbon::parse('2026-07-10 10:00:00'));

        $numeros = app(GestorKanbanService::class)->coletarNumerosColuna($tenant, 'encerrado', $inicio, $fim);

        $this->assertSame(['preco' => 2, 'vendido' => 1], $numeros['tag_desfecho_breakdown']);

        $numerosOutraColuna = app(GestorKanbanService::class)->coletarNumerosColuna($tenant, 'lead_novo', $inicio, $fim);
        $this->assertSame([], $numerosOutraColuna['tag_desfecho_breakdown']);
    }

    public function test_breakdown_de_tag_desfecho_funciona_com_coluna_de_encerramento_renomeada(): void
    {
        $tenant = Tenant::factory()->create();
        $kanban = \App\Models\Kanban::where('tenant_id', $tenant->id)->where('tipo', 'vendas')->firstOrFail();
        \App\Models\KanbanColuna::where('kanban_id', $kanban->id)->where('papel', \App\Enums\PapelColunaKanban::Encerramento)
            ->update(['chave' => 'finalizado']);
        $inicio = Carbon::parse('2026-07-06 00:00:00');
        $fim    = Carbon::parse('2026-07-12 23:59:59');

        $this->criarTicket($tenant->id, 'finalizado', 'preco', Carbon::parse('2026-07-08 10:00:00'));

        $numeros = app(GestorKanbanService::class)->coletarNumerosColuna($tenant, 'finalizado', $inicio, $fim);

        $this->assertSame(['preco' => 1], $numeros['tag_desfecho_breakdown']);
    }

    public function test_isola_numeros_por_tenant(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();
        $inicio  = Carbon::parse('2026-07-06 00:00:00');
        $fim     = Carbon::parse('2026-07-12 23:59:59');

        Carbon::setTestNow('2026-07-08 10:00:00');
        $this->criarTicket($tenantA->id, 'lead_novo');
        $this->criarTicket($tenantB->id, 'lead_novo');
        $this->criarTicket($tenantB->id, 'lead_novo');
        Carbon::setTestNow();

        $numerosA = app(GestorKanbanService::class)->coletarNumerosColuna($tenantA, 'lead_novo', $inicio, $fim);
        $numerosB = app(GestorKanbanService::class)->coletarNumerosColuna($tenantB, 'lead_novo', $inicio, $fim);

        $this->assertSame(1, $numerosA['entradas']);
        $this->assertSame(2, $numerosB['entradas']);
    }
}
