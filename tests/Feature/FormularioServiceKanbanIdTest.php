<?php

namespace Tests\Feature;

use App\Models\Formulario;
use App\Models\Kanban;
use App\Models\Tenant;
use App\Models\TicketAtendimento;
use App\Services\FormularioService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

class FormularioServiceKanbanIdTest extends TestCase
{
    use RefreshDatabase;

    public function test_ticket_criado_pelo_formulario_grava_kanban_id(): void
    {
        Bus::fake();

        $tenant     = Tenant::factory()->create();
        $kanban     = Kanban::where('tenant_id', $tenant->id)->where('tipo', 'vendas')->firstOrFail();
        $formulario = Formulario::create([
            'tenant_id' => $tenant->id,
            'uuid'      => 'form-kanban-id-teste',
            'nome'      => 'Formulário de teste',
            'ativo'     => true,
        ]);

        $resultado = app(FormularioService::class)->processar($formulario, [
            'telefone' => '21977776666',
            'nome'     => 'Lead Teste',
        ], 'teste.com.br');

        $this->assertTrue($resultado['ok']);

        $ticket = TicketAtendimento::where('tenant_id', $tenant->id)->latest()->first();
        $this->assertNotNull($ticket);
        $this->assertSame($kanban->id, $ticket->kanban_id);
    }
}
