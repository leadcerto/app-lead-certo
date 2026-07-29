<?php

namespace Tests\Feature;

use App\Models\Contato;
use App\Models\Formulario;
use App\Models\Kanban;
use App\Models\Tenant;
use App\Models\TicketAtendimento;
use App\Models\WhatsappCanal;
use App\Services\FormularioService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class NovoTicketSorteiaCanalTest extends TestCase
{
    use RefreshDatabase;

    public function test_ticket_interno_recebe_canal_vinculado_ao_kanban(): void
    {
        $tenant = Tenant::factory()->create();
        $kanban = Kanban::where('tenant_id', $tenant->id)->where('tipo', 'vendas')->firstOrFail();
        $canal  = WhatsappCanal::factory()->create(['tenant_id' => $tenant->id, 'status' => 'connected']);
        $kanban->canais()->attach($canal->id);
        $contato = Contato::factory()->create();

        config(['app.service_key' => 'chave-de-teste']);

        $response = $this->postJson('/api/internal/ticket', [
            'contato_id' => $contato->id,
            'tenant_id'  => $tenant->id,
        ], ['X-Service-Key' => 'chave-de-teste']);

        $response->assertOk();
        $ticket = TicketAtendimento::withoutGlobalScopes()->where('tenant_id', $tenant->id)->first();
        $this->assertSame($canal->id, $ticket->whatsapp_canal_id);
    }

    public function test_ticket_de_formulario_recebe_canal_vinculado_ao_kanban(): void
    {
        Bus::fake();

        $tenant = Tenant::factory()->create();
        $kanban = Kanban::where('tenant_id', $tenant->id)->where('tipo', 'vendas')->firstOrFail();
        $canal  = WhatsappCanal::factory()->create(['tenant_id' => $tenant->id, 'status' => 'connected']);
        $kanban->canais()->attach($canal->id);

        $formulario = Formulario::create([
            'tenant_id' => $tenant->id,
            'uuid'      => 'form-teste-canal',
            'nome'      => 'Formulário de teste',
            'ativo'     => true,
        ]);

        $resultado = app(FormularioService::class)->processar($formulario, [
            'telefone' => '21999998888',
            'nome'     => 'Lead do Formulário',
        ], 'teste.com.br');

        $ticket = TicketAtendimento::withoutGlobalScopes()->findOrFail($resultado['ticket_id']);
        $this->assertSame($canal->id, $ticket->whatsapp_canal_id);
    }

    public function test_ticket_de_chamada_perdida_recebe_canal_vinculado_ao_kanban(): void
    {
        Queue::fake();

        $tenant = Tenant::factory()->create(['secretaria_token' => 'token-teste-canal']);
        $kanban = Kanban::where('tenant_id', $tenant->id)->where('tipo', 'vendas')->firstOrFail();
        $canal  = WhatsappCanal::factory()->create(['tenant_id' => $tenant->id, 'status' => 'connected']);
        $kanban->canais()->attach($canal->id);

        $response = $this->postJson('/api/secretaria/token-teste-canal', [
            'numero_chamador'  => '11999997777',
            'duracao_segundos' => 0,
        ]);

        $response->assertOk();
        $ticket = TicketAtendimento::withoutGlobalScopes()->where('tenant_id', $tenant->id)->first();
        $this->assertSame($canal->id, $ticket->whatsapp_canal_id);
    }
}
