<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class MediaProcessorService
{
    private string $openRouterKey;
    private string $groqKey;
    private string $uazapiBaseUrl;

    public function __construct()
    {
        $this->openRouterKey = (string) config('services.openrouter.key', '');
        $this->groqKey       = (string) config('services.groq.key', '');
        $this->uazapiBaseUrl = rtrim(config('services.uazapi.base_url', ''), '/');
    }

    /**
     * Processa qualquer tipo de mídia e retorna texto descritivo para o bot.
     * Retorna null se não há mídia ou não conseguiu processar.
     */
    public function processar(array $msg, string $instanceToken): ?string
    {
        $mediaType = $msg['mediaType'] ?? null;

        if (! $mediaType) {
            return null;
        }

        return match ($mediaType) {
            'image'    => $this->processarImagem($msg, $instanceToken),
            'audio'    => $this->processarAudio($msg, $instanceToken),
            'video'    => $this->processarVideo($msg, $instanceToken),
            'document' => $this->processarDocumento($msg),
            default    => null,
        };
    }

    // -------------------------------------------------------------------------
    // Imagem → visão IA
    // -------------------------------------------------------------------------

    private function processarImagem(array $msg, string $instanceToken): string
    {
        $caption  = is_string($msg['content'] ?? null) ? ($msg['content'] ?? '') : '';
        $mediaUrl = $this->extrairUrl($msg) ?? $this->baixarUrlViaUazapi($instanceToken, $msg);

        if (! $mediaUrl) {
            Log::warning('MediaProcessor: não encontrou URL de imagem', ['msg' => array_keys($msg)]);
            $caption = $caption ?: '[Imagem recebida]';
            return $caption;
        }

        $descricao = $this->descreverImagemComVisao($mediaUrl, $caption);
        $prefixo   = $caption ? "[Imagem: {$caption}] " : '[Imagem] ';

        return $prefixo . $descricao;
    }

    // Modelo pago de visão — último recurso se todos os gratuitos falharem
    private const VISAO_PAGO_FALLBACK = 'google/gemini-flash-1.5-8b';

    private function descreverImagemComVisao(string $imageUrl, string $caption = ''): string
    {
        if (! $this->openRouterKey) {
            return '[Imagem recebida — processamento de visão não configurado]';
        }

        // Modelos gratuitos atualizados diariamente + 1 pago como último recurso (≤ 3 total)
        $modelosVision = FreeModelsService::vision();
        if (count($modelosVision) < 3) {
            $modelosVision[] = self::VISAO_PAGO_FALLBACK;
        }

        $promptContexto = 'Você é um assistente de uma empresa de fretes e mudanças. '
            . 'Descreva em português o que vê na imagem de forma objetiva e prática, '
            . 'focando em: móveis, volumes, caixas, dimensões estimadas, quantidade de itens, '
            . 'condição dos objetos. Se não for relevante para frete/mudança, descreva brevemente o que vê.';

        if ($caption) {
            $promptContexto .= " O remetente adicionou a legenda: \"{$caption}\".";
        }

        try {
            // OpenRouter route=fallback tenta cada modelo em ordem até um responder
            $response = Http::withHeaders([
                'Authorization' => "Bearer {$this->openRouterKey}",
                'HTTP-Referer'  => config('app.url', 'https://app.leadcerto.app.br'),
                'X-Title'       => 'Lead Certo',
            ])->timeout(45)->post('https://openrouter.ai/api/v1/chat/completions', [
                'models' => $modelosVision,
                'route'  => 'fallback',
                'messages' => [[
                    'role'    => 'user',
                    'content' => [
                        ['type' => 'image_url', 'image_url' => ['url' => $imageUrl]],
                        ['type' => 'text',      'text'      => $promptContexto],
                    ],
                ]],
                'max_tokens' => 300,
            ]);

            if ($response->successful()) {
                $modeloUsado = $response->json('model') ?? 'desconhecido';
                Log::debug('MediaProcessor visão OK', ['modelo' => $modeloUsado]);
                return trim($response->json('choices.0.message.content') ?? '[Imagem recebida]');
            }

            Log::warning('MediaProcessor visão falhou', ['status' => $response->status(), 'body' => substr($response->body(), 0, 200)]);
        } catch (\Exception $e) {
            Log::error('MediaProcessor visão exception', ['erro' => $e->getMessage()]);
        }

        return '[Imagem recebida — não foi possível analisar o conteúdo]';
    }

    // -------------------------------------------------------------------------
    // Áudio / PTT → transcrição Whisper (via Groq)
    // -------------------------------------------------------------------------

    private function processarAudio(array $msg, string $instanceToken): string
    {
        if (! $this->groqKey) {
            return '[Áudio recebido — transcrição não configurada]';
        }

        // Áudio do WhatsApp vem criptografado (.enc) — deve ser baixado pelo Uazapi
        $midia = $this->baixarMidiaDoUazapi($instanceToken, $msg);

        if (! $midia) {
            Log::warning('MediaProcessor: não conseguiu baixar áudio', ['messageid' => $msg['messageid'] ?? null]);
            return '[Áudio recebido — não foi possível transcrever]';
        }

        $transcricao = $this->transcreverAudioBase64($midia['base64'], $midia['mime']);

        return $transcricao
            ? "[Áudio transcrito: {$transcricao}]"
            : '[Áudio recebido — não foi possível transcrever]';
    }

    private function transcreverAudioBase64(string $base64, string $mime): ?string
    {
        try {
            $audioContent = base64_decode($base64);
            $extensao     = str_contains($mime, 'ogg') ? 'ogg'
                          : (str_contains($mime, 'mp4') ? 'mp4'
                          : (str_contains($mime, 'mpeg') ? 'mp3'
                          : (str_contains($mime, 'webm') ? 'webm' : 'ogg')));

            $response = Http::withHeaders(['Authorization' => "Bearer {$this->groqKey}"])
                ->timeout(60)
                ->attach('file', $audioContent, "audio.{$extensao}")
                ->post('https://api.groq.com/openai/v1/audio/transcriptions', [
                    'model'    => 'whisper-large-v3-turbo',
                    'language' => 'pt',
                ]);

            if ($response->successful()) {
                $texto = trim($response->json('text') ?? '');
                Log::debug('MediaProcessor Whisper OK', ['chars' => strlen($texto)]);
                return $texto ?: null;
            }

            Log::warning('MediaProcessor Groq Whisper falhou', [
                'status' => $response->status(),
                'body'   => substr($response->body(), 0, 300),
            ]);
        } catch (\Exception $e) {
            Log::error('MediaProcessor transcrição exception', ['erro' => $e->getMessage()]);
        }

        return null;
    }

    // -------------------------------------------------------------------------
    // Vídeo
    // -------------------------------------------------------------------------

    private function processarVideo(array $msg, string $instanceToken): string
    {
        $caption = is_string($msg['content'] ?? null) ? ($msg['content'] ?? '') : '';
        return $caption
            ? "[Vídeo recebido com legenda: {$caption}]"
            : '[Vídeo recebido]';
    }

    // -------------------------------------------------------------------------
    // Documento
    // -------------------------------------------------------------------------

    private function processarDocumento(array $msg): string
    {
        $nomeArquivo = $msg['fileName'] ?? ($msg['filename'] ?? null);
        $caption     = is_string($msg['content'] ?? null) ? ($msg['content'] ?? '') : '';

        if ($nomeArquivo) {
            return "[Documento recebido: {$nomeArquivo}]" . ($caption ? " — {$caption}" : '');
        }

        return $caption ? "[Documento recebido: {$caption}]" : '[Documento recebido]';
    }

    // -------------------------------------------------------------------------
    // Helpers: extração de URL
    // -------------------------------------------------------------------------

    /**
     * Tenta extrair URL de mídia do payload Uazapi.
     * O campo `content` chega como objeto já decodificado pelo Laravel (array PHP)
     * na maioria dos casos — {"URL":"https://mmg.whatsapp.net/...","mimetype":"..."} —
     * mas trata também o caso de vir como string JSON, por segurança.
     * Arquivos de áudio têm URL com extensão .enc (criptografados) — devem ir via baixarMidiaDoUazapi.
     */
    private function extrairUrl(array $msg): ?string
    {
        // Campos diretos (raramente preenchidos pela Uazapi)
        foreach (['fileUrl', 'mediaUrl', 'url', 'imageUrl', 'audioUrl'] as $campo) {
            if (! empty($msg[$campo]) && str_starts_with($msg[$campo], 'http')) {
                return $msg[$campo];
            }
        }

        $content = $msg['content'] ?? null;

        if (is_string($content)) {
            // content como URL direta
            if (str_starts_with($content, 'http')) {
                return $content;
            }

            // content como string JSON: {"URL":"https://...","mimetype":"..."}
            $content = json_decode($content, true);
        }

        if (is_array($content)) {
            foreach (['URL', 'url', 'directPath', 'mediaUrl'] as $key) {
                if (! empty($content[$key]) && is_string($content[$key]) && str_starts_with($content[$key], 'http')) {
                    return $content[$key];
                }
            }
        }

        return null;
    }

    /**
     * Baixa mídia via endpoint Uazapi — necessário para arquivos criptografados (.enc).
     * Retorna ['base64' => '...', 'mime' => 'audio/ogg'] ou null.
     */
    private function baixarMidiaDoUazapi(string $instanceToken, array $msg): ?array
    {
        if (! $instanceToken || ! $this->uazapiBaseUrl) {
            return null;
        }

        $messageId = $msg['messageid'] ?? null;
        $chatId    = $msg['chatid'] ?? null;

        if (! $messageId) {
            return null;
        }

        // Mime type do content JSON para usar como fallback
        $mimeDefault = 'application/octet-stream';
        $rawContent  = $msg['content'] ?? '{}';
        $contentJson = is_array($rawContent) ? $rawContent : json_decode($rawContent, true);
        if (is_array($contentJson) && ! empty($contentJson['mimetype'])) {
            $mimeDefault = $contentJson['mimetype'];
        }

        // Tenta múltiplos endpoints de download da Uazapi
        $endpoints = [
            ['method' => 'post', 'path' => '/message/download', 'body' => ['messageId' => $messageId, 'chatId' => $chatId]],
            ['method' => 'post', 'path' => '/download',         'body' => ['messageId' => $messageId, 'chatId' => $chatId]],
            ['method' => 'get',  'path' => "/download/{$messageId}", 'body' => []],
        ];

        foreach ($endpoints as $ep) {
            try {
                $req = Http::withHeaders(['token' => $instanceToken])->timeout(20);
                $response = $ep['method'] === 'get'
                    ? $req->get("{$this->uazapiBaseUrl}{$ep['path']}")
                    : $req->post("{$this->uazapiBaseUrl}{$ep['path']}", $ep['body']);

                if ($response->successful()) {
                    $base64 = $response->json('base64') ?? $response->json('data') ?? $response->json('file');
                    $mime   = $response->json('mimetype') ?? $response->json('mimeType') ?? $mimeDefault;

                    if ($base64) {
                        return ['base64' => $base64, 'mime' => $mime];
                    }

                    // Resposta pode ser o binário direto
                    if (strlen($response->body()) > 100) {
                        return [
                            'base64' => base64_encode($response->body()),
                            'mime'   => $response->header('Content-Type') ?: $mimeDefault,
                        ];
                    }
                }
            } catch (\Exception $e) {
                Log::debug('MediaProcessor endpoint falhou', ['endpoint' => $ep['path'], 'erro' => $e->getMessage()]);
            }
        }

        Log::warning('MediaProcessor: não conseguiu baixar mídia do Uazapi', ['messageId' => $messageId]);
        return null;
    }

    private function baixarUrlViaUazapi(string $instanceToken, array $msg): ?string
    {
        $midia = $this->baixarMidiaDoUazapi($instanceToken, $msg);
        if (! $midia) return null;
        return "data:{$midia['mime']};base64,{$midia['base64']}";
    }

    /**
     * Baixa a mídia (via Uazapi) e salva permanentemente em storage/public,
     * retornando uma URL própria — as URLs diretas do WhatsApp (mmg.whatsapp.net)
     * expiram, então não servem pra exibir depois no histórico da conversa.
     * Retorna null se não conseguir baixar nem achar uma URL direta como fallback.
     */
    public function baixarEPersistirUrl(array $msg, string $instanceToken, string $mediaType): ?string
    {
        $midia = $this->baixarMidiaDoUazapi($instanceToken, $msg);

        if (! $midia) {
            // Fallback: URL direta do payload (pode expirar, mas é melhor que nada)
            return $this->extrairUrl($msg);
        }

        $extensao = $this->extensaoPorMime($midia['mime'], $mediaType);
        $caminho  = 'kanban-midia/recebida-' . \Illuminate\Support\Str::random(24) . '.' . $extensao;

        \Illuminate\Support\Facades\Storage::disk('public')->put($caminho, base64_decode($midia['base64']));

        return url('storage/' . $caminho);
    }

    private function extensaoPorMime(string $mime, string $mediaType): string
    {
        return match (true) {
            str_contains($mime, 'jpeg') => 'jpg',
            str_contains($mime, 'png')  => 'png',
            str_contains($mime, 'webp') => 'webp',
            str_contains($mime, 'gif')  => 'gif',
            str_contains($mime, 'ogg')  => 'ogg',
            str_contains($mime, 'mp4')  => 'mp4',
            str_contains($mime, 'mpeg') => 'mp3',
            str_contains($mime, 'webm') => 'webm',
            $mediaType === 'image' => 'jpg',
            $mediaType === 'audio' => 'ogg',
            default => 'bin',
        };
    }
}
