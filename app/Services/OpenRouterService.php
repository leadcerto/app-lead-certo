<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class OpenRouterService
{
    private const URL = 'https://openrouter.ai/api/v1/chat/completions';

    private string $key;

    // Configurável via OPENROUTER_MODELO_SIMPLES / OPENROUTER_MODELO_COMPLEXO no .env
    private string $modeloSimples;
    private string $modeloComplexo;

    public function __construct()
    {
        $this->key            = config('services.openrouter.key', '');
        $this->modeloSimples  = config('services.openrouter.modelo_simples', 'openai/gpt-4o-mini');
        $this->modeloComplexo = config('services.openrouter.modelo_complexo', 'anthropic/claude-3.5-haiku-20241022');
    }

    /**
     * Envia uma conversa para o OpenRouter e retorna o texto gerado pelo modelo.
     *
     * @param  array  $messages  Formato OpenAI: [['role' => 'system|user|assistant', 'content' => '...']]
     * @param  string $tier      'simples' | 'complexo'
     * @param  int    $maxTokens Máximo de tokens na resposta
     * @return string|null       Resposta ou null em caso de falha
     */
    public function chat(array $messages, string $tier = 'simples', int $maxTokens = 400): ?string
    {
        $modelo = $tier === 'complexo' ? $this->modeloComplexo : $this->modeloSimples;

        try {
            $response = Http::withHeaders([
                'Authorization' => "Bearer {$this->key}",
                'HTTP-Referer'  => config('app.url'),
                'X-Title'       => 'Lead Certo',
            ])->post(self::URL, [
                'model'       => $modelo,
                'temperature' => 0.4,
                'max_tokens'  => $maxTokens,
                'messages'    => $messages,
            ]);

            if ($response->failed()) {
                Log::error('OpenRouter falhou', ['status' => $response->status(), 'body' => $response->body()]);
                return null;
            }

            return $response->json('choices.0.message.content');
        } catch (\Exception $e) {
            Log::error('OpenRouter exception', ['erro' => $e->getMessage()]);
            return null;
        }
    }
}
