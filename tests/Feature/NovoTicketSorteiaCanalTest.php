<?php

namespace Tests\Feature;

use App\Models\Kanban;
use App\Models\Tenant;
use App\Models\WhatsappCanal;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
        $contato = \App\Models\Contato::factory()->create();

        config(['app.service_key' => 'chave-de-teste']);

        $response = $this->postJson('/api/internal/ticket', [
            'contato_id' => $contato->id,
            'tenant_id'  => $tenant->id,
        ], ['X-Service-Key' => 'chave-de-teste']);

        $response->assertOk();
        $ticket = \App\Models\TicketAtendimento::withoutGlobalScopes()->where('tenant_id', $tenant->id)->first();
        $this->assertSame($canal->id, $ticket->whatsapp_canal_id);
    }
}
