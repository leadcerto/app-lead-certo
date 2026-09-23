<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Gera imagens novas via a Unified Image API do OpenRouter (lançada em
 * 06/2026), usada pra criar fotos de fundo novas no mesmo estilo das fotos
 * de referência que o usuário sobe na Galeria de Imagens. Nunca gera a
 * máscara/marca em si — isso é sempre um PNG fixo (ver ImagemMascara),
 * só a foto de fundo passa pela IA.
 */
class OpenRouterImageService
{
    private const URL = 'https://openrouter.ai/api/v1/images';

    private string $key;
    private string $modelo;

    public function __construct()
    {
        $this->key    = config('services.openrouter.key', '');
        $this->modelo = config('services.openrouter.modelo_imagem', 'bytedance-seed/seedream-4.5');
    }

    /**
     * @param  string[]  $urlsReferencia  URLs públicas de fotos existentes pra guiar o estilo (opcional).
     * @return string[]  Bytes crus (PNG/JPEG) de cada imagem gerada — vazio se a geração falhar.
     */
    public function gerar(string $prompt, array $urlsReferencia = [], string $aspectRatio = '4:3', int $quantidade = 1): array
    {
        $body = [
            'model'        => $this->modelo,
            'prompt'       => $prompt,
            'aspect_ratio' => $aspectRatio,
            'n'            => max(1, min($quantidade, 10)),
        ];

        if (! empty($urlsReferencia)) {
            $body['input_references'] = array_map(
                fn (string $url) => ['type' => 'image_url', 'image_url' => ['url' => $url]],
                $urlsReferencia
            );
        }

        try {
            $response = Http::withToken($this->key)
                ->timeout(120)
                ->post(self::URL, $body);

            if ($response->failed()) {
                Log::error('OpenRouterImageService: falha ao gerar imagem', [
                    'status' => $response->status(), 'body' => $response->body(),
                ]);

                // Mesma chave de cache do OpenRouterService::chat() — o dashboard já lê
                // esse alerta hoje, então esgotar crédito via geração de imagem também
                // acende o mesmo aviso, sem precisar duplicar a checagem em outro lugar.
                if ($response->status() === 402) {
                    Cache::put(OpenRouterService::CACHE_KEY_SEM_CREDITO, [
                        'desde'        => Cache::get(OpenRouterService::CACHE_KEY_SEM_CREDITO)['desde'] ?? now()->toIso8601String(),
                        'ultima_falha' => now()->toIso8601String(),
                        'mensagem'     => $response->json('error.message') ?? 'Créditos insuficientes na OpenRouter.',
                    ], now()->addDay());
                }

                return [];
            }

            $dados = $response->json('data', []);
            $custo = $response->json('usage.cost');

            Log::info('OpenRouterImageService: imagem(ns) gerada(s)', [
                'modelo' => $this->modelo, 'quantidade' => count($dados), 'custo' => $custo,
            ]);

            return array_values(array_filter(array_map(
                fn (array $item) => isset($item['b64_json']) ? base64_decode($item['b64_json']) : null,
                $dados
            )));
        } catch (\Exception $e) {
            Log::error('OpenRouterImageService: exception', ['erro' => $e->getMessage()]);
            return [];
        }
    }
}
