<?php

namespace Tests\Feature;

use App\Services\UazapiService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class UazapiServiceStickerTest extends TestCase
{
    public function test_enviar_sticker_manda_type_sticker_para_uazapi(): void
    {
        Http::fake(['*/send/media' => Http::response(['id' => 'msg123'], 200)]);

        $service = app(UazapiService::class);

        $ok = $service->enviarSticker('token-abc', '5511999999999', 'https://exemplo.com/figurinha.webp');

        $this->assertTrue($ok);

        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/send/media')
                && $request['type'] === 'sticker'
                && $request['number'] === '5511999999999'
                && $request['file'] === 'https://exemplo.com/figurinha.webp'
                && $request->hasHeader('token', 'token-abc');
        });
    }

    public function test_enviar_sticker_retorna_false_em_falha(): void
    {
        Http::fake(['*/send/media' => Http::response(['error' => 'bad request'], 400)]);

        $service = app(UazapiService::class);

        $ok = $service->enviarSticker('token-abc', '5511999999999', 'https://exemplo.com/figurinha.webp');

        $this->assertFalse($ok);
    }
}
