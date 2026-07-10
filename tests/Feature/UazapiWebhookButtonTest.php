<?php

namespace Tests\Feature;

use App\Models\Contato;
use App\Models\KanbanColunaConfig;
use App\Models\Tenant;
use App\Models\TicketAtendimento;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class UazapiWebhookButtonTest extends TestCase
{
    use RefreshDatabase;

    public function test_clique_no_botao_move_o_ticket_e_nao_processa_como_texto(): void
    {
        Http::fake(['*' => Http::response(['ok' => true], 200)]);

        $tenant = Tenant::factory()->create([
            'uazapi_webhook_token'   => 'wh-token-123',
            'uazapi_instance_token'  => 'instance-token-123',
        ]);
        $contato = Contato::factory()->create(['telefone' => '5511999999999']);
        $ticket = TicketAtendimento::create([
            'tenant_id' => $tenant->id, 'contato_id' => $contato->id,
            'coluna_kanban' => 'aguardando_lead', 'agente_responsavel' => 'bot',
            'status' => 'aberto', 'aberto_em' => now(),
        ]);
        KanbanColunaConfig::create([
            'tenant_id' => $tenant->id, 'coluna_kanban' => 'aguardando_lead',
            'button_settings' => [
                ['text' => 'Falar com Humano', 'action' => 'move_column', 'target' => 'em_atendimento'],
            ],
        ]);

        $response = $this->postJson('/api/webhook/uazapi/wh-token-123', [
            'EventType' => 'messages',
            'message'   => [
                'fromMe'        => false,
                'isGroup'       => false,
                'chatid'        => '5511999999999@s.whatsapp.net',
                'buttonOrListid' => 'move_column:0',
                'text'          => 'Falar com Humano',
                'messageType'   => 'buttonsResponseMessage',
            ],
        ]);

        $response->assertOk();
        $this->assertSame('em_atendimento', $ticket->fresh()->coluna_kanban);
    }

    public function test_mensagem_sem_buttonorlistid_continua_fluxo_normal_de_texto(): void
    {
        Http::fake(['*' => Http::response(['ok' => true], 200)]);

        $tenant = Tenant::factory()->create([
            'uazapi_webhook_token'   => 'wh-token-456',
            'uazapi_instance_token'  => 'instance-token-456',
        ]);

        $response = $this->postJson('/api/webhook/uazapi/wh-token-456', [
            'EventType' => 'messages',
            'message'   => [
                'fromMe'  => false,
                'isGroup' => false,
                'chatid'  => '5511988888888@s.whatsapp.net',
                'text'    => 'Oi, tudo bem?',
            ],
        ]);

        $response->assertOk();
        $this->assertDatabaseHas('contatos', ['telefone' => '5511988888888']);
    }
}
