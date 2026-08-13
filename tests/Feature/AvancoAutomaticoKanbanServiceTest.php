<?php

namespace Tests\Feature;

use App\Enums\PapelColunaKanban;
use App\Models\Contato;
use App\Models\KanbanColuna;
use App\Models\KanbanColunaObjetivo;
use App\Models\Tenant;
use App\Models\TicketAtendimento;
use App\Services\AvancoAutomaticoKanbanService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AvancoAutomaticoKanbanServiceTest extends TestCase
{
    use RefreshDatabase;

    private function criarTicket(string $coluna = 'em_atendimento'): TicketAtendimento
    {
        $tenant  = Tenant::factory()->create();
        $contato = Contato::factory()->create();

        return TicketAtendimento::create([
            'tenant_id' => $tenant->id, 'contato_id' => $contato->id,
            'coluna_kanban' => $coluna, 'agente_responsavel' => 'bot',
            'status' => 'aberto', 'aberto_em' => now(),
        ]);
    }

    private function criarObjetivo(TicketAtendimento $ticket, string $texto, string $coluna = 'em_atendimento'): KanbanColunaObjetivo
    {
        return KanbanColunaObjetivo::create([
            'tenant_id' => $ticket->tenant_id, 'coluna_kanban' => $coluna,
            'texto' => $texto, 'ordem' => 1, 'ativo' => true,
        ]);
    }

    public function test_marca_objetivo_sem_avancar_se_ainda_faltam_outros(): void
    {
        $ticket = $this->criarTicket();
        $obj1   = $this->criarObjetivo($ticket, 'Endereço de origem');
        $this->criarObjetivo($ticket, 'Lista de itens'); // segundo objetivo, não marcado

        app(AvancoAutomaticoKanbanService::class)->marcarObjetivos($ticket, [$obj1->id]);

        $fresco = $ticket->fresh();
        $this->assertSame([$obj1->id], $fresco->objetivos_cumpridos);
        $this->assertSame('em_atendimento', $fresco->coluna_kanban);
    }

    public function test_marca_ultimo_objetivo_avanca_para_proxima_coluna(): void
    {
        $ticket = $this->criarTicket();
        $obj1   = $this->criarObjetivo($ticket, 'Endereço de origem');
        $obj2   = $this->criarObjetivo($ticket, 'Lista de itens');
        $ticket->update(['objetivos_cumpridos' => [$obj1->id]]);

        app(AvancoAutomaticoKanbanService::class)->marcarObjetivos($ticket, [$obj2->id]);

        $fresco = $ticket->fresh();
        $this->assertSame('aguardando_orcamento', $fresco->coluna_kanban);
        // Checklist da nova coluna começa zerada (hook de reset já existente).
        $this->assertSame([], $fresco->objetivos_cumpridos ?? []);
    }

    public function test_nao_avanca_para_coluna_de_papel_encerramento(): void
    {
        $ticket = $this->criarTicket('servico_agendado');
        $obj    = $this->criarObjetivo($ticket, 'Serviço confirmado', 'servico_agendado');

        app(AvancoAutomaticoKanbanService::class)->marcarObjetivos($ticket, [$obj->id]);

        $fresco = $ticket->fresh();
        $this->assertSame('servico_agendado', $fresco->coluna_kanban);
        $this->assertSame([$obj->id], $fresco->objetivos_cumpridos);
    }

    public function test_nao_avanca_para_coluna_de_papel_transferencia_humana(): void
    {
        $ticket = $this->criarTicket('encerrado');
        $obj    = $this->criarObjetivo($ticket, 'Algo', 'encerrado');

        app(AvancoAutomaticoKanbanService::class)->marcarObjetivos($ticket, [$obj->id]);

        $this->assertSame('encerrado', $ticket->fresh()->coluna_kanban);
    }

    public function test_coluna_sem_objetivo_ativo_nunca_avanca(): void
    {
        $ticket = $this->criarTicket();

        $avancou = app(AvancoAutomaticoKanbanService::class)->avancarSeCompleto($ticket);

        $this->assertFalse($avancou);
        $this->assertSame('em_atendimento', $ticket->fresh()->coluna_kanban);
    }

    public function test_ja_na_ultima_coluna_nao_quebra(): void
    {
        $ticket = $this->criarTicket('outros'); // última coluna padrão (ordem 8)
        $obj    = $this->criarObjetivo($ticket, 'Algo', 'outros');

        app(AvancoAutomaticoKanbanService::class)->marcarObjetivos($ticket, [$obj->id]);

        $this->assertSame('outros', $ticket->fresh()->coluna_kanban);
    }

    public function test_id_invalido_ou_de_outra_coluna_e_ignorado(): void
    {
        $ticket = $this->criarTicket();
        $obj    = $this->criarObjetivo($ticket, 'Endereço', 'aguardando_orcamento'); // outra coluna

        app(AvancoAutomaticoKanbanService::class)->marcarObjetivos($ticket, [$obj->id, 999999]);

        $this->assertSame([], $ticket->fresh()->objetivos_cumpridos ?? []);
    }

    public function test_objetivo_ja_marcado_nao_duplica_na_lista(): void
    {
        $ticket = $this->criarTicket();
        $obj    = $this->criarObjetivo($ticket, 'Endereço');
        // Segundo objetivo pendente — mantém a checklist incompleta, senão
        // (Achado 2 da revisão final: avancarSeCompletoInterno agora roda
        // incondicionalmente) o único objetivo já completo faria o ticket
        // avançar de coluna e zerar objetivos_cumpridos como efeito colateral,
        // o que não é o que este teste quer verificar (aqui o foco é dedup).
        $this->criarObjetivo($ticket, 'Lista de itens');
        $ticket->update(['objetivos_cumpridos' => [$obj->id]]);

        app(AvancoAutomaticoKanbanService::class)->marcarObjetivos($ticket, [$obj->id]);

        $this->assertSame([$obj->id], $ticket->fresh()->objetivos_cumpridos);
    }
}
