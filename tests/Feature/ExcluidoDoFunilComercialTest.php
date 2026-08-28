<?php

namespace Tests\Feature;

use App\Models\Contato;
use App\Models\Formulario;
use App\Models\Tenant;
use App\Models\TicketAtendimento;
use App\Models\WhatsappCanal;
use App\Services\FormularioService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Pedido do Leonardo (2026-08-28): contato marcado numa etiqueta do Google
 * que não é "lead" (Contato::excluidoDoFunilComercial()) não entra no funil
 * comercial — nenhum dos 6 pontos que criam ticket novo pode abrir um pra
 * ele. Um teste por ponto.
 */
class ExcluidoDoFunilComercialTest extends TestCase
{
    use RefreshDatabase;

    public function test_secretaria_eletronica_nao_cria_ticket_pra_contato_excluido(): void
    {
        Queue::fake();

        $tenant  = Tenant::factory()->create(['secretaria_token' => 'token-excluido']);
        Contato::factory()->create(['telefone' => '5511999998888', 'tipo_contato' => 'pessoal']);

        $response = $this->postJson('/api/secretaria/token-excluido', [
            'numero_chamador'  => '11999998888',
            'duracao_segundos' => 0,
        ]);

        $response->assertOk();
        $response->assertJson(['acao' => 'contato_fora_do_funil_comercial']);
        $this->assertSame(0, TicketAtendimento::where('tenant_id', $tenant->id)->count());
    }

    public function test_internal_ticket_controller_nao_cria_ticket_pra_contato_excluido(): void
    {
        config(['app.service_key' => 'chave-de-teste']);

        $tenant  = Tenant::factory()->create();
        $contato = Contato::factory()->create(['tipo_contato' => 'fornecedor']);

        $response = $this->postJson('/api/internal/ticket', [
            'contato_id' => $contato->id,
            'tenant_id'  => $tenant->id,
        ], ['X-Service-Key' => 'chave-de-teste']);

        $response->assertOk();
        $response->assertJson(['excluido' => true, 'ticket_id' => null]);
        $this->assertSame(0, TicketAtendimento::withoutGlobalScopes()->where('contato_id', $contato->id)->count());
    }

    public function test_uazapi_webhook_mensagem_de_lead_novo_nao_cria_ticket_pra_contato_excluido(): void
    {
        Http::fake(['*' => Http::response(['ok' => true], 200)]);

        $tenant = Tenant::factory()->create([
            'uazapi_webhook_token'  => 'wh-excluido-1',
            'uazapi_instance_token' => 'instance-excluido-1',
        ]);
        WhatsappCanal::factory()->create([
            'tenant_id'     => $tenant->id,
            'webhook_token' => 'wh-excluido-1',
            'config'        => ['instance_token' => 'instance-excluido-1'],
        ]);
        Contato::factory()->create(['telefone' => '5511911112222', 'tipo_contato' => 'fornecedor']);

        $response = $this->postJson('/api/webhook/uazapi/wh-excluido-1', [
            'EventType' => 'messages',
            'message'   => [
                'fromMe'  => false,
                'isGroup' => false,
                'chatid'  => '5511911112222@s.whatsapp.net',
                'text'    => 'Oi',
            ],
        ]);

        $response->assertOk();
        $contato = Contato::where('telefone', '5511911112222')->first();
        $this->assertSame(0, TicketAtendimento::withoutGlobalScopes()->where('contato_id', $contato->id)->count());
    }

    public function test_uazapi_webhook_chamada_perdida_nao_cria_ticket_pra_contato_excluido(): void
    {
        Http::fake(['*' => Http::response(['ok' => true], 200)]);

        $tenant = Tenant::factory()->create([
            'uazapi_webhook_token'  => 'wh-excluido-2',
            'uazapi_instance_token' => 'instance-excluido-2',
        ]);
        WhatsappCanal::factory()->create([
            'tenant_id'     => $tenant->id,
            'webhook_token' => 'wh-excluido-2',
            'config'        => ['instance_token' => 'instance-excluido-2'],
        ]);
        Contato::factory()->create(['telefone' => '5511933334444', 'tipo_contato' => 'pessoal']);

        $this->postJson('/api/webhook/uazapi/wh-excluido-2', [
            'EventType' => 'messages',
            'message'   => [
                'fromMe'      => false,
                'isGroup'     => false,
                'chatid'      => '5511933334444@s.whatsapp.net',
                'messageType' => 'call_log',
            ],
        ]);

        $contato = Contato::where('telefone', '5511933334444')->first();
        $this->assertSame(0, TicketAtendimento::withoutGlobalScopes()->where('contato_id', $contato->id)->count());
    }

    public function test_covercut_webhook_nao_cria_ticket_pra_contato_excluido(): void
    {
        Bus::fake();

        $tenant = Tenant::factory()->create();
        WhatsappCanal::factory()->create([
            'tenant_id' => $tenant->id, 'tipo' => 'oficial', 'provider' => 'covercut',
            'config' => ['phone_number_id' => '950147584848138', 'webhook_secret' => 'segredo-excluido'],
        ]);
        Contato::factory()->create(['telefone' => '5521988887777', 'tipo_contato' => 'cliente']);

        $payload = [
            'event' => 'message', 'direction' => 'inbound', 'from_number_id' => '950147584848138',
            'contact' => ['wa_id' => '5521988887777', 'name' => 'Sandro'],
            'message' => ['id' => 'wamid.excluido1', 'type' => 'text', 'text' => 'Ola'],
        ];
        $body       = json_encode($payload);
        $assinatura = hash_hmac('sha256', $body, 'segredo-excluido');

        $response = $this->call('POST', '/api/webhook/covercut', [], [], [], [
            'CONTENT_TYPE'         => 'application/json',
            'HTTP_X-BSP-Signature' => $assinatura,
        ], $body);

        $response->assertOk();
        $contato = Contato::where('telefone', '5521988887777')->first();
        $this->assertSame(0, TicketAtendimento::withoutGlobalScopes()->where('contato_id', $contato->id)->count());
    }

    public function test_formulario_nao_cria_ticket_pra_contato_excluido(): void
    {
        Bus::fake();

        $tenant     = Tenant::factory()->create();
        $formulario = Formulario::create([
            'tenant_id' => $tenant->id, 'uuid' => 'form-excluido-1',
            'nome' => 'Formulário de teste', 'ativo' => true,
        ]);
        Contato::factory()->create(['telefone' => '5521999997777', 'tipo_contato' => 'parceiro']);

        $resultado = app(FormularioService::class)->processar($formulario, [
            'telefone' => '21999997777',
        ], 'teste.com.br');

        $this->assertTrue($resultado['ok']);
        $this->assertSame('contato_fora_do_funil_comercial', $resultado['acao']);
        $contato = Contato::where('telefone', '5521999997777')->first();
        $this->assertSame(0, TicketAtendimento::withoutGlobalScopes()->where('contato_id', $contato->id)->count());
    }
}
