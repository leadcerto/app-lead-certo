<?php

namespace Tests\Feature;

use App\Services\OpenRouterService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OpenRouterServiceFallbackTest extends TestCase
{
    use RefreshDatabase;

    public function test_envia_lista_de_modelos_com_reserva_e_route_fallback(): void
    {
        Http::fake([
            'openrouter.ai/*' => Http::response([
                'model'   => 'meta-llama/llama-3.3-70b-instruct:free',
                'choices' => [['message' => ['content' => 'Olá!']]],
                'usage'   => ['prompt_tokens' => 5, 'completion_tokens' => 2],
            ], 200),
        ]);

        app(OpenRouterService::class)->chat(
            [['role' => 'user', 'content' => 'oi']],
            modeloCustomizado: 'meta-llama/llama-3.3-70b-instruct:free',
        );

        Http::assertSent(function ($request) {
            $body = $request->data();

            return $body['models'] === ['meta-llama/llama-3.3-70b-instruct:free', OpenRouterService::MODELO_RESERVA]
                && $body['route'] === 'fallback'
                && ! isset($body['model']);
        });
    }

    public function test_nao_duplica_quando_modelo_escolhido_ja_e_o_de_reserva(): void
    {
        Http::fake([
            'openrouter.ai/*' => Http::response([
                'model'   => OpenRouterService::MODELO_RESERVA,
                'choices' => [['message' => ['content' => 'Olá!']]],
                'usage'   => ['prompt_tokens' => 5, 'completion_tokens' => 2],
            ], 200),
        ]);

        app(OpenRouterService::class)->chat(
            [['role' => 'user', 'content' => 'oi']],
            modeloCustomizado: OpenRouterService::MODELO_RESERVA,
        );

        Http::assertSent(fn ($request) => $request->data()['models'] === [OpenRouterService::MODELO_RESERVA]);
    }

    public function test_registra_o_modelo_real_usado_quando_o_fallback_entrou_em_acao(): void
    {
        Http::fake([
            'openrouter.ai/*' => Http::response([
                'model'   => OpenRouterService::MODELO_RESERVA,
                'choices' => [['message' => ['content' => 'Olá!']]],
                'usage'   => ['prompt_tokens' => 5, 'completion_tokens' => 2],
            ], 200),
        ]);

        app(OpenRouterService::class)->chat(
            [['role' => 'user', 'content' => 'oi']],
            modeloCustomizado: 'meta-llama/llama-3.3-70b-instruct:free',
        );

        $this->assertDatabaseHas('ia_usages', [
            'modelo' => OpenRouterService::MODELO_RESERVA,
        ]);
    }
}
