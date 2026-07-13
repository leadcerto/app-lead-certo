<?php

namespace Tests\Feature;

use App\Models\Contato;
use App\Models\KanbanColunaConfig;
use App\Models\Mensagem;
use App\Models\Tenant;
use App\Models\TicketAtendimento;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ListaItensImagemTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        config(['services.openrouter.key' => 'fake-openrouter-key']);
    }

    private function fakeOpenRouterListaItens(string $texto): void
    {
        Http::fake([
            'openrouter.ai/*' => Http::response([
                'model'   => 'modelo-fake',
                'choices' => [['message' => ['content' => $texto]]],
            ], 200),
            '*' => Http::response('not found', 404),
        ]);
    }

    public function test_imagem_com_foco_configurado_gera_lista_de_itens_no_ticket(): void
    {
        $this->fakeOpenRouterListaItens("- Sofá 3 lugares\n- Geladeira duplex\n- 4 caixas médias");

        $tenant = Tenant::factory()->create(['uazapi_webhook_token' => 'wh-itens-1', 'uazapi_instance_token' => 'inst-itens-1']);
        KanbanColunaConfig::create([
            'tenant_id'           => $tenant->id,
            'coluna_kanban'       => 'em_atendimento',
            'foco_analise_imagem' => 'móveis e volumes de mudança',
        ]);
        $contato = Contato::factory()->create(['telefone' => '5511911112222']);
        $ticket  = TicketAtendimento::create([
            'tenant_id' => $tenant->id, 'contato_id' => $contato->id,
            'coluna_kanban' => 'em_atendimento', 'agente_responsavel' => 'bot',
            'status' => 'aberto', 'aberto_em' => now(),
        ]);

        $this->postJson('/api/webhook/uazapi/wh-itens-1', [
            'EventType' => 'messages',
            'message'   => [
                'fromMe'    => false,
                'isGroup'   => false,
                'chatid'    => '5511911112222@s.whatsapp.net',
                'mediaType' => 'image',
                'messageid' => 'msg-itens-1',
                'content'   => ['URL' => 'https://mmg.whatsapp.net/v/fake.jpg', 'mimetype' => 'image/jpeg'],
            ],
        ]);

        $ticket->refresh();
        $this->assertNotNull($ticket->lista_itens);
        $this->assertStringContainsString('Sofá 3 lugares', $ticket->lista_itens);
    }

    public function test_segunda_imagem_acumula_na_lista_existente(): void
    {
        $this->fakeOpenRouterListaItens("- Mesa de jantar");

        $tenant  = Tenant::factory()->create(['uazapi_webhook_token' => 'wh-itens-2', 'uazapi_instance_token' => 'inst-itens-2']);
        $contato = Contato::factory()->create(['telefone' => '5511922223333']);
        $ticket  = TicketAtendimento::create([
            'tenant_id' => $tenant->id, 'contato_id' => $contato->id,
            'coluna_kanban' => 'em_atendimento', 'agente_responsavel' => 'bot',
            'status' => 'aberto', 'aberto_em' => now(),
            'lista_itens' => '- Sofá 3 lugares',
        ]);

        $this->postJson('/api/webhook/uazapi/wh-itens-2', [
            'EventType' => 'messages',
            'message'   => [
                'fromMe'    => false,
                'isGroup'   => false,
                'chatid'    => '5511922223333@s.whatsapp.net',
                'mediaType' => 'image',
                'messageid' => 'msg-itens-2',
                'content'   => ['URL' => 'https://mmg.whatsapp.net/v/fake.jpg', 'mimetype' => 'image/jpeg'],
            ],
        ]);

        $ticket->refresh();
        $this->assertStringContainsString('Sofá 3 lugares', $ticket->lista_itens);
        $this->assertStringContainsString('Mesa de jantar', $ticket->lista_itens);
    }

    public function test_resposta_nada_identificado_nao_e_adicionada_a_lista(): void
    {
        $this->fakeOpenRouterListaItens('Nada identificado');

        $tenant  = Tenant::factory()->create(['uazapi_webhook_token' => 'wh-itens-3', 'uazapi_instance_token' => 'inst-itens-3']);
        $contato = Contato::factory()->create(['telefone' => '5511933334444']);
        $ticket  = TicketAtendimento::create([
            'tenant_id' => $tenant->id, 'contato_id' => $contato->id,
            'coluna_kanban' => 'em_atendimento', 'agente_responsavel' => 'bot',
            'status' => 'aberto', 'aberto_em' => now(),
        ]);

        $this->postJson('/api/webhook/uazapi/wh-itens-3', [
            'EventType' => 'messages',
            'message'   => [
                'fromMe'    => false,
                'isGroup'   => false,
                'chatid'    => '5511933334444@s.whatsapp.net',
                'mediaType' => 'image',
                'messageid' => 'msg-itens-3',
                'content'   => ['URL' => 'https://mmg.whatsapp.net/v/fake.jpg', 'mimetype' => 'image/jpeg'],
            ],
        ]);

        $this->assertNull($ticket->fresh()->lista_itens);
    }
}
