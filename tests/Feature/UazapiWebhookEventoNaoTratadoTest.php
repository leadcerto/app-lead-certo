<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\WhatsappCanal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * Investigação em andamento (2026-07-30): não sabíamos se a Uazapi manda algum
 * evento de histórico/backfill ao reconectar após queda de sessão — o default
 * antigo (`default => null`) descartava silenciosamente qualquer EventType além
 * de 'messages'/'connection', sem nenhum log. Este teste cobre o novo
 * comportamento: logar em warning (nível capturado em produção) com o payload
 * completo, pra pegar a próxima ocorrência real.
 */
class UazapiWebhookEventoNaoTratadoTest extends TestCase
{
    use RefreshDatabase;

    public function test_evento_desconhecido_loga_warning_com_payload_completo(): void
    {
        Log::spy();

        $tenant = Tenant::factory()->create();
        $canal  = WhatsappCanal::factory()->create([
            'tenant_id'     => $tenant->id,
            'webhook_token' => 'token-evento-teste',
        ]);

        $response = $this->postJson('/api/webhook/uazapi/token-evento-teste', [
            'EventType' => 'history_sync',
            'data'      => ['algum' => 'dado_desconhecido'],
        ]);

        $response->assertOk();

        Log::shouldHaveReceived('warning')
            ->once()
            ->withArgs(function (string $mensagem, array $contexto) use ($canal) {
                return str_contains($mensagem, 'EventType não tratado')
                    && $contexto['EventType'] === 'history_sync'
                    && $contexto['canal_id'] === $canal->id
                    && $contexto['payload']['data']['algum'] === 'dado_desconhecido';
            });
    }

    public function test_eventos_conhecidos_nao_disparam_o_log_de_nao_tratado(): void
    {
        Log::spy();

        $tenant = Tenant::factory()->create();
        WhatsappCanal::factory()->create([
            'tenant_id'     => $tenant->id,
            'webhook_token' => 'token-evento-conhecido',
        ]);

        $this->postJson('/api/webhook/uazapi/token-evento-conhecido', [
            'EventType' => 'connection',
            'data'      => ['status' => 'open'],
        ])->assertOk();

        Log::shouldNotHaveReceived('warning', fn (string $mensagem) => str_contains($mensagem, 'EventType não tratado'));
    }
}
