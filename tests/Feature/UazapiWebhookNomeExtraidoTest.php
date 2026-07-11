<?php

namespace Tests\Feature;

use App\Models\Contato;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class UazapiWebhookNomeExtraidoTest extends TestCase
{
    use RefreshDatabase;

    private function enviarMensagem(string $webhookToken, string $telefone, string $texto): \Illuminate\Testing\TestResponse
    {
        return $this->postJson("/api/webhook/uazapi/{$webhookToken}", [
            'EventType' => 'messages',
            'message'   => [
                'fromMe'  => false,
                'isGroup' => false,
                'chatid'  => "{$telefone}@s.whatsapp.net",
                'text'    => $texto,
            ],
        ]);
    }

    public function test_expressao_religiosa_nao_e_extraida_como_nome(): void
    {
        Http::fake(['*' => Http::response(['ok' => true], 200)]);

        $tenant = Tenant::factory()->create(['uazapi_webhook_token' => 'wh-nome-1', 'uazapi_instance_token' => 'inst-1']);

        $this->enviarMensagem('wh-nome-1', '5511911112222', 'boa tarde, Deus é fiel');

        $contato = Contato::where('telefone', '5511911112222')->first();
        $this->assertNotNull($contato);
        $this->assertNotSame('Deus', $contato->nome);
    }

    public function test_saudacao_com_nome_real_continua_funcionando(): void
    {
        Http::fake(['*' => Http::response(['ok' => true], 200)]);

        Tenant::factory()->create(['uazapi_webhook_token' => 'wh-nome-2', 'uazapi_instance_token' => 'inst-2']);

        $this->enviarMensagem('wh-nome-2', '5511922223333', 'Meu nome é Fernanda');

        $contato = Contato::where('telefone', '5511922223333')->first();
        $this->assertNotNull($contato);
        $this->assertSame('Fernanda', $contato->nome);
    }
}
