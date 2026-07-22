<?php

namespace Tests\Feature;

use App\Enums\PapelColunaKanban;
use App\Models\Formulario;
use App\Models\Kanban;
use App\Models\KanbanColuna;
use App\Models\Tenant;
use App\Models\TicketAtendimento;
use App\Services\FormularioService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

class CriacaoTicketUsaChaveDeEntradaTest extends TestCase
{
    use RefreshDatabase;

    public function test_formulario_service_cria_ticket_na_coluna_de_entrada_do_tenant(): void
    {
        Bus::fake();

        $tenant = Tenant::factory()->create();
        $kanban = Kanban::where('tenant_id', $tenant->id)->where('tipo', 'vendas')->firstOrFail();
        KanbanColuna::where('kanban_id', $kanban->id)->where('papel', PapelColunaKanban::Entrada)
            ->update(['chave' => 'novo_contato']);

        $formulario = Formulario::create([
            'tenant_id' => $tenant->id, 'uuid' => 'form-entrada-teste',
            'nome' => 'Formulário de teste', 'ativo' => true,
        ]);

        app(FormularioService::class)->processar($formulario, [
            'telefone' => '21999998887', 'nome' => 'Cliente Teste',
        ], 'teste.com.br');

        $ticket = TicketAtendimento::where('tenant_id', $tenant->id)->firstOrFail();
        $this->assertSame('novo_contato', $ticket->coluna_kanban);
    }
}
