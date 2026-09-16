<?php

namespace Tests\Feature;

use App\Services\OpenRouterService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OpenRouterServiceCreditoTest extends TestCase
{
    use RefreshDatabase;

    private const CACHE_KEY = OpenRouterService::CACHE_KEY_SEM_CREDITO;

    public function test_falha_402_por_falta_de_credito_marca_alerta_no_cache(): void
    {
        Http::fake([
            'openrouter.ai/*' => Http::response([
                'error' => ['message' => 'This request requires more credits, or fewer max_tokens.'],
            ], 402),
        ]);

        $resposta = app(OpenRouterService::class)->chat([['role' => 'user', 'content' => 'oi']]);

        $this->assertNull($resposta);
        $alerta = Cache::get(self::CACHE_KEY);
        $this->assertNotNull($alerta);
        $this->assertSame('This request requires more credits, or fewer max_tokens.', $alerta['mensagem']);
        $this->assertNotEmpty($alerta['desde']);
    }

    public function test_falha_generica_nao_relacionada_a_credito_nao_marca_alerta(): void
    {
        Http::fake([
            'openrouter.ai/*' => Http::response(['error' => ['message' => 'Model overloaded']], 503),
        ]);

        app(OpenRouterService::class)->chat([['role' => 'user', 'content' => 'oi']]);

        $this->assertNull(Cache::get(self::CACHE_KEY));
    }

    public function test_sucesso_apos_falha_limpa_o_alerta_do_cache(): void
    {
        Cache::put(self::CACHE_KEY, ['desde' => now()->toIso8601String(), 'mensagem' => 'x'], now()->addDay());

        Http::fake([
            'openrouter.ai/*' => Http::response([
                'choices' => [['message' => ['content' => 'Olá!']]],
                'usage'   => ['prompt_tokens' => 5, 'completion_tokens' => 2],
            ], 200),
        ]);

        $resposta = app(OpenRouterService::class)->chat([['role' => 'user', 'content' => 'oi']]);

        $this->assertSame('Olá!', $resposta);
        $this->assertNull(Cache::get(self::CACHE_KEY));
    }

    public function test_mantem_a_data_original_desde_em_falhas_402_repetidas(): void
    {
        Http::fake([
            'openrouter.ai/*' => Http::response(['error' => ['message' => 'sem credito']], 402),
        ]);

        app(OpenRouterService::class)->chat([['role' => 'user', 'content' => 'primeira']]);
        $primeiraDesde = Cache::get(self::CACHE_KEY)['desde'];

        $this->travelTo(now()->addMinutes(10));
        app(OpenRouterService::class)->chat([['role' => 'user', 'content' => 'segunda']]);

        $this->assertSame($primeiraDesde, Cache::get(self::CACHE_KEY)['desde']);
    }
}
