<?php

namespace Tests\Feature;

use App\Models\Contato;
use App\Models\KanbanColunaConfig;
use App\Models\Mensagem;
use App\Models\Tenant;
use App\Models\TicketAtendimento;
use App\Models\WhatsappCanal;
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

    private function criarTenantComCanal(string $webhookToken, string $instanceToken): Tenant
    {
        $tenant = Tenant::factory()->create([
            'uazapi_webhook_token'  => $webhookToken,
            'uazapi_instance_token' => $instanceToken,
        ]);
        WhatsappCanal::factory()->create([
            'tenant_id'     => $tenant->id,
            'webhook_token' => $webhookToken,
            'config'        => ['instance_token' => $instanceToken],
        ]);
        return $tenant;
    }

    /**
     * A partir de 2026-08-15 a IA de visão devolve descrição e itens numa
     * única resposta, separados pelo marcador "ITENS:" (ver
     * MediaProcessorService::separarDescricaoEItens) — antes disso eram duas
     * chamadas independentes, uma só de itens. $itensTexto aqui é só a parte
     * depois do marcador; a descrição narrativa antes dele é irrelevante pros
     * testes deste arquivo (focados em lista_itens).
     */
    private function fakeOpenRouterListaItens(string $itensTexto): void
    {
        Http::fake([
            'openrouter.ai/*' => Http::response([
                'model'   => 'modelo-fake',
                'choices' => [['message' => ['content' => "Descrição da imagem.\n\nITENS:\n{$itensTexto}"]]],
            ], 200),
            '*' => Http::response('not found', 404),
        ]);
    }

    public function test_imagem_com_foco_configurado_gera_lista_de_itens_no_ticket(): void
    {
        $this->fakeOpenRouterListaItens("- Sofá 3 lugares\n- Geladeira duplex\n- 4 caixas médias");

        $tenant = $this->criarTenantComCanal('wh-itens-1', 'inst-itens-1');
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

    /**
     * Achado real 2026-08-20 (Leonardo): 3 bicicletas viraram 9 itens
     * separados na lista ("Bicicleta", "Pedais", "Guidão", "Rodas
     * dianteiras"...) — o modelo estava listando peças/componentes do
     * mesmo objeto como se fossem itens à parte. Confirma que o prompt
     * enviado pro OpenRouter agora instrui explicitamente a tratar cada
     * objeto completo como 1 item só.
     */
    public function test_prompt_de_visao_instrui_a_nao_fragmentar_objeto_em_pecas(): void
    {
        $this->fakeOpenRouterListaItens('- 3 bicicletas');

        $tenant  = $this->criarTenantComCanal('wh-itens-frag', 'inst-itens-frag');
        $contato = Contato::factory()->create(['telefone' => '5511944445555']);
        TicketAtendimento::create([
            'tenant_id' => $tenant->id, 'contato_id' => $contato->id,
            'coluna_kanban' => 'em_atendimento', 'agente_responsavel' => 'bot',
            'status' => 'aberto', 'aberto_em' => now(),
        ]);

        $this->postJson('/api/webhook/uazapi/wh-itens-frag', [
            'EventType' => 'messages',
            'message'   => [
                'fromMe'    => false,
                'isGroup'   => false,
                'chatid'    => '5511944445555@s.whatsapp.net',
                'mediaType' => 'image',
                'messageid' => 'msg-itens-frag',
                'content'   => ['URL' => 'https://mmg.whatsapp.net/v/fake.jpg', 'mimetype' => 'image/jpeg'],
            ],
        ]);

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), 'openrouter.ai')) {
                return false;
            }
            $texto = json_encode($request->data());
            return str_contains($texto, 'nunca liste as pe') || str_contains($texto, 'UM item s');
        });
    }

    public function test_segunda_imagem_acumula_na_lista_existente(): void
    {
        $this->fakeOpenRouterListaItens("- Mesa de jantar");

        $tenant  = $this->criarTenantComCanal('wh-itens-2', 'inst-itens-2');
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

        $tenant  = $this->criarTenantComCanal('wh-itens-3', 'inst-itens-3');
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
