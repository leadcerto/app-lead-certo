<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

/**
 * Converte áudio pra .ogg (opus) via ffmpeg — usado quando o canal Oficial
 * (Covercut/Meta Cloud API) recebe um formato que não aceita (webm/wav,
 * tipicamente gravado direto no microfone do painel). Reversão da decisão de
 * 2026-07-31 de só avisar o atendente sem converter — ver
 * docs/superpowers/specs/2026-07-31-erro-formato-audio-canal-oficial-design.md.
 *
 * Depende do binário `ffmpeg` instalado no servidor (não é dependência do
 * Composer/npm — instalado via apt na VPS). Falha graciosamente (retorna
 * null) se o binário não existir ou a conversão der errado — quem chama
 * decide o que fazer (hoje: cai de volta na mensagem de erro que já existia).
 */
class AudioConversorService
{
    public function paraOgg(string $caminhoOrigem): ?string
    {
        $destino = sys_get_temp_dir() . '/' . Str::random(40) . '.ogg';

        $resultado = Process::timeout(30)->run([
            'ffmpeg', '-y', '-i', $caminhoOrigem, '-c:a', 'libopus', '-b:a', '32k', $destino,
        ]);

        if (! $resultado->successful() || ! file_exists($destino)) {
            Log::error('AudioConversorService: falha ao converter áudio pra ogg', [
                'origem'      => $caminhoOrigem,
                'exit_code'   => $resultado->exitCode(),
                'erro'        => $resultado->errorOutput(),
            ]);

            return null;
        }

        return $destino;
    }
}
