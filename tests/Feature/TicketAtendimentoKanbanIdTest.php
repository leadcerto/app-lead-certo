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

    public function test_migracao_real_preenche_kanban_id_dos_tickets_ja_existentes(): void
    {
        // Dropa a coluna (o RefreshDatabase já rodou a migration real na suíte toda) pra
        // simular o estado "antes da migration" e poder chamar a migration de verdade de
        // novo — não uma cópia da lógica dela. Isso cobre a Review Focus 3 (backfill
        // completo) e 4 (tenant sem Kanban 'vendas' não quebra a migration inteira).
        \Illuminate\Support\Facades\Schema::table('tickets_atendimento', function ($table) {
            $table->dropConstrainedForeignId('kanban_id');
        });

        $tenantComVendas = Tenant::factory()->create();
        $kanban = Kanban::where('tenant_id', $tenantComVendas->id)->where('tipo', 'vendas')->firstOrFail();

        $idComVendas = \Illuminate\Support\Facades\DB::table('tickets_atendimento')->insertGetId([
            'tenant_id'     => $tenantComVendas->id,
            'contato_id'    => \App\Models\Contato::factory()->create()->id,
            'coluna_kanban' => 'lead_novo',
            'status'        => 'aberto',
            'aberto_em'     => now(),
            'created_at'    => now(),
            'updated_at'    => now(),
        ]);

        // Tenant num estado inconsistente (nunca deveria existir, mas é defensivo): sem
        // NENHUM Kanban tipo='vendas' — a migration não pode quebrar por causa dele.
        $tenantSemVendas = Tenant::factory()->create();
        Kanban::where('tenant_id', $tenantSemVendas->id)->where('tipo', 'vendas')->delete();
        $idSemVendas = \Illuminate\Support\Facades\DB::table('tickets_atendimento')->insertGetId([
            'tenant_id'     => $tenantSemVendas->id,
            'contato_id'    => \App\Models\Contato::factory()->create()->id,
            'coluna_kanban' => 'lead_novo',
            'status'        => 'aberto',
            'aberto_em'     => now(),
            'created_at'    => now(),
            'updated_at'    => now(),
        ]);

        // Roda a migration real de novo (o próprio arquivo, não uma cópia da lógica).
        (require base_path('database/migrations/2026_10_08_000001_add_kanban_id_to_tickets_atendimento_table.php'))->up();

        $this->assertSame($kanban->id, TicketAtendimento::find($idComVendas)->kanban_id);
        $this->assertNull(TicketAtendimento::find($idSemVendas)->kanban_id);
    }
}
