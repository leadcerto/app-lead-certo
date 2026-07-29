<?php

namespace Tests\Feature;

use App\Models\Contato;
use App\Models\Mensagem;
use App\Models\Tenant;
use App\Models\TicketAtendimento;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MensagemProviderMessageIdTest extends TestCase
{
    use RefreshDatabase;

    public function test_mensagem_grava_provider_message_id(): void
    {
        $tenant  = Tenant::factory()->create();
        $contato = Contato::factory()->create();
        $ticket  = TicketAtendimento::create([
            'tenant_id' => $tenant->id, 'contato_id' => $contato->id,
            'coluna_kanban' => 'em_atendimento', 'agente_responsavel' => 'bot',
            'status' => 'aberto', 'aberto_em' => now(),
        ]);

        $mensagem = Mensagem::create([
            'ticket_id' => $ticket->id, 'tenant_id' => $tenant->id,
            'remetente' => 'lead', 'tipo' => 'texto', 'conteudo' => 'oi',
            'provider_message_id' => 'wamid.ABC123',
            'enviado_em' => now(),
        ]);

        $this->assertDatabaseHas('mensagens', ['id' => $mensagem->id, 'provider_message_id' => 'wamid.ABC123']);
    }
}
