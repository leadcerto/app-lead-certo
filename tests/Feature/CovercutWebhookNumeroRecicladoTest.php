<?php

namespace Tests\Feature;

use App\Models\Contato;
use App\Models\Tenant;
use App\Models\WhatsappCanal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Espelha UazapiWebhookNumeroRecicladoTest — regra de paridade entre canais.
 * Achado real (2026-09-16/17, ticket #4788, contato #93802 "Daniela"/Rodrigo):
 * mensagem nova pra um telefone que já tem contato cadastrado com nome real
 * diferente não tinha nenhuma proteção contra número reciclado — o lead novo
 * simplesmente herdava a identidade de quem usava o número antes.
 */
class CovercutWebhookNumeroRecicladoTest extends TestCase
{
    use RefreshDatabase;

    private function enviarMensagem(string $secret, string $phoneNumberId, string $telefone, string $texto, ?string $pushName = null): \Illuminate\Testing\TestResponse
    {
        $payload = [
            'event' => 'message', 'direction' => 'inbound', 'from_number_id' => $phoneNumberId,
            'contact' => array_filter(['wa_id' => $telefone, 'name' => $pushName]),
            'message' => ['id' => 'wamid.' . uniqid(), 'type' => 'text', 'text' => $texto],
        ];
        $body       = json_encode($payload);
        $assinatura = hash_hmac('sha256', $body, $secret);

        return $this->call('POST', '/api/webhook/covercut', [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_X-BSP-Signature' => $assinatura,
        ], $body);
    }

    public function test_numero_reciclado_e_flagrado_sem_bloquear_a_conversa(): void
    {
        Http::fake(['*' => Http::response(['ok' => true], 200)]);

        $tenant = Tenant::factory()->create();
        WhatsappCanal::factory()->create([
            'tenant_id' => $tenant->id, 'tipo' => 'oficial', 'provider' => 'covercut',
            'config' => ['phone_number_id' => '333111', 'webhook_secret' => 'segredo-reciclado-1'],
        ]);

        $contato = Contato::factory()->create(['telefone' => '5511933331111', 'nome' => 'Daniela']);

        $this->enviarMensagem('segredo-reciclado-1', '333111', '5511933331111', 'Oi, bom dia!', pushName: 'Rodrigo');

        $contato->refresh();
        $this->assertSame('Daniela', $contato->nome, 'não deve sobrescrever o nome existente silenciosamente');

        $this->assertDatabaseHas('contatos_pendentes', [
            'telefone'             => '5511933331111',
            'contato_existente_id' => $contato->id,
            'tipo_conflito'        => 'numero_possivelmente_reciclado',
            'nome_existente'       => 'Daniela',
        ]);

        // a conversa/ticket segue normal — não é bloqueada pelo flag de auditoria
        $this->assertDatabaseCount('mensagens', 1);
        $this->assertDatabaseHas('tickets_atendimento', ['contato_id' => $contato->id]);
    }
}
