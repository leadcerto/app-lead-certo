<?php

namespace Tests\Feature;

use App\Models\Contato;
use App\Models\Tenant;
use App\Models\TicketAtendimento;
use App\Models\WhatsappCanal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TicketAtendimentoJanelaConversaTest extends TestCase
{
    use RefreshDatabase;

    public function test_ticket_guarda_janela_de_conversa(): void
    {
        $tenant  = Tenant::factory()->create();
        $canal   = WhatsappCanal::factory()->create(['tenant_id' => $tenant->id, 'tipo' => 'oficial', 'provider' => 'covercut']);
        $contato = Contato::factory()->create();

        $expiraEm = now()->addHours(72);

        $ticket = TicketAtendimento::create([
            'tenant_id' => $tenant->id, 'contato_id' => $contato->id,
            'whatsapp_canal_id' => $canal->id,
            'coluna_kanban' => 'em_atendimento', 'agente_responsavel' => 'bot',
            'status' => 'aberto', 'aberto_em' => now(),
            'janela_expira_em' => $expiraEm,
            'janela_origem_anuncio' => true,
        ]);

        $ticket->refresh();

        $this->assertTrue($ticket->janela_origem_anuncio);
        $this->assertEqualsWithDelta($expiraEm->timestamp, $ticket->janela_expira_em->timestamp, 2);
    }
}
