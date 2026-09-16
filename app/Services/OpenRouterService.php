<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class OpenRouterService
{
    private const URL = 'https://openrouter.ai/api/v1/chat/completions';

    /** Chave de cache do alerta de crédito esgotado, lida por DashboardController::dados(). */
    public const CACHE_KEY_SEM_CREDITO = 'openrouter:sem_credito';

    /**
     * Modelo de reserva enviado junto com todo pedido (route: fallback) — se o modelo
     * escolhido do agente sair do ar (descontinuado, sem endpoint grátis etc.), a própria
     * OpenRouter já tenta este na mesma chamada, sem esperar um job noturno detectar.
     * Achado real 2026-09-16: o agente Atlas ficou ~2h sem responder porque o modelo
     * configurado (`meta-llama/llama-3.3-70b-instruct:free`) saiu do plano grátis da
     * OpenRouter e nada pegava isso em tempo real.
     */
    public const MODELO_RESERVA = 'openai/gpt-4o-mini';

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
    public function chat(
        array $messages,
        string $tier = 'simples',
        int $maxTokens = 400,
        ?string $origem = null,
        ?int $tenantId = null,
        ?int $agenteId = null,
        ?string $modeloCustomizado = null
    ): ?string
    {
        $agenteId ??= AgenteIaResolver::resolverAgenteId($origem, $tenantId);

        $modelo = $modeloCustomizado;
        if (! $modelo && $agenteId) {
            $ag = \App\Models\User::find($agenteId);
            if ($ag?->openrouter_modelo) {
                $modelo = $ag->openrouter_modelo;
            }
        }

        if (! $modelo) {
            $modelo = $tier === 'complexo' ? $this->modeloComplexo : $this->modeloSimples;
        }

        $modelos = array_values(array_unique([$modelo, self::MODELO_RESERVA]));

        $inicio = now();

        try {
            $response = Http::withHeaders([
                'Authorization' => "Bearer {$this->key}",
                'HTTP-Referer'  => config('app.url'),
                'X-Title'       => 'Lead Certo',
            ])->post(self::URL, [
                'models'      => $modelos,
                'route'       => 'fallback',
                'temperature' => 0.4,
                'max_tokens'  => $maxTokens,
                'messages'    => $messages,
            ]);

            $latencia = (int) $inicio->diffInMilliseconds(now());

            if ($response->failed()) {
                Log::error('OpenRouter falhou', ['status' => $response->status(), 'body' => $response->body(), 'modelos_tentados' => $modelos]);

                if ($response->status() === 402) {
                    $this->marcarSemCredito($response->json('error.message') ?? 'Créditos insuficientes na OpenRouter.');
                }

                return null;
            }

            Cache::forget(self::CACHE_KEY_SEM_CREDITO);

            // A OpenRouter devolve qual modelo da lista realmente respondeu (route: fallback
            // pode ter pulado pro reserva) — logar esse, não o que pedimos, senão o uso fica
            // atribuído ao modelo errado.
            $modeloUsado = $response->json('model') ?: $modelo;

            $usage = $response->json('usage', []);
            $this->logUsage($modeloUsado, $tier, $usage, $latencia, $origem, $tenantId, $agenteId);

            return $response->json('choices.0.message.content');
        } catch (\Exception $e) {
            Log::error('OpenRouter exception', ['erro' => $e->getMessage()]);
            return null;
        }
    }

    private function marcarSemCredito(string $mensagem): void
    {
        $existente = Cache::get(self::CACHE_KEY_SEM_CREDITO);

        Cache::put(self::CACHE_KEY_SEM_CREDITO, [
            'desde'        => $existente['desde'] ?? now()->toIso8601String(),
            'ultima_falha' => now()->toIso8601String(),
            'mensagem'     => $mensagem,
        ], now()->addDay());
    }

    private function logUsage(string $modelo, string $tier, array $usage, int $latencia, ?string $origem, ?int $tenantId, ?int $agenteId): void
    {
        try {
            DB::table('ia_usages')->insert([
                'tenant_id'     => $tenantId,
                'agente_id'     => $agenteId,
                'modelo'        => $modelo,
                'provedor'      => 'openrouter',
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
