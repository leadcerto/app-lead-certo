<?php

namespace Tests\Feature;

use App\Services\OpenRouterImageService;
use App\Services\OpenRouterService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OpenRouterImageServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_gerar_decodifica_base64_e_retorna_bytes_crus(): void
    {
        $bytesOriginais = 'conteudo-fake-de-imagem-png';

        Http::fake([
            'https://openrouter.ai/api/v1/images' => Http::response([
                'data' => [
                    ['b64_json' => base64_encode($bytesOriginais), 'media_type' => 'image/png'],
                ],
                'usage' => ['cost' => 0.02],
            ], 200),
        ]);

        $resultado = app(OpenRouterImageService::class)->gerar('foto de teste', ['https://exemplo.com/ref.jpg'], '4:3', 1);

        $this->assertCount(1, $resultado);
        $this->assertSame($bytesOriginais, $resultado[0]);

        Http::assertSent(function ($request) {
            $body = $request->data();
            return $body['prompt'] === 'foto de teste'
                && $body['aspect_ratio'] === '4:3'
                && $body['n'] === 1
                && ($body['input_references'][0]['image_url']['url'] ?? null) === 'https://exemplo.com/ref.jpg';
        });
    }

    public function test_gerar_multiplas_imagens_retorna_todos_os_bytes(): void
    {
        Http::fake([
            'https://openrouter.ai/api/v1/images' => Http::response([
                'data' => [
                    ['b64_json' => base64_encode('imagem-1')],
                    ['b64_json' => base64_encode('imagem-2')],
                    ['b64_json' => base64_encode('imagem-3')],
                ],
            ], 200),
        ]);

        $resultado = app(OpenRouterImageService::class)->gerar('foto', [], '1:1', 3);

        $this->assertCount(3, $resultado);
        $this->assertSame(['imagem-1', 'imagem-2', 'imagem-3'], $resultado);
    }

    public function test_falha_retorna_array_vazio_sem_lancar_excecao(): void
    {
        Http::fake([
            'https://openrouter.ai/api/v1/images' => Http::response(['error' => ['message' => 'erro qualquer']], 500),
        ]);

        $resultado = app(OpenRouterImageService::class)->gerar('foto', [], '4:3', 1);

        $this->assertSame([], $resultado);
    }

    /**
     * Reaproveita a MESMA chave de cache que OpenRouterService::chat() usa
     * pro alerta de "sem crédito" no dashboard — esgotar crédito gerando
     * imagem precisa acender o mesmo aviso que esgotar gerando texto.
     */
    public function test_falha_402_marca_sem_credito_na_mesma_chave_do_chat(): void
    {
        Http::fake([
            'https://openrouter.ai/api/v1/images' => Http::response(['error' => ['message' => 'Sem créditos']], 402),
        ]);

        app(OpenRouterImageService::class)->gerar('foto', [], '4:3', 1);

        $alerta = Cache::get(OpenRouterService::CACHE_KEY_SEM_CREDITO);
        $this->assertNotNull($alerta);
        $this->assertSame('Sem créditos', $alerta['mensagem']);
    }
}
