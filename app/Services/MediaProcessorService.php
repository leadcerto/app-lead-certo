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

    /**
     * Ordem de tentativa para visão:
     * 1-6: modelos gratuitos (OpenRouter free tier, rate-limited mas sem custo)
     * 7:   fallback pago de baixo custo (~$0.01 por 100 imagens)
     */
    private const MODELOS_VISAO = [
        'google/gemini-2.0-flash-001:free',
        'google/gemini-flash-1.5:free',
        'meta-llama/llama-3.2-90b-vision-instruct:free',
        'meta-llama/llama-3.2-11b-vision-instruct:free',
        'qwen/qwen2.5-vl-72b-instruct:free',
        'microsoft/phi-4-multimodal-instruct:free',
        // Fallback pago — custo mínimo, só acionado se todos os gratuitos falharem
        'google/gemini-flash-1.5-8b',
    ];

    private function descreverImagemComVisao(string $imageUrl, string $caption = ''): string
    {
        if (! $this->openRouterKey) {
            return '[Imagem recebida — processamento de visão não configurado]';
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
                'models' => self::MODELOS_VISAO,
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
        $audioUrl = $this->extrairUrl($msg) ?? $this->baixarUrlViaUazapi($instanceToken, $msg);

        if (! $audioUrl) {
            Log::warning('MediaProcessor: não encontrou URL de áudio', ['msg' => array_keys($msg)]);
            return '[Áudio recebido — não foi possível transcrever]';
        }

        $transcricao = $this->transcreverAudio($audioUrl);

        if (! $transcricao) {
            return '[Áudio recebido — não foi possível transcrever]';
        }

        return "[Áudio transcrito: {$transcricao}]";
    }

    private function transcreverAudio(string $audioUrl): ?string
    {
        if (! $this->groqKey) {
            Log::warning('MediaProcessor: GROQ_KEY não configurada');
            return null;
        }

        try {
            // Baixa o arquivo de áudio
            $audioResponse = Http::timeout(30)->get($audioUrl);
            if (! $audioResponse->successful()) {
                Log::warning('MediaProcessor: falha ao baixar áudio', ['url' => $audioUrl]);
                return null;
            }

            $audioContent  = $audioResponse->body();
            $contentType   = $audioResponse->header('Content-Type') ?: 'audio/ogg';
            $extensao      = str_contains($contentType, 'ogg') ? 'ogg'
                           : (str_contains($contentType, 'mp4') ? 'mp4'
                           : (str_contains($contentType, 'mpeg') ? 'mp3' : 'ogg'));

            // Transcreve com Groq Whisper
            $response = Http::withHeaders(['Authorization' => "Bearer {$this->groqKey}"])
                ->timeout(60)
                ->attach('file', $audioContent, "audio.{$extensao}")
                ->post('https://api.groq.com/openai/v1/audio/transcriptions', [
                    'model'    => 'whisper-large-v3-turbo',
                    'language' => 'pt',
                ]);

            if ($response->successful()) {
                return trim($response->json('text') ?? '');
            }

            Log::warning('MediaProcessor Groq Whisper falhou', ['status' => $response->status(), 'body' => substr($response->body(), 0, 300)]);
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
     * Tenta extrair URL de mídia diretamente do payload da Uazapi.
     * A Uazapi pode enviar a URL nos campos: fileUrl, mediaUrl, url, ou no content (se for string http).
     */
    private function extrairUrl(array $msg): ?string
    {
        // Campos comuns onde a Uazapi pode colocar a URL
        foreach (['fileUrl', 'mediaUrl', 'url', 'imageUrl', 'audioUrl'] as $campo) {
            if (! empty($msg[$campo]) && str_starts_with($msg[$campo], 'http')) {
                return $msg[$campo];
            }
        }

        // content às vezes vem como URL direto
        $content = $msg['content'] ?? null;
        if (is_string($content) && str_starts_with($content, 'http')) {
            return $content;
        }

        return null;
    }

    /**
     * Usa a API da Uazapi para obter URL de download da mídia pelo messageId.
     */
    private function baixarUrlViaUazapi(string $instanceToken, array $msg): ?string
    {
        if (! $instanceToken || ! $this->uazapiBaseUrl) {
            return null;
        }

        $messageId = $msg['messageid'] ?? null;
        $chatId    = $msg['chatid'] ?? null;

        if (! $messageId) {
            return null;
        }

        try {
            // Tenta endpoint de download da Uazapi
            $response = Http::withHeaders(['token' => $instanceToken])
                ->timeout(15)
                ->post("{$this->uazapiBaseUrl}/message/download", [
                    'messageId' => $messageId,
                    'chatId'    => $chatId,
                ]);

            if ($response->successful()) {
                $url = $response->json('url') ?? $response->json('fileUrl') ?? $response->json('mediaUrl');
                if ($url && str_starts_with($url, 'http')) {
                    return $url;
                }

                // Se veio base64, converte para data URI
                $base64 = $response->json('base64') ?? $response->json('data');
                $mime   = $response->json('mimetype') ?? $response->json('mimeType') ?? 'application/octet-stream';
                if ($base64) {
                    return "data:{$mime};base64,{$base64}";
                }
            }

            Log::debug('MediaProcessor baixarUrlViaUazapi falhou', [
                'status' => $response->status(),
                'body'   => substr($response->body(), 0, 200),
            ]);
        } catch (\Exception $e) {
            Log::error('MediaProcessor baixarUrlViaUazapi exception', ['erro' => $e->getMessage()]);
        }

        return null;
    }
}
