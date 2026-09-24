<?php

namespace Tests\Feature;

use App\Services\MediaProcessorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Fase 4 do plano do canal WhatsApp Messenger próprio (23/09): diferente de
 * Uazapi/Covercut, o microserviço já entrega a mídia decriptada (bytes) no
 * payload do webhook — não precisa "baixar por token/id", só processar.
 * Reaproveita os mesmos helpers provider-agnósticos (salvarBytes,
 * analisarImagemCompleta, transcreverAudioBase64) que os outros dois canais
 * já usam.
 */
class MediaProcessorServiceMessengerProprioTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
    }

    public function test_processar_video_retorna_placeholder_sem_nenhuma_chamada_externa(): void
    {
        $conteudo = app(MediaProcessorService::class)->processarMessengerProprio('video', 'bytes-fake', 'video/mp4');

        $this->assertSame('[Vídeo recebido]', $conteudo);
    }

    public function test_processar_documento_retorna_placeholder(): void
    {
        $conteudo = app(MediaProcessorService::class)->processarMessengerProprio('documento', 'bytes-fake', 'application/pdf');

        $this->assertSame('[Documento recebido]', $conteudo);
    }

    public function test_processar_audio_com_transcricao_desativada_nao_chama_groq(): void
    {
        Http::fake();

        $conteudo = app(MediaProcessorService::class)->processarMessengerProprio('audio', 'bytes-fake', 'audio/ogg', false);

        $this->assertSame('[Áudio recebido — transcrição desativada para esta coluna]', $conteudo);
        Http::assertNothingSent();
    }

    public function test_processar_audio_transcreve_via_groq(): void
    {
        config(['services.groq.key' => 'chave-teste']);
        Http::fake(['*api.groq.com*' => Http::response(['text' => 'oi tudo bem'], 200)]);

        $conteudo = app(MediaProcessorService::class)->processarMessengerProprio('audio', base64_decode('Ynl0ZXM='), 'audio/ogg', true);

        $this->assertSame('[Áudio transcrito: oi tudo bem]', $conteudo);
    }

    public function test_processar_imagem_unica_salva_bytes_e_retorna_url(): void
    {
        config(['services.openrouter.key' => '']); // sem chave — cai no fallback sem chamada externa
        Http::fake();

        $resultado = app(MediaProcessorService::class)->processarImagemUnicaMessengerProprio(
            base64_decode('Ynl0ZXM='), 'image/jpeg', 'legenda da foto'
        );

        $this->assertStringContainsString('processamento de visão não configurado', $resultado['conteudo']);
        $this->assertNotNull($resultado['midiaUrl']);
        Storage::disk('public')->assertExists($this->caminhoRelativo($resultado['midiaUrl']));
    }

    public function test_persistir_url_messenger_proprio_salva_bytes_no_storage(): void
    {
        $url = app(MediaProcessorService::class)->persistirUrlMessengerProprio(base64_decode('Ynl0ZXM='), 'audio/ogg', 'audio');

        $this->assertNotNull($url);
        Storage::disk('public')->assertExists($this->caminhoRelativo($url));
    }

    private function caminhoRelativo(string $url): string
    {
        return preg_replace('#^.*/storage/#', '', $url);
    }
}
