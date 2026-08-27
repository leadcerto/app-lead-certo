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

    public function test_retorna_false_sem_lancar_excecao_em_falha_de_conexao(): void
    {
        Http::fake(fn () => throw new \Illuminate\Http\Client\ConnectionException('timeout'));
        Log::spy();

        $tenant = Tenant::factory()->create();
        $canal  = $this->canalOficial($tenant->id);

        $enviado = app(CovercutChannelService::class)->enviarTexto($canal, '5511977777777', 'Oi!');

        $this->assertFalse($enviado);
        Log::shouldHaveReceived('warning')->once();
    }

    public function test_envia_imagem_via_covercut_com_legenda(): void
    {
        Http::fake(['*/messages/send' => Http::response(['id' => 'wamid.img'], 200)]);

        $tenant = Tenant::factory()->create();
        $canal  = $this->canalOficial($tenant->id);

        $enviado = app(CovercutChannelService::class)->enviarImagem($canal, '5511999999999', 'https://app.leadcerto.app.br/storage/foto.jpg', 'legenda');

        $this->assertTrue($enviado);
        Http::assertSent(fn ($request) =>
            $request['type'] === 'image'
            && $request['image']['link'] === 'https://app.leadcerto.app.br/storage/foto.jpg'
            && $request['image']['caption'] === 'legenda'
        );
    }

    public function test_envia_audio_ogg_como_nota_de_voz(): void
    {
        Http::fake(['*/messages/send' => Http::response(['id' => 'wamid.audio'], 200)]);

        $tenant = Tenant::factory()->create();
        $canal  = $this->canalOficial($tenant->id);

        $enviado = app(CovercutChannelService::class)->enviarAudio($canal, '5511999999999', 'https://app.leadcerto.app.br/storage/audio.ogg');

        $this->assertTrue($enviado);
        Http::assertSent(fn ($request) =>
            $request['type'] === 'audio'
            && $request['audio']['link'] === 'https://app.leadcerto.app.br/storage/audio.ogg'
            && $request['audio']['voice'] === true
        );
    }

    public function test_envia_audio_ogg_com_query_string_como_nota_de_voz(): void
    {
        Http::fake(['*/messages/send' => Http::response(['id' => 'wamid.audio'], 200)]);

        $tenant = Tenant::factory()->create();
        $canal  = $this->canalOficial($tenant->id);

        $enviado = app(CovercutChannelService::class)->enviarAudio($canal, '5511999999999', 'https://app.leadcerto.app.br/storage/audio.ogg?token=abc123');

        $this->assertTrue($enviado);
        Http::assertSent(fn ($request) =>
            $request['type'] === 'audio'
            && $request['audio']['link'] === 'https://app.leadcerto.app.br/storage/audio.ogg?token=abc123'
            && $request['audio']['voice'] === true
        );
    }

    public function test_envia_audio_mp3_sem_marcar_como_nota_de_voz(): void
    {
        Http::fake(['*/messages/send' => Http::response(['id' => 'wamid.audio'], 200)]);

        $tenant = Tenant::factory()->create();
        $canal  = $this->canalOficial($tenant->id);

        $enviado = app(CovercutChannelService::class)->enviarAudio($canal, '5511999999999', 'https://app.leadcerto.app.br/storage/audio.mp3');

        $this->assertTrue($enviado);
        Http::assertSent(fn ($request) =>
            $request['type'] === 'audio'
            && ! isset($request['audio']['voice'])
        );
    }

    public function test_envia_documento_com_nome_de_arquivo(): void
    {
        Http::fake(['*/messages/send' => Http::response(['id' => 'wamid.doc'], 200)]);

        $tenant = Tenant::factory()->create();
        $canal  = $this->canalOficial($tenant->id);

        $enviado = app(CovercutChannelService::class)->enviarDocumento($canal, '5511999999999', 'https://app.leadcerto.app.br/storage/arquivo.pdf', 'boleto.pdf');

        $this->assertTrue($enviado);
        Http::assertSent(fn ($request) =>
            $request['type'] === 'document'
            && $request['document']['link'] === 'https://app.leadcerto.app.br/storage/arquivo.pdf'
            && $request['document']['filename'] === 'boleto.pdf'
        );
    }

    public function test_envia_sticker_via_covercut(): void
    {
        Http::fake(['*/messages/send' => Http::response(['id' => 'wamid.sticker'], 200)]);

        $tenant = Tenant::factory()->create();
        $canal  = $this->canalOficial($tenant->id);

        $enviado = app(CovercutChannelService::class)->enviarSticker($canal, '5511999999999', 'https://app.leadcerto.app.br/storage/fig.webp');

        $this->assertTrue($enviado);
        Http::assertSent(fn ($request) =>
            $request['type'] === 'sticker'
            && $request['sticker']['link'] === 'https://app.leadcerto.app.br/storage/fig.webp'
        );
    }

    public function test_bloqueia_envio_de_imagem_fora_da_janela(): void
    {
        Http::fake();
        Log::spy();

        $tenant  = Tenant::factory()->create();
        $canal   = $this->canalOficial($tenant->id);
        $contato = Contato::factory()->create(['telefone' => '5511988888888']);
        TicketAtendimento::create([
            'tenant_id' => $tenant->id, 'contato_id' => $contato->id,
            'whatsapp_canal_id' => $canal->id,
            'coluna_kanban' => 'em_atendimento', 'agente_responsavel' => 'bot',
            'status' => 'aberto', 'aberto_em' => now(),
            'janela_expira_em' => now()->subHour(),
        ]);

        $enviado = app(CovercutChannelService::class)->enviarImagem($canal, '5511988888888', 'https://app.leadcerto.app.br/storage/foto.jpg');

        $this->assertFalse($enviado);
        Http::assertNothingSent();
    }

    // ── Número sem WhatsApp (2026-08-26) ────────────────────────────────────

    public function test_detecta_numero_sem_whatsapp_pelo_codigo_de_erro_da_meta(): void
    {
        Http::fake(['*/messages/send' => Http::response([
            'error' => ['message' => 'Recipient phone number not in allowed list', 'code' => 131026],
        ], 400)]);

        $tenant = Tenant::factory()->create();
        $canal  = $this->canalOficial($tenant->id);
        $servico = app(CovercutChannelService::class);

        $enviado = $servico->enviarTexto($canal, '5511900000001', 'Oi!');

        $this->assertFalse($enviado);
        $this->assertTrue($servico->ultimoEnvioFalhouPorNumeroInvalido());
    }

    public function test_nao_marca_numero_invalido_pra_outros_tipos_de_falha(): void
    {
        Http::fake(['*/messages/send' => Http::response([
            'error' => ['message' => 'Internal server error', 'code' => 500],
        ], 500)]);

        $tenant = Tenant::factory()->create();
        $canal  = $this->canalOficial($tenant->id);
        $servico = app(CovercutChannelService::class);

        $enviado = $servico->enviarTexto($canal, '5511977777777', 'Oi!');

        $this->assertFalse($enviado);
        $this->assertFalse($servico->ultimoEnvioFalhouPorNumeroInvalido());
    }

    public function test_flag_de_numero_invalido_reseta_a_cada_novo_envio(): void
    {
        Http::fakeSequence()
            ->push(['error' => ['code' => 131026]], 400)
            ->push(['id' => 'wamid.ok'], 200);

        $tenant  = Tenant::factory()->create();
        $canal   = $this->canalOficial($tenant->id);
        $servico = app(CovercutChannelService::class);

        $servico->enviarTexto($canal, '5511900000001', 'Oi!');
        $this->assertTrue($servico->ultimoEnvioFalhouPorNumeroInvalido());

        $enviado = $servico->enviarTexto($canal, '5511999999999', 'Oi de novo!');
        $this->assertTrue($enviado);
        $this->assertFalse($servico->ultimoEnvioFalhouPorNumeroInvalido());
    }
}
