<?php

namespace Tests\Feature;

use App\Models\Contato;
use App\Models\Tenant;
use App\Models\WhatsappCanal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Espelha UazapiWebhookNomeExtraidoTest — regra de paridade entre canais do
 * CLAUDE.md. Achado real (2026-08-14): a extração progressiva de nome
 * (NomeExtracaoService) vivia só do lado Uazapi; um lead que ligou
 * (Secretária Eletrônica) e se identificou por áudio no canal Oficial nunca
 * tinha o nome capturado, porque o Covercut nunca chamava essa lógica.
 */
class CovercutWebhookNomeExtraidoTest extends TestCase
{
    use RefreshDatabase;

    private function enviarMensagem(string $secret, string $phoneNumberId, string $telefone, string $texto): \Illuminate\Testing\TestResponse
    {
        $payload = [
            'event' => 'message', 'direction' => 'inbound', 'from_number_id' => $phoneNumberId,
            'contact' => ['wa_id' => $telefone],
            'message' => ['id' => 'wamid.' . uniqid(), 'type' => 'text', 'text' => $texto],
        ];
        $body       = json_encode($payload);
        $assinatura = hash_hmac('sha256', $body, $secret);

        return $this->call('POST', '/api/webhook/covercut', [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_X-BSP-Signature' => $assinatura,
        ], $body);
    }

    public function test_expressao_religiosa_nao_e_extraida_como_nome(): void
    {
        Http::fake(['*' => Http::response(['ok' => true], 200)]);

        $tenant = Tenant::factory()->create();
        WhatsappCanal::factory()->create([
            'tenant_id' => $tenant->id, 'tipo' => 'oficial', 'provider' => 'covercut',
            'config' => ['phone_number_id' => '222111', 'webhook_secret' => 'segredo-nome-1'],
        ]);

        $this->enviarMensagem('segredo-nome-1', '222111', '5511911119999', 'boa tarde, Deus é fiel');

        $contato = Contato::where('telefone', '5511911119999')->first();
        $this->assertNotNull($contato);
        $this->assertNotSame('Deus', $contato->nome);
    }

    public function test_saudacao_com_nome_real_continua_funcionando(): void
    {
        Http::fake(['*' => Http::response(['ok' => true], 200)]);

        $tenant = Tenant::factory()->create();
        WhatsappCanal::factory()->create([
            'tenant_id' => $tenant->id, 'tipo' => 'oficial', 'provider' => 'covercut',
            'config' => ['phone_number_id' => '222222', 'webhook_secret' => 'segredo-nome-2'],
        ]);

        $this->enviarMensagem('segredo-nome-2', '222222', '5511922228888', 'Meu nome é Fernanda');

        $contato = Contato::where('telefone', '5511922228888')->first();
        $this->assertNotNull($contato);
        $this->assertSame('Fernanda', $contato->nome);
    }
}
