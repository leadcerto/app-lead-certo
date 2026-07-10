<?php

namespace Tests\Feature;

use App\Models\Contato;
use App\Models\KanbanColunaConfig;
use App\Models\Tenant;
use App\Models\TicketAtendimento;
use App\Services\KanbanBotaoActionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class KanbanBotaoActionServiceEnviarBotoesTest extends TestCase
{
    use RefreshDatabase;

    public function test_monta_e_envia_o_menu_com_formato_correto_por_tipo_de_botao(): void
    {
        Http::fake(['*/send/menu' => Http::response(['id' => 'msg1'], 200)]);

        $tenant  = Tenant::factory()->create(['uazapi_instance_token' => 'tok']);
        $contato = Contato::factory()->create(['telefone' => '5511999999999']);
        $ticket  = TicketAtendimento::create([
            'tenant_id' => $tenant->id, 'contato_id' => $contato->id,
            'coluna_kanban' => 'aguardando_lead', 'agente_responsavel' => 'bot',
            'status' => 'aberto', 'aberto_em' => now(),
        ]);
        KanbanColunaConfig::create([
            'tenant_id' => $tenant->id, 'coluna_kanban' => 'aguardando_lead',
            'objetivo' => 'Escolha uma opção',
            'button_settings' => [
                ['text' => 'Ver Site', 'action' => 'open_url', 'target' => 'https://exemplo.com'],
                ['text' => 'Falar com Humano', 'action' => 'move_column', 'target' => 'em_atendimento'],
            ],
        ]);

        $ok = app(KanbanBotaoActionService::class)->enviarBotoesDaColuna($ticket);

        $this->assertTrue($ok);
        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/send/menu')
                && $request['choices'] === ['Ver Site|https://exemplo.com', 'Falar com Humano|move_column:1'];
        });
    }

    public function test_retorna_false_sem_button_settings_configurado(): void
    {
        $tenant  = Tenant::factory()->create(['uazapi_instance_token' => 'tok']);
        $contato = Contato::factory()->create(['telefone' => '5511999999999']);
        $ticket  = TicketAtendimento::create([
            'tenant_id' => $tenant->id, 'contato_id' => $contato->id,
            'coluna_kanban' => 'lead_novo', 'agente_responsavel' => 'bot',
            'status' => 'aberto', 'aberto_em' => now(),
        ]);

        $ok = app(KanbanBotaoActionService::class)->enviarBotoesDaColuna($ticket);

        $this->assertFalse($ok);
    }
}
