<?php
// tests/Feature/TicketAtendimentoTentativasEnvioFalhasTest.php
namespace Tests\Feature;

use App\Models\Contato;
use App\Models\Tenant;
use App\Models\TicketAtendimento;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TicketAtendimentoTentativasEnvioFalhasTest extends TestCase
{
    use RefreshDatabase;

    public function test_tentativas_envio_falhas_comeca_em_zero(): void
    {
        $tenant  = Tenant::factory()->create();
        $contato = Contato::factory()->create();
        $ticket  = TicketAtendimento::create([
            'tenant_id' => $tenant->id, 'contato_id' => $contato->id,
            'coluna_kanban' => 'lead_novo', 'agente_responsavel' => 'bot',
            'status' => 'aberto', 'aberto_em' => now(),
        ]);

        $this->assertSame(0, $ticket->fresh()->tentativas_envio_falhas);
    }

    public function test_tentativas_envio_falhas_e_mass_assignable(): void
    {
        $tenant  = Tenant::factory()->create();
        $contato = Contato::factory()->create();
        $ticket  = TicketAtendimento::create([
            'tenant_id' => $tenant->id, 'contato_id' => $contato->id,
            'coluna_kanban' => 'lead_novo', 'agente_responsavel' => 'bot',
            'status' => 'aberto', 'aberto_em' => now(),
            'tentativas_envio_falhas' => 2,
        ]);

        $this->assertSame(2, $ticket->fresh()->tentativas_envio_falhas);
    }
}
