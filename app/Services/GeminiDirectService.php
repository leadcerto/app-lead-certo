<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GeminiDirectService
{
    private const BASE_URL = 'https://generativelanguage.googleapis.com/v1beta/models';

    /**
     * Envia uma conversa para a API direta do Google Gemini Pro e retorna o texto gerado.
     *
     * @param  array       $messages   Formato: [['role' => 'system|user|assistant|model', 'content' => '...']]
     * @param  string|null $apiKey     Chave de API do Gemini (se nula, usa do config ou .env)
     * @param  string      $modelo     'gemini-1.5-pro' | 'gemini-2.0-flash' | 'gemini-1.5-flash'
     * @param  int         $maxTokens  Máximo de tokens na resposta
     * @param  string|null $origem     Identificador da funcionalidade (ex: sdr, suporte, agente)
     * @param  int|null    $tenantId   Tenant para vincular o log
     * @param  int|null    $agenteId   ID do usuário/agente de IA
     */
    public function chat(
        array $messages,
        ?string $apiKey = null,
        string $modelo = 'gemini-1.5-pro',
        int $maxTokens = 400,
        ?string $origem = null,
        ?int $tenantId = null,
        ?int $agenteId = null
    ): ?string {
        $agenteId ??= AgenteIaResolver::resolverAgenteId($origem, $tenantId);

        $key = $apiKey ?: config('services.gemini.key', env('GEMINI_API_KEY', ''));

        if (empty($key)) {
            Log::warning('GeminiDirectService: API Key do Gemini não configurada.');
            return null;
        }

        // Separa a instrução de sistema dos turnos de conversa
        $systemInstruction = null;
        $contents = [];

        foreach ($messages as $msg) {
            $role = strtolower($msg['role'] ?? 'user');
            $text = $msg['content'] ?? '';

            if ($role === 'system') {
                $systemInstruction = [
                    'parts' => [['text' => $text]],
                ];
            } else {
                $geminiRole = in_array($role, ['assistant', 'model', 'bot'], true) ? 'model' : 'user';
                $contents[] = [
                    'role'  => $geminiRole,
                    'parts' => [['text' => $text]],
                ];
            }
        }

        // Se não houver mensagens de conteúdo, cria uma padrão
        if (empty($contents)) {
            $contents[] = [
                'role'  => 'user',
                'parts' => [['text' => 'Olá']],
            ];
        }

        $payload = [
            'contents'         => $contents,
            'generationConfig' => [
                'temperature'     => 0.4,
                'maxOutputTokens' => $maxTokens,
            ],
        ];

        if ($systemInstruction) {
            $payload['system_instruction'] = $systemInstruction;
        }

        $url = self::BASE_URL . "/{$modelo}:generateContent?key={$key}";
        $inicio = now();

        try {
            $response = Http::timeout(45)->post($url, $payload);
            $latencia = (int) $inicio->diffInMilliseconds(now());

            if ($response->failed()) {
                Log::error('GeminiDirectService falhou', [
                    'status' => $response->status(),
                    'body'   => $response->body(),
                ]);
                return null;
            }

            $json = $response->json();
            $textoResposta = $json['candidates'][0]['content']['parts'][0]['text'] ?? null;

            $promptTokens = $json['usageMetadata']['promptTokenCount'] ?? 0;
            $outputTokens = $json['usageMetadata']['candidatesTokenCount'] ?? 0;

            $this->logUsage($modelo, $promptTokens, $outputTokens, $latencia, $origem, $tenantId, $agenteId);

            return $textoResposta;
        } catch (\Exception $e) {
            Log::error('GeminiDirectService exceção', ['erro' => $e->getMessage()]);
            return null;
        }
    }

    private function logUsage(
        string $modelo,
        int $inputTokens,
        int $outputTokens,
        int $latencia,
        ?string $origem,
        ?int $tenantId,
        ?int $agenteId
    ): void {
        try {
            DB::table('ia_usages')->insert([
                'tenant_id'     => $tenantId,
                'agente_id'     => $agenteId,
                'modelo'        => $modelo,
                'provedor'      => 'gemini_direto',
                'tier'          => 'pro',
                'tokens_input'  => $inputTokens,
                'tokens_output' => $outputTokens,
                'latencia_ms'   => $latencia,
                'origem'        => $origem,
                'created_at'    => now(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('GeminiDirectService: falha ao logar usage', ['erro' => $e->getMessage()]);
        }
    }
}
