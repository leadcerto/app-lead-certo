<?php

namespace Tests\Feature;

use App\Jobs\SdrResponderJob;
use App\Models\Mensagem;
use App\Models\Tenant;
use App\Models\TicketAtendimento;
use App\Models\WhatsappCanal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CovercutWebhookMidiaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        Bus::fake();
        config([
            'services.covercut.base_url'   => 'https://fake-covercut.test/api/v1',
            'services.covercut.api_key'    => 'fake-key',
            'services.covercut.api_secret' => 'fake-secret',
            // Testes de áudio deste arquivo cobrem cenários DEPOIS da checagem de
            // chave (mediaId ausente, falha de download, corpo vazio/inesperado) —
            // sem uma chave fake aqui, phpunit.xml zera GROQ_KEY e todos caem no
            // placeholder "transcrição não configurada" antes de chegar no cenário
            // que cada teste realmente quer exercitar.
            'services.groq.key'            => 'fake-groq-key',
        ]);
    }

    private function postComAssinatura(array $payload, string $segredo)
    {
        $body       = json_encode($payload);
        $assinatura = hash_hmac('sha256', $body, $segredo);

        return $this->call('POST', '/api/webhook/covercut', [], [], [], [
            'CONTENT_TYPE'         => 'application/json',
            'HTTP_X-BSP-Signature' => $assinatura,
        ], $body);
    }

    public function test_audio_recebido_e_transcrito_e_salvo_com_midia_url(): void
    {
        Http::fake([
            '*/media/get*' => Http::response('conteudo-binario-fake-audio', 200, ['Content-Type' => 'audio/ogg']),
        ]);

        $tenant = Tenant::factory()->create();
        WhatsappCanal::factory()->create([
            'tenant_id' => $tenant->id, 'tipo' => 'oficial', 'provider' => 'covercut',
            'config' => ['phone_number_id' => '950147584848138', 'webhook_secret' => 'segredo-abc'],
        ]);

        $payload = [
            'event' => 'message', 'direction' => 'inbound', 'from_number_id' => '950147584848138',
            'contact' => ['wa_id' => '5521988887777', 'name' => 'Sandro'],
            'message' => ['id' => 'wamid.audio1', 'type' => 'audio', 'audio' => ['id' => 'media-audio-1', 'mime_type' => 'audio/ogg']],
        ];

        $this->postComAssinatura($payload, 'segredo-abc')->assertOk();

        $mensagem = Mensagem::where('provider_message_id', 'wamid.audio1')->first();
        $this->assertNotNull($mensagem, 'Mensagem de áudio deveria ter sido criada');
        $this->assertSame('audio', $mensagem->tipo);
        $this->assertNotNull($mensagem->midia_url);
        $this->assertStringContainsString('/storage/kanban-midia/', $mensagem->midia_url);
        $this->assertNotEmpty(Storage::disk('public')->allFiles('kanban-midia'));

        Http::assertSent(fn ($request) =>
            str_contains($request->url(), '/media/get')
            && $request['id'] === 'media-audio-1'
            && $request['from'] === '950147584848138'
            && $request['mode'] === 'stream'
        );
    }

    public function test_audio_sem_id_no_payload_e_tratado_sem_quebrar(): void
    {
        Http::fake(); // não deveria ser chamado

        $tenant = Tenant::factory()->create();
        WhatsappCanal::factory()->create([
            'tenant_id' => $tenant->id, 'tipo' => 'oficial', 'provider' => 'covercut',
            'config' => ['phone_number_id' => '950147584848138', 'webhook_secret' => 'segredo-abc'],
        ]);

        $payload = [
            'event' => 'message', 'direction' => 'inbound', 'from_number_id' => '950147584848138',
            'contact' => ['wa_id' => '5521988887777'],
            'message' => ['id' => 'wamid.audio2', 'type' => 'audio'], // sem 'audio' => ['id' => ...]
        ];

        $response = $this->postComAssinatura($payload, 'segredo-abc');

        $response->assertOk();
        $mensagem = Mensagem::where('provider_message_id', 'wamid.audio2')->first();
        $this->assertNotNull($mensagem);
        $this->assertSame('[Áudio recebido — não foi possível identificar o arquivo]', $mensagem->conteudo);
        Http::assertNothingSent();
    }

    public function test_download_da_covercut_falha_nao_quebra_o_webhook(): void
    {
        Http::fake(['*/media/get*' => Http::response('erro interno', 500)]);

        $tenant = Tenant::factory()->create();
        WhatsappCanal::factory()->create([
            'tenant_id' => $tenant->id, 'tipo' => 'oficial', 'provider' => 'covercut',
            'config' => ['phone_number_id' => '950147584848138', 'webhook_secret' => 'segredo-abc'],
        ]);

        $payload = [
            'event' => 'message', 'direction' => 'inbound', 'from_number_id' => '950147584848138',
            'contact' => ['wa_id' => '5521988887777'],
            'message' => ['id' => 'wamid.audio3', 'type' => 'audio', 'audio' => ['id' => 'media-x']],
        ];

        $response = $this->postComAssinatura($payload, 'segredo-abc');

        $response->assertOk();
        $mensagem = Mensagem::where('provider_message_id', 'wamid.audio3')->first();
        $this->assertNotNull($mensagem);
        $this->assertSame('[Áudio recebido — não foi possível transcrever]', $mensagem->conteudo);
        $this->assertNull($mensagem->midia_url);
    }

    /**
     * Achado Importante 2 da revisão final: baixarMidiaCovercut() só checava
     * successful(), sem validar corpo vazio nem content-type inesperado (a
     * Covercut poderia ignorar mode=stream e devolver o envelope JSON dela).
     * Simula exatamente esse cenário — 200 OK mas corpo vazio — e prova que o
     * fluxo de áudio degrada pro placeholder em vez de tentar transcrever bytes
     * vazios/JSON como se fossem áudio.
     */
    public function test_resposta_de_midia_com_corpo_vazio_degrada_para_placeholder(): void
    {
        Http::fake([
            '*/media/get*' => Http::response('', 200, ['Content-Type' => 'audio/ogg']),
        ]);

        $tenant = Tenant::factory()->create();
        WhatsappCanal::factory()->create([
            'tenant_id' => $tenant->id, 'tipo' => 'oficial', 'provider' => 'covercut',
            'config' => ['phone_number_id' => '950147584848138', 'webhook_secret' => 'segredo-abc'],
        ]);

        $payload = [
            'event' => 'message', 'direction' => 'inbound', 'from_number_id' => '950147584848138',
            'contact' => ['wa_id' => '5521988887777'],
            'message' => ['id' => 'wamid.audio4', 'type' => 'audio', 'audio' => ['id' => 'media-vazio']],
        ];

        $response = $this->postComAssinatura($payload, 'segredo-abc');

        $response->assertOk();
        $mensagem = Mensagem::where('provider_message_id', 'wamid.audio4')->first();
        $this->assertNotNull($mensagem);
        $this->assertSame('[Áudio recebido — não foi possível transcrever]', $mensagem->conteudo);
        $this->assertNull($mensagem->midia_url);
    }

    /**
     * Mesmo achado, mas cobrindo o outro sintoma citado no doc de design: a
     * Covercut ignora mode=stream e devolve o envelope JSON dela em vez do
     * arquivo bruto. Sem a checagem de content-type, esses bytes JSON seriam
     * tratados como se fossem o áudio.
     */
    public function test_resposta_de_midia_em_json_inesperado_degrada_para_placeholder(): void
    {
        Http::fake([
            '*/media/get*' => Http::response(json_encode(['status' => 'ok', 'url' => 'https://exemplo.com/x.ogg']), 200, ['Content-Type' => 'application/json']),
        ]);

        $tenant = Tenant::factory()->create();
        WhatsappCanal::factory()->create([
            'tenant_id' => $tenant->id, 'tipo' => 'oficial', 'provider' => 'covercut',
            'config' => ['phone_number_id' => '950147584848138', 'webhook_secret' => 'segredo-abc'],
        ]);

        $payload = [
            'event' => 'message', 'direction' => 'inbound', 'from_number_id' => '950147584848138',
            'contact' => ['wa_id' => '5521988887777'],
            'message' => ['id' => 'wamid.audio5', 'type' => 'audio', 'audio' => ['id' => 'media-json']],
        ];

        $response = $this->postComAssinatura($payload, 'segredo-abc');

        $response->assertOk();
        $mensagem = Mensagem::where('provider_message_id', 'wamid.audio5')->first();
        $this->assertNotNull($mensagem);
        $this->assertSame('[Áudio recebido — não foi possível transcrever]', $mensagem->conteudo);
        $this->assertNull($mensagem->midia_url);
    }

    /**
     * Achado real 2026-08-15 (ticket 3085, Frete Rio): antes desta correção a
     * mesma imagem era baixada da Covercut até 3x (descrição, persistência,
     * itens) e passava por 2 chamadas de IA separadas — sob volume (2+ imagens
     * seguidas), o provedor free-tier de visão estourava timeout na chamada de
     * itens e o card ficava sem a lista mesmo com a descrição salva certinho.
     * Agora é 1 download + 1 chamada de visão que devolve os dois juntos
     * (separados pelo marcador "ITENS:" no prompt) — o assertSentCount(2) abaixo
     * prova que não duplica mais.
     */
    public function test_imagem_recebida_e_descrita_e_salva_com_midia_url_e_itens(): void
    {
        // openrouter.key não está configurado por padrão no ambiente de teste (é assim
        // que as outras chamadas de IA no resto da suíte fazem short-circuit sem bater na
        // rede) — aqui precisamos de uma chave fake pra analisarImagemCompleta() de fato
        // tentar a chamada de visão, senão ela nem chega a bater no endpoint fakeado.
        config(['services.openrouter.key' => 'fake-openrouter-key']);

        Http::fake([
            '*/media/get*'    => Http::response('conteudo-binario-fake-imagem', 200, ['Content-Type' => 'image/jpeg']),
            'openrouter.ai/*' => Http::response([
                'model'   => 'modelo-fake',
                'choices' => [['message' => ['content' => "Uma sala de estar com sofá e mesa de jantar.\n\nITENS:\n- Sofá 3 lugares\n- Mesa de jantar"]]],
            ], 200),
        ]);

        $tenant = Tenant::factory()->create();
        WhatsappCanal::factory()->create([
            'tenant_id' => $tenant->id, 'tipo' => 'oficial', 'provider' => 'covercut',
            'config' => ['phone_number_id' => '950147584848138', 'webhook_secret' => 'segredo-abc'],
        ]);

        $payload = [
            'event' => 'message', 'direction' => 'inbound', 'from_number_id' => '950147584848138',
            'contact' => ['wa_id' => '5521988887777', 'name' => 'Sandro'],
            'message' => ['id' => 'wamid.img1', 'type' => 'image', 'image' => ['id' => 'media-img-1', 'mime_type' => 'image/jpeg', 'caption' => 'minha sala']],
        ];

        $this->postComAssinatura($payload, 'segredo-abc')->assertOk();

        $mensagem = Mensagem::where('provider_message_id', 'wamid.img1')->first();
        $this->assertNotNull($mensagem, 'Mensagem de imagem deveria ter sido criada');
        $this->assertSame('imagem', $mensagem->tipo);
        $this->assertNotNull($mensagem->midia_url);
        $this->assertStringContainsString('minha sala', $mensagem->conteudo);
        $this->assertStringContainsString('Uma sala de estar com sofá', $mensagem->conteudo);

        $ticket = TicketAtendimento::withoutGlobalScopes()->find($mensagem->ticket_id);
        $this->assertNotNull($ticket);
        $this->assertNotNull($ticket->lista_itens, 'lista_itens do ticket deveria ter sido populada com os itens extraídos');
        $this->assertStringContainsString('Sofá 3 lugares', $ticket->lista_itens);
        $this->assertStringContainsString('Mesa de jantar', $ticket->lista_itens);

        Http::assertSent(fn ($request) => str_contains($request->url(), '/media/get') && $request['id'] === 'media-img-1');
        Http::assertSent(fn ($request) => str_contains($request->url(), 'openrouter.ai'));
        Http::assertSentCount(2); // 1 download da mídia + 1 chamada de visão — não duplica mais
    }

    public function test_imagem_sem_id_no_payload_e_tratada_sem_quebrar(): void
    {
        Http::fake();

        $tenant = Tenant::factory()->create();
        WhatsappCanal::factory()->create([
            'tenant_id' => $tenant->id, 'tipo' => 'oficial', 'provider' => 'covercut',
            'config' => ['phone_number_id' => '950147584848138', 'webhook_secret' => 'segredo-abc'],
        ]);

        $payload = [
            'event' => 'message', 'direction' => 'inbound', 'from_number_id' => '950147584848138',
            'contact' => ['wa_id' => '5521988887777'],
            'message' => ['id' => 'wamid.img2', 'type' => 'image', 'image' => ['caption' => 'sem id aqui']],
        ];

        $response = $this->postComAssinatura($payload, 'segredo-abc');

        $response->assertOk();
        $mensagem = Mensagem::where('provider_message_id', 'wamid.img2')->first();
        $this->assertNotNull($mensagem);
        $this->assertSame('sem id aqui', $mensagem->conteudo);
        Http::assertNothingSent();
    }

    public function test_video_e_salvo_com_tipo_video_e_midia_url(): void
    {
        Http::fake([
            '*/media/get*' => Http::response('conteudo-binario-fake-video', 200, ['Content-Type' => 'video/mp4']),
        ]);

        $tenant = Tenant::factory()->create();
        WhatsappCanal::factory()->create([
            'tenant_id' => $tenant->id, 'tipo' => 'oficial', 'provider' => 'covercut',
            'config' => ['phone_number_id' => '950147584848138', 'webhook_secret' => 'segredo-abc'],
        ]);

        $payload = [
            'event' => 'message', 'direction' => 'inbound', 'from_number_id' => '950147584848138',
            'contact' => ['wa_id' => '5521988887777'],
            'message' => ['id' => 'wamid.vid1', 'type' => 'video', 'video' => ['id' => 'media-vid-1', 'caption' => 'olha isso']],
        ];

        $this->postComAssinatura($payload, 'segredo-abc')->assertOk();

        $mensagem = Mensagem::where('provider_message_id', 'wamid.vid1')->first();
        $this->assertNotNull($mensagem);
        $this->assertSame('video', $mensagem->tipo);
        $this->assertStringContainsString('olha isso', $mensagem->conteudo);
        $this->assertNotNull($mensagem->midia_url);
    }

    /**
     * Achado Importante 1 da revisão final: o branch de vídeo não tinha
     * try/catch (diferente de áudio e imagem), então uma falha ao PERSISTIR a
     * mídia já baixada (ex.: Storage::put() lançando por disco cheio/permissão
     * — exceção que não é capturada dentro de baixarMidiaCovercut(), pois essa
     * acontece depois do download já ter tido sucesso) escapava sem tratamento
     * e derrubava o webhook inteiro com 500, fazendo a Covercut re-tentar e a
     * mensagem nunca ser salva. Prova que agora ela é capturada e a mensagem
     * ainda é criada com o placeholder textual (só sem midia_url).
     */
    public function test_falha_ao_persistir_video_e_capturada_sem_quebrar_webhook(): void
    {
        Http::fake([
            '*/media/get*' => Http::response('conteudo-binario-fake-video', 200, ['Content-Type' => 'video/mp4']),
        ]);

        Storage::shouldReceive('disk')->with('public')->andReturnSelf();
        Storage::shouldReceive('put')->andThrow(new \RuntimeException('disco cheio'));

        $tenant = Tenant::factory()->create();
        WhatsappCanal::factory()->create([
            'tenant_id' => $tenant->id, 'tipo' => 'oficial', 'provider' => 'covercut',
            'config' => ['phone_number_id' => '950147584848138', 'webhook_secret' => 'segredo-abc'],
        ]);

        $payload = [
            'event' => 'message', 'direction' => 'inbound', 'from_number_id' => '950147584848138',
            'contact' => ['wa_id' => '5521988887777'],
            'message' => ['id' => 'wamid.vid-falha', 'type' => 'video', 'video' => ['id' => 'media-vid-falha', 'caption' => 'olha isso']],
        ];

        $response = $this->postComAssinatura($payload, 'segredo-abc');

        $response->assertOk();
        $response->assertJson(['ok' => true]);

        $mensagem = Mensagem::where('provider_message_id', 'wamid.vid-falha')->first();
        $this->assertNotNull($mensagem, 'Mensagem de vídeo deveria ter sido criada mesmo com falha ao persistir a mídia');
        $this->assertSame('video', $mensagem->tipo);
        $this->assertStringContainsString('olha isso', $mensagem->conteudo);
        $this->assertNull($mensagem->midia_url);
    }

    public function test_documento_e_salvo_com_placeholder_sem_midia_url(): void
    {
        Http::fake(); // não deveria ser chamado — documento não baixa mídia

        $tenant = Tenant::factory()->create();
        WhatsappCanal::factory()->create([
            'tenant_id' => $tenant->id, 'tipo' => 'oficial', 'provider' => 'covercut',
            'config' => ['phone_number_id' => '950147584848138', 'webhook_secret' => 'segredo-abc'],
        ]);

        $payload = [
            'event' => 'message', 'direction' => 'inbound', 'from_number_id' => '950147584848138',
            'contact' => ['wa_id' => '5521988887777'],
            'message' => ['id' => 'wamid.doc1', 'type' => 'document', 'document' => ['filename' => 'orcamento.pdf']],
        ];

        $this->postComAssinatura($payload, 'segredo-abc')->assertOk();

        $mensagem = Mensagem::where('provider_message_id', 'wamid.doc1')->first();
        $this->assertNotNull($mensagem);
        $this->assertSame('texto', $mensagem->tipo);
        $this->assertStringContainsString('orcamento.pdf', $mensagem->conteudo);
        $this->assertNull($mensagem->midia_url);
        Http::assertNothingSent();
    }

    public function test_tipo_unsupported_da_meta_continua_apenas_logado(): void
    {
        Http::fake();

        $tenant = Tenant::factory()->create();
        WhatsappCanal::factory()->create([
            'tenant_id' => $tenant->id, 'tipo' => 'oficial', 'provider' => 'covercut',
            'config' => ['phone_number_id' => '950147584848138', 'webhook_secret' => 'segredo-abc'],
        ]);

        $payload = [
            'event' => 'message', 'direction' => 'inbound', 'from_number_id' => '950147584848138',
            'contact' => ['wa_id' => '5521988887777'],
            'message' => ['id' => 'wamid.unsup1', 'type' => 'unsupported', 'unsupported' => ['type' => 'unknown']],
        ];

        $this->postComAssinatura($payload, 'segredo-abc')->assertOk();

        $this->assertDatabaseMissing('mensagens', ['provider_message_id' => 'wamid.unsup1']);
        Http::assertNothingSent();
    }

    /**
     * Achado real 2026-08-20 (Leonardo): o eco da transcrição do áudio do
     * LEAD de volta pra ele mesmo não tem função nenhuma (ele já sabe o que
     * falou) — comportamento removido. A transcrição continua disponível
     * pro sistema/IA normalmente, só não é mais reenviada como mensagem de
     * WhatsApp pro cliente. Eco do lado do ATENDENTE continua existindo (ver
     * test_audio_do_atendente_via_coexistence_..._ecoado_com_nome_da_persona
     * abaixo) — esse sim faz sentido, o cliente pode não conseguir ouvir
     * áudio. Ver mesmo teste espelhado em UazapiWebhookMidiaTest.
     */
    public function test_audio_do_lead_e_transcrito_mas_nao_e_mais_ecoado_na_conversa(): void
    {
        config(['services.groq.key' => 'fake-groq-key']);

        Http::fake([
            '*/media/get*'   => Http::response('conteudo-binario-fake-audio', 200, ['Content-Type' => 'audio/ogg']),
            'api.groq.com/*' => Http::response(['text' => 'oi, quero saber o valor do frete'], 200),
            '*/messages/send' => Http::response(['id' => 'wamid.eco'], 200),
        ]);

        $tenant = Tenant::factory()->create();
        WhatsappCanal::factory()->create([
            'tenant_id' => $tenant->id, 'tipo' => 'oficial', 'provider' => 'covercut',
            'config' => ['phone_number_id' => '950147584848138', 'webhook_secret' => 'segredo-abc'],
        ]);

        $payload = [
            'event' => 'message', 'direction' => 'inbound', 'from_number_id' => '950147584848138',
            'contact' => ['wa_id' => '5521988887777', 'name' => 'Sandro'],
            'message' => ['id' => 'wamid.audioeco1', 'type' => 'audio', 'audio' => ['id' => 'media-audio-eco1', 'mime_type' => 'audio/ogg']],
        ];

        $this->postComAssinatura($payload, 'segredo-abc')->assertOk();

        $mensagemLead = Mensagem::where('provider_message_id', 'wamid.audioeco1')->first();
        $this->assertNotNull($mensagemLead);
        $this->assertStringContainsString('oi, quero saber o valor do frete', $mensagemLead->conteudo);

        $this->assertDatabaseMissing('mensagens', ['remetente' => 'bot', 'ticket_id' => $mensagemLead->ticket_id]);

        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/messages/send')
            && str_contains($request['text']['body'] ?? '', 'Segue a transcrição do áudio enviado pelo Cliente'));
    }

    /**
     * Modo Coexistence: atendente grava áudio direto pelo WhatsApp Business App.
     * A Covercut manda isso como echo/outbound/phone — mesmo tratamento do
     * áudio do lead, mas com o nome da persona no lugar de "Cliente".
     */
    public function test_audio_do_atendente_via_coexistence_e_transcrito_e_ecoado_com_nome_da_persona(): void
    {
        config(['services.groq.key' => 'fake-groq-key']);

        Http::fake([
            '*/media/get*'    => Http::response('conteudo-binario-fake-audio', 200, ['Content-Type' => 'audio/ogg']),
            'api.groq.com/*'  => Http::response(['text' => 'pode deixar que eu confirmo o horario'], 200),
            '*/messages/send' => Http::response(['id' => 'wamid.eco2'], 200),
        ]);

        $tenant = Tenant::factory()->create();
        $canal  = WhatsappCanal::factory()->create([
            'tenant_id' => $tenant->id, 'tipo' => 'oficial', 'provider' => 'covercut',
            'config' => ['phone_number_id' => '950147584848138', 'webhook_secret' => 'segredo-abc'],
        ]);
        $persona = \App\Models\SdrPersona::create([
            'tenant_id' => $tenant->id, 'nome_interno' => 'padrao', 'nome_display' => 'Leonardo',
            'system_prompt' => 'Você é o Leonardo, atendente da empresa.',
            'ativo' => true, 'is_default' => true,
        ]);
        $contato = \App\Models\Contato::factory()->create(['telefone' => '5521988887777']);
        $ticket  = TicketAtendimento::create([
            'tenant_id' => $tenant->id, 'contato_id' => $contato->id,
            'whatsapp_canal_id' => $canal->id, 'sdr_persona_id' => $persona->id,
            'coluna_kanban' => 'em_atendimento', 'agente_responsavel' => 'bot',
            'status' => 'aberto', 'aberto_em' => now(),
            'janela_expira_em' => now()->addHours(10),
        ]);

        $payload = [
            'event' => 'echo', 'direction' => 'outbound', 'echo_source' => 'phone', 'from_number_id' => '950147584848138',
            'contact' => ['wa_id' => '5521988887777'],
            'message' => ['id' => 'wamid.audioatendente1', 'type' => 'audio', 'audio' => ['id' => 'media-audio-atendente1', 'mime_type' => 'audio/ogg']],
        ];

        $this->postComAssinatura($payload, 'segredo-abc')->assertOk();

        $mensagemHumano = Mensagem::where('provider_message_id', 'wamid.audioatendente1')->first();
        $this->assertNotNull($mensagemHumano);
        $this->assertSame('humano', $mensagemHumano->remetente);
        $this->assertStringContainsString('pode deixar que eu confirmo o horario', $mensagemHumano->conteudo);

        $eco = Mensagem::where('remetente', 'bot')->latest()->first();
        $this->assertNotNull($eco);
        $this->assertSame(
            "[Segue a transcrição do áudio enviado pelo Leonardo]\n\npode deixar que eu confirmo o horario",
            $eco->conteudo
        );

        $ticket->refresh();
        $this->assertSame('humano', $ticket->agente_responsavel);
    }

    /**
     * Pedido do Leonardo (2026-08-05): poder ligar/desligar a transcrição de
     * áudio e a análise de imagem por coluna do Kanban — mesma cobertura no
     * canal oficial que no Uazapi (ver UazapiWebhookMidiaTest).
     */
    public function test_transcricao_desativada_na_coluna_pula_ia_mas_ainda_salva_midia(): void
    {
        Http::fake([
            '*/media/get*' => Http::response('conteudo-binario-fake-audio', 200, ['Content-Type' => 'audio/ogg']),
        ]);

        $tenant = Tenant::factory()->create();
        WhatsappCanal::factory()->create([
            'tenant_id' => $tenant->id, 'tipo' => 'oficial', 'provider' => 'covercut',
            'config' => ['phone_number_id' => '950147584848138', 'webhook_secret' => 'segredo-abc'],
        ]);
        \App\Models\KanbanColunaConfig::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id, 'coluna_kanban' => 'lead_novo', 'transcricao_ativa' => false,
        ]);

        $payload = [
            'event' => 'message', 'direction' => 'inbound', 'from_number_id' => '950147584848138',
            'contact' => ['wa_id' => '5521988887777', 'name' => 'Sandro'],
            'message' => ['id' => 'wamid.transcoff', 'type' => 'audio', 'audio' => ['id' => 'media-transc-off', 'mime_type' => 'audio/ogg']],
        ];

        $this->postComAssinatura($payload, 'segredo-abc')->assertOk();

        $mensagem = Mensagem::where('provider_message_id', 'wamid.transcoff')->first();
        $this->assertNotNull($mensagem);
        $this->assertSame('audio', $mensagem->tipo);
        $this->assertNotNull($mensagem->midia_url, 'O áudio ainda deveria ser baixado e salvo');
        $this->assertSame('[Áudio recebido — transcrição desativada para esta coluna]', $mensagem->conteudo);

        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'groq'));
    }
}
