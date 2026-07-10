<?php

namespace Tests\Feature;

use App\Services\UazapiService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class UazapiServiceMenuTest extends TestCase
{
    public function test_enviar_menu_botoes_monta_payload_correto(): void
    {
        Http::fake(['*/send/menu' => Http::response(['id' => 'msg123'], 200)]);

        $service = app(UazapiService::class);

        $ok = $service->enviarMenuBotoes(
            'token-abc',
            '5511999999999',
            'Como podemos ajudar?',
            ['Suporte Técnico|suporte', 'Fazer Pedido|pedido', 'Não tenho interesse|opt_out'],
            'Escolha uma opção'
        );

        $this->assertTrue($ok);

        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/send/menu')
                && $request['type'] === 'button'
                && $request['number'] === '5511999999999'
                && $request['text'] === 'Como podemos ajudar?'
                && $request['footerText'] === 'Escolha uma opção'
                && $request['choices'] === ['Suporte Técnico|suporte', 'Fazer Pedido|pedido', 'Não tenho interesse|opt_out']
                && $request->hasHeader('token', 'token-abc');
        });
    }

    public function test_enviar_menu_botoes_retorna_false_em_falha(): void
    {
        Http::fake(['*/send/menu' => Http::response(['error' => 'bad request'], 400)]);

        $service = app(UazapiService::class);

        $ok = $service->enviarMenuBotoes('token-abc', '5511999999999', 'Oi', ['A|a']);

        $this->assertFalse($ok);
    }
}
