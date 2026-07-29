<?php

namespace Tests\Feature;

use App\Models\Contato;
use App\Models\Tenant;
use App\Models\TicketAtendimento;
use App\Models\WhatsappCanal;
use App\Services\Canais\CovercutChannelService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class CovercutChannelServiceTest extends TestCase
{
    use RefreshDatabase;

    private function canalOficial(int $tenantId): WhatsappCanal
    {
        return WhatsappCanal::factory()->create([
            'tenant_id' => $tenantId, 'tipo' => 'oficial', 'provider' => 'covercut',
            'config' => ['phone_number_id' => '123456', 'webhook_secret' => 'segredo'],
        ]);
    }

    public function test_envia_texto_via_covercut_dentro_da_janela(): void
    {
        Http::fake(['*/messages/send' => Http::response(['id' => 'wamid.xyz'], 200)]);

        $tenant  = Tenant::factory()->create();
        $canal   = $this->canalOficial($tenant->id);
        $contato = Contato::factory()->create(['telefone' => '5511999999999']);
        TicketAtendimento::create([
            'tenant_id' => $tenant->id, 'contato_id' => $contato->id,
            'whatsapp_canal_id' => $canal->id,
            'coluna_kanban' => 'em_atendimento', 'agente_responsavel' => 'bot',
            'status' => 'aberto', 'aberto_em' => now(),
            'janela_expira_em' => now()->addHours(10),
        ]);

        $enviado = app(CovercutChannelService::class)->enviarTexto($canal, '5511999999999', 'Oi!');

        $this->assertTrue($enviado);
        Http::assertSent(function ($request) {
            return str_ends_with($request->url(), '/messages/send')
                && $request->hasHeader('X-API-Key', config('services.covercut.api_key') ?? '')
                && $request['to'] === '5511999999999'
                && $request['text']['body'] === 'Oi!';
        });
    }

    public function test_bloqueia_envio_fora_da_janela(): void
    {
        Http::fake(); // nenhuma chamada HTTP deve acontecer
        Log::spy();

        $tenant  = Tenant::factory()->create();
        $canal   = $this->canalOficial($tenant->id);
        $contato = Contato::factory()->create(['telefone' => '5511988888888']);
        TicketAtendimento::create([
            'tenant_id' => $tenant->id, 'contato_id' => $contato->id,
            'whatsapp_canal_id' => $canal->id,
            'coluna_kanban' => 'em_atendimento', 'agente_responsavel' => 'bot',
            'status' => 'aberto', 'aberto_em' => now(),
            'janela_expira_em' => now()->subHour(), // já expirou
        ]);

        $enviado = app(CovercutChannelService::class)->enviarTexto($canal, '5511988888888', 'Oi!');

        $this->assertFalse($enviado);
        Http::assertNothingSent();
        Log::shouldHaveReceived('warning')->once();
    }

    public function test_envia_normalmente_quando_nao_ha_ticket_para_o_telefone(): void
    {
        // Sem ticket em aberto para este telefone neste canal não há janela pra checar
        // (ex: primeiro contato antes de qualquer ticket existir) — não bloqueia;
        // a Covercut também respeita a janela do lado dela.
        Http::fake(['*/messages/send' => Http::response(['id' => 'wamid.abc'], 200)]);

        $tenant = Tenant::factory()->create();
        $canal  = $this->canalOficial($tenant->id);

        $enviado = app(CovercutChannelService::class)->enviarTexto($canal, '5511977777777', 'Oi!');

        $this->assertTrue($enviado);
    }
}
