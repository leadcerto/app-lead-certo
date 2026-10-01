<?php

namespace Tests\Feature;

use App\Services\MetaService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MetaServiceObterPerfilUsuarioTest extends TestCase
{
    public function test_obter_perfil_usuario_retorna_id_e_nome_quando_a_api_responde_ok(): void
    {
        Http::fake([
            'graph.facebook.com/*' => Http::response(['id' => '123456', 'name' => 'Leonardo Teste'], 200),
        ]);

        $service = app(MetaService::class);
        $perfil  = $service->obterPerfilUsuario('token-valido');

        $this->assertSame('123456', $perfil['id']);
        $this->assertSame('Leonardo Teste', $perfil['name']);
    }

    public function test_obter_perfil_usuario_retorna_null_quando_a_api_falha(): void
    {
        Http::fake([
            'graph.facebook.com/*' => Http::response(['error' => ['message' => 'Invalid token']], 400),
        ]);

        $service = app(MetaService::class);
        $perfil  = $service->obterPerfilUsuario('token-invalido');

        $this->assertNull($perfil);
    }

    public function test_obter_perfil_usuario_retorna_null_quando_a_requisicao_lanca_excecao(): void
    {
        Http::fake(function () {
            throw new \Illuminate\Http\Client\ConnectionException('timeout');
        });

        $service = app(MetaService::class);
        $perfil  = $service->obterPerfilUsuario('token-qualquer');

        $this->assertNull($perfil);
    }
}
