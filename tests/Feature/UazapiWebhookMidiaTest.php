<?php

namespace Tests\Feature;

use App\Models\Contato;
use App\Models\Mensagem;
use App\Models\Tenant;
use App\Models\TicketAtendimento;
use App\Models\WhatsappCanal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class UazapiWebhookMidiaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        config(['services.uazapi.base_url' => 'https://fake-uazapi.test']);
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

    private function fakeDownloadDeMidia(string $mimetype): void
    {
        Http::fake([
            '*/message/download' => Http::response([
                'base64'   => base64_encode('conteudo-binario-fake'),
                'mimetype' => $mimetype,
            ], 200),
            '*' => Http::response(['ok' => true], 200),
        ]);
    }

    public function test_imagem_enviada_pelo_lead_e_salva_com_midia_url(): void
    {
        $this->fakeDownloadDeMidia('image/jpeg');

        $this->criarTenantComCanal('wh-midia-1', 'inst-1');

        $this->postJson('/api/webhook/uazapi/wh-midia-1', [
            'EventType' => 'messages',
            'message'   => [
                'fromMe'      => false,
                'isGroup'     => false,
                'chatid'      => '5511933334444@s.whatsapp.net',
                'mediaType'   => 'image',
                'messageid'   => 'msg-1',
                'content'     => '{}',
            ],
        ]);

        $contato = Contato::where('telefone', '5511933334444')->first();
        $this->assertNotNull($contato);

        $mensagem = Mensagem::where('remetente', 'lead')->latest()->first();
        $this->assertNotNull($mensagem);
        $this->assertSame('imagem', $mensagem->tipo);
        $this->assertNotNull($mensagem->midia_url);
        $this->assertStringContainsString('/storage/kanban-midia/', $mensagem->midia_url);
        $this->assertNotEmpty(Storage::disk('public')->allFiles('kanban-midia'));
    }

    public function test_audio_enviado_pelo_whatsapp_web_sem_legenda_agora_e_salvo(): void
    {
        $this->fakeDownloadDeMidia('audio/ogg');

        $tenant  = $this->criarTenantComCanal('wh-midia-2', 'inst-2');
        $contato = Contato::factory()->create(['telefone' => '5511944445555']);
        $ticket  = TicketAtendimento::create([
            'tenant_id' => $tenant->id, 'contato_id' => $contato->id,
            'coluna_kanban' => 'em_atendimento', 'agente_responsavel' => 'bot',
            'status' => 'aberto', 'aberto_em' => now(),
        ]);

        $this->postJson('/api/webhook/uazapi/wh-midia-2', [
            'EventType' => 'messages',
            'message'   => [
                'fromMe'      => true,
                'wasSentByApi' => false,
                'isGroup'     => false,
                'chatid'      => '5511944445555@s.whatsapp.net',
                'mediaType'   => 'audio',
                'messageid'   => 'msg-2',
                'content'     => '{}',
            ],
        ]);

        $mensagem = Mensagem::where('ticket_id', $ticket->id)->where('remetente', 'humano')->latest()->first();
        $this->assertNotNull($mensagem, 'A mensagem de áudio enviada via WhatsApp Web deveria ter sido salva');
        $this->assertSame('audio', $mensagem->tipo);
        $this->assertNotNull($mensagem->midia_url);
    }

    /**
     * Achado real 2026-08-15 (ticket 3085, Frete Rio, mesmo bug do Covercut —
     * ver CovercutWebhookMidiaTest::test_imagem_recebida_e_descrita_e_salva_com_midia_url_e_itens):
     * antes desta correção a mesma imagem era baixada até 3x e passava por 2
     * chamadas de IA separadas (descrição + itens). Agora é 1 download + 1
     * chamada de visão que devolve os dois juntos (marcador "ITENS:" no
     * prompt) — o assertSentCount(2) abaixo prova que não duplica mais.
     */
    public function test_imagem_e_descrita_e_itens_extraidos_numa_unica_passada(): void
    {
        config(['services.openrouter.key' => 'fake-openrouter-key']);

        Http::fake([
            '*/message/download' => Http::response([
                'base64'   => base64_encode('conteudo-binario-fake-imagem'),
                'mimetype' => 'image/jpeg',
            ], 200),
            'openrouter.ai/*' => Http::response([
                'model'   => 'modelo-fake',
                'choices' => [['message' => ['content' => "Um sofá de três lugares na sala.\n\nITENS:\n- Sofá 3 lugares\n- Mesa de centro"]]],
            ], 200),
        ]);

        $this->criarTenantComCanal('wh-midia-itens', 'inst-itens');

        $this->postJson('/api/webhook/uazapi/wh-midia-itens', [
            'EventType' => 'messages',
            'message'   => [
                'fromMe'    => false,
                'isGroup'   => false,
                'chatid'    => '5511922221111@s.whatsapp.net',
                'mediaType' => 'image',
                'messageid' => 'msg-itens-1',
                'content'   => '{}',
            ],
        ]);

        $mensagem = Mensagem::where('remetente', 'lead')->latest()->first();
        $this->assertNotNull($mensagem);
        $this->assertSame('imagem', $mensagem->tipo);
        $this->assertStringContainsString('Um sofá de três lugares', $mensagem->conteudo);

        $ticket = TicketAtendimento::withoutGlobalScopes()->where('id', $mensagem->ticket_id)->first();
        $this->assertNotNull($ticket->lista_itens, 'lista_itens do ticket deveria ter sido populada com os itens extraídos');
        $this->assertStringContainsString('Sofá 3 lugares', $ticket->lista_itens);
        $this->assertStringContainsString('Mesa de centro', $ticket->lista_itens);

        Http::assertSentCount(2); // 1 download da mídia + 1 chamada de visão — não duplica mais
    }

    public function test_imagem_usa_url_direta_do_content_quando_download_do_uazapi_falha(): void
    {
        // Simula o cenário real de produção: os endpoints de download do Uazapi
        // retornam erro, então o fallback tem que extrair a URL direta do campo
        // `content` — que chega como array já decodificado, não como string JSON.
        Http::fake(['*' => Http::response('method not allowed', 405)]);

        $this->criarTenantComCanal('wh-midia-3', 'inst-3');

        $this->postJson('/api/webhook/uazapi/wh-midia-3', [
            'EventType' => 'messages',
            'message'   => [
                'fromMe'    => false,
                'isGroup'   => false,
                'chatid'    => '5511955556666@s.whatsapp.net',
                'mediaType' => 'image',
                'messageid' => 'msg-3',
                'content'   => [
                    'URL'      => 'https://mmg.whatsapp.net/o1/v/t24/f2/m235/fake-image-url',
                    'mimetype' => 'image/jpeg',
                ],
            ],
        ]);

        $mensagem = Mensagem::where('remetente', 'lead')->latest()->first();
        $this->assertNotNull($mensagem);
        $this->assertSame('imagem', $mensagem->tipo);
        $this->assertSame('https://mmg.whatsapp.net/o1/v/t24/f2/m235/fake-image-url', $mensagem->midia_url);
    }

    public function test_webhook_duplicado_com_mesmo_messageid_nao_cria_segunda_mensagem(): void
    {
        Http::fake(['*' => Http::response('not found', 404)]);

        $this->criarTenantComCanal('wh-midia-4', 'inst-4');

        $payload = [
            'EventType' => 'messages',
            'message'   => [
                'fromMe'    => false,
                'isGroup'   => false,
                'chatid'    => '5511966667777@s.whatsapp.net',
                'text'      => 'Oi, tudo bem?',
                'messageid' => 'msg-duplicado',
            ],
        ];

        // Uazapi reenvia o mesmo evento (mesmo messageid) mais de uma vez
        $this->postJson('/api/webhook/uazapi/wh-midia-4', $payload);
        $this->postJson('/api/webhook/uazapi/wh-midia-4', $payload);

        $this->assertSame(1, Mensagem::where('provider_message_id', 'msg-duplicado')->count());
    }

    public function test_album_placeholder_e_ignorado_e_nao_vira_mensagem(): void
    {
        $this->criarTenantComCanal('wh-midia-5', 'inst-5');

        $this->postJson('/api/webhook/uazapi/wh-midia-5', [
            'EventType' => 'messages',
            'message'   => [
                'fromMe'    => false,
                'isGroup'   => false,
                'chatid'    => '5511977778888@s.whatsapp.net',
                'text'      => 'Album: 3 images',
                'messageid' => 'msg-album',
            ],
        ]);

        $this->assertSame(0, Mensagem::where('conteudo', 'Album: 3 images')->count());
    }

    /**
     * Monta um arquivo "criptografado" de verdade seguindo o mesmo protocolo do
     * WhatsApp (HKDF-SHA256 sem salt + AES-256-CBC + HMAC-SHA256 truncado em 10
     * bytes), pra provar que o decrypt do MediaProcessorService recupera
     * exatamente o conteúdo original — sem precisar de nenhuma mensagem real.
     */
    private function criptografarComoWhatsApp(string $plaintext, string $infoLabel): array
    {
        $mediaKey  = random_bytes(32);
        $expandido = hash_hkdf('sha256', $mediaKey, 112, $infoLabel);
        $iv        = substr($expandido, 0, 16);
        $cipherKey = substr($expandido, 16, 32);
        $macKey    = substr($expandido, 48, 32);

        $ciphertext = openssl_encrypt($plaintext, 'aes-256-cbc', $cipherKey, OPENSSL_RAW_DATA, $iv);
        $mac10      = substr(hash_hmac('sha256', $iv . $ciphertext, $macKey, true), 0, 10);

        return [
            'arquivo_criptografado' => $ciphertext . $mac10,
            'media_key_base64'      => base64_encode($mediaKey),
        ];
    }

    public function test_imagem_e_descriptografada_corretamente_sem_depender_do_uazapi(): void
    {
        $plaintextFakeJpeg = "conteudo-binario-de-uma-imagem-jpeg-de-verdade";
        $pacote = $this->criptografarComoWhatsApp($plaintextFakeJpeg, 'WhatsApp Image Keys');

        Http::fake([
            'mmg.whatsapp.net/*' => Http::response($pacote['arquivo_criptografado'], 200),
            '*'                  => Http::response('not found', 404), // Uazapi nunca deveria ser chamado aqui
        ]);

        $this->criarTenantComCanal('wh-midia-6', 'inst-6');

        $this->postJson('/api/webhook/uazapi/wh-midia-6', [
            'EventType' => 'messages',
            'message'   => [
                'fromMe'    => false,
                'isGroup'   => false,
                'chatid'    => '5511988889999@s.whatsapp.net',
                'mediaType' => 'image',
                'messageid' => 'msg-6',
                'content'   => [
                    'URL'      => 'https://mmg.whatsapp.net/v/t62/fake.enc',
                    'mimetype' => 'image/jpeg',
                    'mediaKey' => $pacote['media_key_base64'],
                ],
            ],
        ]);

        $mensagem = Mensagem::where('remetente', 'lead')->latest()->first();
        $this->assertNotNull($mensagem);
        $this->assertSame('imagem', $mensagem->tipo);
        $this->assertNotNull($mensagem->midia_url);

        $caminho = Storage::disk('public')->allFiles('kanban-midia')[0] ?? null;
        $this->assertNotNull($caminho);
        $this->assertSame($plaintextFakeJpeg, Storage::disk('public')->get($caminho));
    }

    public function test_audio_de_voz_ptt_e_reconhecido_como_audio(): void
    {
        // WhatsApp manda mensagem de voz com mediaType 'ptt', não 'audio'.
        Http::fake(['*' => Http::response('not found', 404)]);

        $this->criarTenantComCanal('wh-midia-7', 'inst-7');

        $this->postJson('/api/webhook/uazapi/wh-midia-7', [
            'EventType' => 'messages',
            'message'   => [
                'fromMe'    => false,
                'isGroup'   => false,
                'chatid'    => '5511999990000@s.whatsapp.net',
                'mediaType' => 'ptt',
                'messageid' => 'msg-7',
                'content'   => ['mimetype' => 'audio/ogg; codecs=opus'],
            ],
        ]);

        $mensagem = Mensagem::where('remetente', 'lead')->latest()->first();
        $this->assertNotNull($mensagem, 'Mensagem de voz (ptt) deveria ter sido salva como áudio');
        $this->assertSame('audio', $mensagem->tipo);
    }

    public function test_video_e_salvo_com_tipo_video_e_midia_url(): void
    {
        $plaintextFakeMp4 = "conteudo-binario-de-um-video-mp4-de-verdade";
        $pacote = $this->criptografarComoWhatsApp($plaintextFakeMp4, 'WhatsApp Video Keys');

        Http::fake([
            'mmg.whatsapp.net/*' => Http::response($pacote['arquivo_criptografado'], 200),
            '*'                  => Http::response('not found', 404),
        ]);

        $this->criarTenantComCanal('wh-midia-8', 'inst-8');

        $this->postJson('/api/webhook/uazapi/wh-midia-8', [
            'EventType' => 'messages',
            'message'   => [
                'fromMe'    => false,
                'isGroup'   => false,
                'chatid'    => '5511911110000@s.whatsapp.net',
                'mediaType' => 'video',
                'messageid' => 'msg-8',
                'content'   => [
                    'URL'      => 'https://mmg.whatsapp.net/v/t62/fake-video.enc',
                    'mimetype' => 'video/mp4',
                    'mediaKey' => $pacote['media_key_base64'],
                ],
            ],
        ]);

        $mensagem = Mensagem::where('remetente', 'lead')->latest()->first();
        $this->assertNotNull($mensagem);
        $this->assertSame('video', $mensagem->tipo);
        $this->assertNotNull($mensagem->midia_url);
    }

    public function test_telefone_de_contato_apagado_e_restaurado_em_vez_de_travar(): void
    {
        Http::fake(['*' => Http::response('not found', 404)]);

        $this->criarTenantComCanal('wh-midia-9', 'inst-9');

        $apagado = Contato::factory()->create(['telefone' => '5511922223333', 'nome' => 'Fulano Antigo']);
        $apagado->delete();
        $this->assertTrue($apagado->fresh()->trashed());

        $this->postJson('/api/webhook/uazapi/wh-midia-9', [
            'EventType' => 'messages',
            'message'   => [
                'fromMe'    => false,
                'isGroup'   => false,
                'chatid'    => '5511922223333@s.whatsapp.net',
                'text'      => 'Oi, voltei a falar com vocês',
                'messageid' => 'msg-9',
            ],
        ]);

        $contato = Contato::where('telefone', '5511922223333')->first();
        $this->assertNotNull($contato, 'Contato apagado deveria ter sido restaurado');
        $this->assertSame($apagado->id, $contato->id);

        $ticket = TicketAtendimento::withoutGlobalScopes()->where('contato_id', $contato->id)->first();
        $this->assertNotNull($ticket);
    }

    public function test_url_de_midia_fora_do_dominio_whatsapp_nao_e_buscada(): void
    {
        // Protecao contra SSRF: uma URL manipulada no payload nao pode fazer o
        // servidor buscar um endereco interno/arbitrario.
        Http::fake(['*' => Http::response('not found', 404)]);

        $this->criarTenantComCanal('wh-midia-10', 'inst-10');

        $this->postJson('/api/webhook/uazapi/wh-midia-10', [
            'EventType' => 'messages',
            'message'   => [
                'fromMe'    => false,
                'isGroup'   => false,
                'chatid'    => '5511900001111@s.whatsapp.net',
                'mediaType' => 'image',
                'messageid' => 'msg-10',
                'content'   => [
                    'URL'      => 'http://169.254.169.254/latest/meta-data/',
                    'mimetype' => 'image/jpeg',
                    'mediaKey' => base64_encode(random_bytes(32)),
                ],
            ],
        ]);

        Http::assertNotSent(fn ($request) => str_contains($request->url(), '169.254.169.254'));
    }

    /**
     * Nova funcionalidade (2026-08-03): além de transcrever pro contexto do bot,
     * a transcrição também é ecoada como mensagem de texto na própria conversa
     * do WhatsApp — quem lê pelo app (sem abrir o painel) consegue ler sem tocar
     * o áudio. Ver CovercutWebhookMidiaTest para o mesmo comportamento no canal
     * oficial.
     */
    public function test_audio_do_lead_e_transcrito_e_ecoado_na_conversa(): void
    {
        config(['services.groq.key' => 'fake-groq-key']);

        $pacote = $this->criptografarComoWhatsApp('conteudo-audio-fake', 'WhatsApp Audio Keys');

        Http::fake([
            'mmg.whatsapp.net/*' => Http::response($pacote['arquivo_criptografado'], 200),
            'api.groq.com/*'     => Http::response(['text' => 'oi, quero saber o valor do frete'], 200),
            '*/send/text'        => Http::response(['ok' => true], 200),
            '*'                  => Http::response('not found', 404),
        ]);

        $this->criarTenantComCanal('wh-eco-1', 'inst-eco-1');

        $this->postJson('/api/webhook/uazapi/wh-eco-1', [
            'EventType' => 'messages',
            'message'   => [
                'fromMe'    => false,
                'isGroup'   => false,
                'chatid'    => '5511900001234@s.whatsapp.net',
                'mediaType' => 'audio',
                'messageid' => 'msg-eco-1',
                'content'   => [
                    'URL'      => 'https://mmg.whatsapp.net/v/t62/fake-audio.enc',
                    'mimetype' => 'audio/ogg; codecs=opus',
                    'mediaKey' => $pacote['media_key_base64'],
                ],
            ],
        ]);

        $mensagemLead = Mensagem::where('remetente', 'lead')->latest()->first();
        $this->assertNotNull($mensagemLead);
        $this->assertSame('audio', $mensagemLead->tipo);
        $this->assertStringContainsString('oi, quero saber o valor do frete', $mensagemLead->conteudo);

        $eco = Mensagem::where('remetente', 'bot')->latest()->first();
        $this->assertNotNull($eco, 'Eco da transcrição deveria ter sido salvo');
        $this->assertSame(
            "[Segue a transcrição do áudio enviado pelo Cliente]\n\noi, quero saber o valor do frete",
            $eco->conteudo
        );

        Http::assertSent(fn ($request) => str_contains($request->url(), '/send/text')
            && str_contains($request['text'] ?? '', 'Segue a transcrição do áudio enviado pelo Cliente'));
    }

    /**
     * Mensagem enviada pelo atendente via WhatsApp Web/celular (fromMe &&
     * !wasSentByApi) — antes desta correção nem sequer era transcrita (só
     * baixava a URL e usava o placeholder "[Áudio]"). Agora transcreve de
     * verdade e ecoa na conversa com o nome da persona no lugar de "Cliente".
     */
    public function test_audio_do_atendente_via_whatsapp_web_e_transcrito_e_ecoado_com_nome_da_persona(): void
    {
        config(['services.groq.key' => 'fake-groq-key']);

        $pacote = $this->criptografarComoWhatsApp('resposta em audio do atendente', 'WhatsApp Audio Keys');

        Http::fake([
            'mmg.whatsapp.net/*' => Http::response($pacote['arquivo_criptografado'], 200),
            'api.groq.com/*'     => Http::response(['text' => 'pode deixar que eu confirmo o horario'], 200),
            '*/send/text'        => Http::response(['ok' => true], 200),
            '*'                  => Http::response('not found', 404),
        ]);

        $tenant  = $this->criarTenantComCanal('wh-eco-2', 'inst-eco-2');
        $persona = \App\Models\SdrPersona::create([
            'tenant_id' => $tenant->id, 'nome_interno' => 'padrao', 'nome_display' => 'Leonardo',
            'system_prompt' => 'Você é o Leonardo, atendente da empresa.',
            'ativo' => true, 'is_default' => true,
        ]);
        $contato = Contato::factory()->create(['telefone' => '5511955559999']);
        $ticket  = TicketAtendimento::create([
            'tenant_id' => $tenant->id, 'contato_id' => $contato->id,
            'sdr_persona_id' => $persona->id,
            'coluna_kanban' => 'em_atendimento', 'agente_responsavel' => 'bot',
            'status' => 'aberto', 'aberto_em' => now(),
        ]);

        $this->postJson('/api/webhook/uazapi/wh-eco-2', [
            'EventType' => 'messages',
            'message'   => [
                'fromMe'       => true,
                'wasSentByApi' => false,
                'isGroup'      => false,
                'chatid'       => '5511955559999@s.whatsapp.net',
                'mediaType'    => 'audio',
                'messageid'    => 'msg-eco-2',
                'content'      => [
                    'URL'      => 'https://mmg.whatsapp.net/v/t62/fake-audio2.enc',
                    'mimetype' => 'audio/ogg; codecs=opus',
                    'mediaKey' => $pacote['media_key_base64'],
                ],
            ],
        ]);

        $mensagemHumano = Mensagem::where('remetente', 'humano')->latest()->first();
        $this->assertNotNull($mensagemHumano);
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
     * áudio e a análise de imagem por coluna do Kanban. Desligado: a mídia
     * ainda é baixada e salva no card normalmente, só a chamada de IA é
     * pulada (custo zero de Whisper/visão).
     */
    public function test_transcricao_desativada_na_coluna_pula_ia_mas_ainda_salva_midia(): void
    {
        // Contato novo nasce sem nome real (pushName ausente no payload) —
        // dispara IdentificarNomeConversaJob (achado 2026-08-14), irrelevante
        // pra este teste especificamente sobre toggle de transcrição.
        Queue::fake();
        $this->fakeDownloadDeMidia('image/jpeg');

        $tenant = $this->criarTenantComCanal('wh-midia-transc-off', 'inst-transc-off');
        \App\Models\KanbanColunaConfig::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id, 'coluna_kanban' => 'lead_novo', 'transcricao_ativa' => false,
        ]);

        $this->postJson('/api/webhook/uazapi/wh-midia-transc-off', [
            'EventType' => 'messages',
            'message'   => [
                'fromMe'      => false,
                'isGroup'     => false,
                'chatid'      => '5511933339999@s.whatsapp.net',
                'mediaType'   => 'image',
                'messageid'   => 'msg-transc-off',
                'content'     => '{}',
            ],
        ]);

        $mensagem = Mensagem::where('remetente', 'lead')->latest()->first();
        $this->assertNotNull($mensagem);
        $this->assertSame('imagem', $mensagem->tipo);
        $this->assertNotNull($mensagem->midia_url, 'A imagem ainda deveria ser baixada e salva');
        $this->assertSame('[Imagem recebida — transcrição desativada para esta coluna]', $mensagem->conteudo);

        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'openrouter'));
    }
}
