<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class OpenRouterService
{
    private const URL = 'https://openrouter.ai/api/v1/chat/completions';

    private string $key;
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
     * @param  array       $messages  Formato OpenAI: [['role' => 'system|user|assistant', 'content' => '...']]
     * @param  string      $tier      'simples' | 'complexo'
     * @param  int         $maxTokens Máximo de tokens na resposta
     * @param  string|null $origem    Identificador da funcionalidade chamadora (para ia_usages)
     * @param  int|null    $tenantId  Tenant para vincular o log
     */
    public function chat(array $messages, string $tier = 'simples', int $maxTokens = 400, ?string $origem = null, ?int $tenantId = null): ?string
    {
        $modelo = $tier === 'complexo' ? $this->modeloComplexo : $this->modeloSimples;

        $inicio = now();

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

            $latencia = (int) $inicio->diffInMilliseconds(now());

            if ($response->failed()) {
                Log::error('OpenRouter falhou', ['status' => $response->status(), 'body' => $response->body()]);
                return null;
            }

            $usage = $response->json('usage', []);
            $this->logUsage($modelo, $tier, $usage, $latencia, $origem, $tenantId);

            return $response->json('choices.0.message.content');
        } catch (\Exception $e) {
            Log::error('OpenRouter exception', ['erro' => $e->getMessage()]);
            return null;
        }
    }

    private function logUsage(string $modelo, string $tier, array $usage, int $latencia, ?string $origem, ?int $tenantId): void
    {
        try {
            DB::table('ia_usages')->insert([
                'tenant_id'     => $tenantId,
                'modelo'        => $modelo,
                'tier'          => $tier,
                'tokens_input'  => $usage['prompt_tokens'] ?? 0,
                'tokens_output' => $usage['completion_tokens'] ?? 0,
                'latencia_ms'   => $latencia,
                'origem'        => $origem,
                'created_at'    => now(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('OpenRouter: falha ao logar usage', ['erro' => $e->getMessage()]);
        }
    }
}
