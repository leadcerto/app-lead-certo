<?php

namespace Tests\Feature;

use App\Models\Contato;
use App\Models\Kanban;
use App\Models\Tenant;
use App\Models\TicketAtendimento;
use App\Models\WhatsappCanal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RepararTicketsSemCanalCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_vincula_canal_oficial_a_ticket_sem_canal_quando_nao_ha_canal_nao_oficial(): void
    {
        $tenant = Tenant::factory()->create();
        $kanban = Kanban::where('tenant_id', $tenant->id)->where('tipo', 'vendas')->firstOrFail();

        $oficial = WhatsappCanal::factory()->create(['tenant_id' => $tenant->id, 'tipo' => 'oficial', 'status' => 'connected']);
        $kanban->canais()->attach([$oficial->id]);

        $contato = Contato::factory()->create();
        $ticket  = TicketAtendimento::create([
            'tenant_id' => $tenant->id, 'contato_id' => $contato->id,
            'coluna_kanban' => 'lead_novo', 'agente_responsavel' => 'humano',
            'status' => 'aberto', 'aberto_em' => now(), 'origem' => 'ligacao',
        ]);

        $this->artisan('whatsapp:reparar-tickets-sem-canal')->assertExitCode(0);

        $this->assertSame($oficial->id, $ticket->fresh()->whatsapp_canal_id);
    }

    public function test_dry_run_nao_altera_nada(): void
    {
        $tenant = Tenant::factory()->create();
        $kanban = Kanban::where('tenant_id', $tenant->id)->where('tipo', 'vendas')->firstOrFail();

        $oficial = WhatsappCanal::factory()->create(['tenant_id' => $tenant->id, 'tipo' => 'oficial', 'status' => 'connected']);
        $kanban->canais()->attach([$oficial->id]);

        $contato = Contato::factory()->create();
        $ticket  = TicketAtendimento::create([
            'tenant_id' => $tenant->id, 'contato_id' => $contato->id,
            'coluna_kanban' => 'lead_novo', 'agente_responsavel' => 'humano',
            'status' => 'aberto', 'aberto_em' => now(), 'origem' => 'ligacao',
        ]);

        $this->artisan('whatsapp:reparar-tickets-sem-canal --dry-run')->assertExitCode(0);

        $this->assertNull($ticket->fresh()->whatsapp_canal_id);
    }

    public function test_ticket_ja_com_canal_nao_e_alterado(): void
    {
        $tenant = Tenant::factory()->create();
        $kanban = Kanban::where('tenant_id', $tenant->id)->where('tipo', 'vendas')->firstOrFail();

        $original = WhatsappCanal::factory()->create(['tenant_id' => $tenant->id, 'tipo' => 'nao_oficial', 'status' => 'connected']);
        $outro    = WhatsappCanal::factory()->create(['tenant_id' => $tenant->id, 'tipo' => 'oficial', 'status' => 'connected']);
        $kanban->canais()->attach([$original->id, $outro->id]);

        $contato = Contato::factory()->create();
        $ticket  = TicketAtendimento::create([
            'tenant_id' => $tenant->id, 'contato_id' => $contato->id,
            'whatsapp_canal_id' => $original->id,
            'coluna_kanban' => 'lead_novo', 'agente_responsavel' => 'humano',
            'status' => 'aberto', 'aberto_em' => now(),
        ]);

        $this->artisan('whatsapp:reparar-tickets-sem-canal')->assertExitCode(0);

        $this->assertSame($original->id, $ticket->fresh()->whatsapp_canal_id);
    }

    public function test_ticket_encerrado_sem_canal_nao_e_alterado(): void
    {
        $tenant = Tenant::factory()->create();
        $kanban = Kanban::where('tenant_id', $tenant->id)->where('tipo', 'vendas')->firstOrFail();

        $oficial = WhatsappCanal::factory()->create(['tenant_id' => $tenant->id, 'tipo' => 'oficial', 'status' => 'connected']);
        $kanban->canais()->attach([$oficial->id]);

        $contato = Contato::factory()->create();
        $ticket  = TicketAtendimento::create([
            'tenant_id' => $tenant->id, 'contato_id' => $contato->id,
            'coluna_kanban' => 'encerrado', 'agente_responsavel' => 'humano',
            'status' => 'encerrado', 'aberto_em' => now(),
        ]);

        $this->artisan('whatsapp:reparar-tickets-sem-canal')->assertExitCode(0);

        $this->assertNull($ticket->fresh()->whatsapp_canal_id);
    }
}
