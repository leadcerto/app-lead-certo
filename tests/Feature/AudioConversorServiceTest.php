<?php

namespace Tests\Feature;

use App\Services\AudioConversorService;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

/**
 * Reversão da decisão de 2026-07-31 (ver
 * docs/superpowers/specs/2026-07-31-erro-formato-audio-canal-oficial-design.md):
 * na época o Leonardo optou por só avisar o atendente que webm/wav não são
 * aceitos pelo canal Oficial, sem instalar ffmpeg. Em 2026-08-21, ao esbarrar
 * nisso ao vivo, pediu pra resolver de verdade — converter em vez de só
 * rejeitar.
 */
class AudioConversorServiceTest extends TestCase
{
    public function test_converte_arquivo_pra_ogg_com_sucesso(): void
    {
        $origem = tempnam(sys_get_temp_dir(), 'audio_teste_') . '.webm';
        file_put_contents($origem, 'conteudo-fake-de-audio');

        Process::fake(function ($process) {
            // Simula o ffmpeg criando o arquivo de destino de verdade —
            // sem isso o teste passaria mesmo se o serviço nunca checasse
            // se o arquivo convertido existe.
            $destino = collect($process->command)->last();
            file_put_contents($destino, 'conteudo-fake-convertido');

            return Process::result(exitCode: 0);
        });

        $resultado = app(AudioConversorService::class)->paraOgg($origem);

        $this->assertNotNull($resultado);
        $this->assertFileExists($resultado);
        $this->assertStringEndsWith('.ogg', $resultado);

        Process::assertRan(function ($process) use ($origem) {
            $comando = $process->command;
            return $comando[0] === 'ffmpeg'
                && in_array($origem, $comando, true)
                && in_array('-c:a', $comando, true)
                && in_array('libopus', $comando, true);
        });

        @unlink($origem);
        @unlink($resultado);
    }

    public function test_ffmpeg_ausente_ou_com_erro_retorna_null(): void
    {
        $origem = tempnam(sys_get_temp_dir(), 'audio_teste_') . '.webm';
        file_put_contents($origem, 'conteudo-fake-de-audio');

        Process::fake(fn () => Process::result(exitCode: 127, errorOutput: 'ffmpeg: command not found'));

        $resultado = app(AudioConversorService::class)->paraOgg($origem);

        $this->assertNull($resultado);

        @unlink($origem);
    }

    public function test_ffmpeg_roda_mas_nao_gera_arquivo_de_saida_retorna_null(): void
    {
        $origem = tempnam(sys_get_temp_dir(), 'audio_teste_') . '.webm';
        file_put_contents($origem, 'conteudo-fake-de-audio');

        // Exit code 0 mas o arquivo de destino nunca foi criado de verdade —
        // caso raro, mas a checagem de file_exists() é a rede de segurança.
        Process::fake(fn () => Process::result(exitCode: 0));

        $resultado = app(AudioConversorService::class)->paraOgg($origem);

        $this->assertNull($resultado);

        @unlink($origem);
    }
}
