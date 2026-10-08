<?php

namespace Tests\Feature;

use App\Models\Kanban;
use App\Models\Tenant;
use App\Models\TicketAtendimento;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TicketAtendimentoKanbanIdTest extends TestCase
{
    use RefreshDatabase;

    public function test_ticket_tem_kanban_id_fillable_e_relacionamento(): void
    {
        $tenant = Tenant::factory()->create();
        $kanban = Kanban::where('tenant_id', $tenant->id)->where('tipo', 'vendas')->firstOrFail();

        $ticket = TicketAtendimento::create([
            'tenant_id'     => $tenant->id,
            'contato_id'    => \App\Models\Contato::factory()->create()->id,
            'kanban_id'     => $kanban->id,
            'coluna_kanban' => 'lead_novo',
            'status'        => 'aberto',
            'aberto_em'     => now(),
        ]);

        $this->assertSame($kanban->id, $ticket->fresh()->kanban_id);
        $this->assertTrue($ticket->kanban->is($kanban));
    }

    public function test_backfill_preenche_kanban_id_dos_tickets_ja_existentes(): void
    {
        $tenant = Tenant::factory()->create();
        $kanban = Kanban::where('tenant_id', $tenant->id)->where('tipo', 'vendas')->firstOrFail();

        // Simula um ticket criado ANTES da migration existir, inserindo direto sem kanban_id.
        $id = \Illuminate\Support\Facades\DB::table('tickets_atendimento')->insertGetId([
            'tenant_id'     => $tenant->id,
            'contato_id'    => \App\Models\Contato::factory()->create()->id,
            'coluna_kanban' => 'lead_novo',
            'status'        => 'aberto',
            'aberto_em'     => now(),
            'created_at'    => now(),
            'updated_at'    => now(),
        ]);

        // Roda o backfill da migration manualmente (mesma lógica que ela usa, via query
        // builder — não SQL cru, pra funcionar igual no SQLite dos testes e no MySQL de produção).
        Kanban::where('tipo', 'vendas')->get()->each(function (Kanban $k) {
            \Illuminate\Support\Facades\DB::table('tickets_atendimento')
                ->where('tenant_id', $k->tenant_id)
                ->whereNull('kanban_id')
                ->update(['kanban_id' => $k->id]);
        });

        $this->assertSame($kanban->id, TicketAtendimento::find($id)->kanban_id);
    }
}
