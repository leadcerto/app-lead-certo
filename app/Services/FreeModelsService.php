<?php

namespace App\Services;

/**
 * Fonte centralizada de modelos gratuitos do OpenRouter.
 *
 * O arquivo JSON em storage/app/openrouter-free-models.json é regenerado todo dia
 * às 00:01 pelo comando openrouter:atualizar-modelos.
 * Se o arquivo ainda não existir, retorna os defaults hardcoded abaixo.
 */
class FreeModelsService
{
    private const FILE = 'openrouter-free-models.json';

    // Máximo de modelos enviados por chamada (limite da API OpenRouter)
    private const MAX = 3;

    // Fallback caso o arquivo JSON ainda não exista ou esteja vazio
    private const DEFAULT_TEXT = [
        'google/gemma-4-31b-it:free',
        'meta-llama/llama-3.3-70b-instruct:free',
        'openai/gpt-oss-120b:free',
    ];

    // Vision: modelos com suporte a imagens; MediaProcessorService adiciona 1 pago como último recurso
    private const DEFAULT_VISION = [
        'nvidia/nemotron-nano-12b-v2-vl:free',
    ];

    public static function text(): array
    {
        return self::load('text', self::DEFAULT_TEXT);
    }

    public static function vision(): array
    {
        return self::load('vision', self::DEFAULT_VISION);
    }

    private static function load(string $tipo, array $fallback): array
    {
        $path = storage_path('app/' . self::FILE);

        if (! file_exists($path)) {
            return $fallback;
        }

        $data    = json_decode(file_get_contents($path), true) ?? [];
        $modelos = $data[$tipo] ?? [];

        return ! empty($modelos)
            ? array_values(array_slice($modelos, 0, self::MAX))
            : $fallback;
    }
}
