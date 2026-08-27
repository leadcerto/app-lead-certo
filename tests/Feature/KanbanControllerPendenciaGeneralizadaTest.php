<?php

namespace Tests\Feature;

use App\Models\Contato;
use App\Models\Tenant;
use App\Models\TicketAtendimento;
use App\Models\User;
use App\Models\VinculoContatoTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

class KanbanControllerPendenciaGeneralizadaTest extends TestCase
{
    use RefreshDatabase;

    public function test_show_do_ticket_reflete_pendencia_de_nome_vinda_da_estrutura_nova(): void
    {
        Bus::fake([\App\Jobs\EnriquecerContatoNovoViaGoogleJob::class]);

        $tenant  = Tenant::factory()->create();
        $user    = User::factory()->create(['tenant_id' => $tenant->id, 'perfil' => 'admin']);
        $contato = Contato::factory()->create(['nome' => 'Marcia']);
        VinculoContatoTenant::create([
            'contato_id' => $contato->id, 'tenant_id' => $tenant->id,
            'campos_pendentes_auditoria' => ['nome' => ['sugerido' => 'Marcia Souza', 'origem' => 'google']],
        ]);
        $ticket = TicketAtendimento::create([
            'tenant_id' => $tenant->id, 'contato_id' => $contato->id,
            'coluna_kanban' => 'lead_novo', 'agente_responsavel' => 'bot',
        ]);

        $res = $this->actingAs($user)->getJson("/api/painel/kanban/ticket/{$ticket->id}")->assertOk();

        $this->assertSame('Marcia Souza', $res->json('contato.nome_local'));
        $this->assertTrue($res->json('contato.auditoria_pendente'));
    }
}
