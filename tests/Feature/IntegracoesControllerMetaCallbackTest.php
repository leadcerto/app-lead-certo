<?php

namespace Tests\Feature;

use App\Models\MetaToken;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Vistoria de 01/10 (Leonardo): reconectar a Meta com um login do Facebook
 * diferente do que já estava salvo sobrescrevia o MetaToken em silêncio —
 * páginas/contas de Instagram vinculadas pelo login antigo podiam parar de
 * funcionar sem nenhum aviso. Estes testes cobrem a detecção de troca de
 * login e o preenchimento de meta_user_id/nome_usuario (colunas que já
 * existiam no model mas nunca eram preenchidas).
 */
class IntegracoesControllerMetaCallbackTest extends TestCase
{
    use RefreshDatabase;

    private function dono(Tenant $tenant): User
    {
        return User::factory()->create([
            'tenant_id' => $tenant->id,
            'perfil'    => 'dono',
            'ativo'     => true,
        ]);
    }

    private function fakeGraphOauthEMe(string $metaUserId, string $nome): void
    {
        Http::fake([
            'graph.facebook.com/*/oauth/access_token*' => Http::response([
                'access_token' => 'token-longa-duracao',
                'expires_in'   => 5184000,
            ], 200),
            'graph.facebook.com/*/me*' => Http::response([
                'id'   => $metaUserId,
                'name' => $nome,
            ], 200),
        ]);
    }

    public function test_primeira_conexao_salva_meta_user_id_e_nome_usuario_sem_avisar(): void
    {
        $tenant = Tenant::factory()->create();
        $dono   = $this->dono($tenant);
        $this->fakeGraphOauthEMe('111', 'Leonardo Pessoal');

        $response = $this->actingAs($dono)->get(route('meta.callback', ['code' => 'codigo-123']));

        $response->assertRedirect(route('meta.selecionar-paginas'));
        $response->assertSessionHas('sucesso');
        $response->assertSessionMissing('erro');

        $this->assertDatabaseHas('meta_tokens', [
            'tenant_id'    => $tenant->id,
            'meta_user_id' => '111',
            'nome_usuario' => 'Leonardo Pessoal',
        ]);
    }

    public function test_reconectar_com_o_mesmo_login_nao_avisa(): void
    {
        $tenant = Tenant::factory()->create();
        $dono   = $this->dono($tenant);
        MetaToken::create([
            'tenant_id'    => $tenant->id,
            'access_token' => 'token-antigo',
            'meta_user_id' => '111',
            'nome_usuario' => 'Leonardo Pessoal',
        ]);
        $this->fakeGraphOauthEMe('111', 'Leonardo Pessoal');

        $response = $this->actingAs($dono)->get(route('meta.callback', ['code' => 'codigo-123']));

        $response->assertRedirect(route('meta.selecionar-paginas'));
        $response->assertSessionHas('sucesso');
        $response->assertSessionMissing('erro');
    }

    public function test_reconectar_com_login_diferente_avisa_e_sobrescreve_o_token(): void
    {
        $tenant = Tenant::factory()->create();
        $dono   = $this->dono($tenant);
        MetaToken::create([
            'tenant_id'    => $tenant->id,
            'access_token' => 'token-antigo',
            'meta_user_id' => '111',
            'nome_usuario' => 'Leonardo Pessoal',
        ]);
        $this->fakeGraphOauthEMe('222', 'Outro Login');

        $response = $this->actingAs($dono)->get(route('meta.callback', ['code' => 'codigo-456']));

        $response->assertRedirect(route('meta.selecionar-paginas'));
        $response->assertSessionHas('erro');
        $response->assertSessionMissing('sucesso');
        $this->assertStringContainsString('login do Facebook diferente', session('erro'));
        $this->assertStringContainsString('Leonardo Pessoal', session('erro'));
        $this->assertStringContainsString('Outro Login', session('erro'));

        $this->assertDatabaseHas('meta_tokens', [
            'tenant_id'    => $tenant->id,
            'meta_user_id' => '222',
            'nome_usuario' => 'Outro Login',
        ]);
    }

    public function test_quando_a_api_de_perfil_falha_nao_quebra_e_apenas_nao_avisa_troca(): void
    {
        $tenant = Tenant::factory()->create();
        $dono   = $this->dono($tenant);
        MetaToken::create([
            'tenant_id'    => $tenant->id,
            'access_token' => 'token-antigo',
            'meta_user_id' => '111',
            'nome_usuario' => 'Leonardo Pessoal',
        ]);

        Http::fake([
            'graph.facebook.com/*/oauth/access_token*' => Http::response([
                'access_token' => 'token-longa-duracao',
                'expires_in'   => 5184000,
            ], 200),
            'graph.facebook.com/*/me*' => Http::response(['error' => ['message' => 'falhou']], 400),
        ]);

        $response = $this->actingAs($dono)->get(route('meta.callback', ['code' => 'codigo-789']));

        $response->assertRedirect(route('meta.selecionar-paginas'));
        $response->assertSessionHas('sucesso');
        $response->assertSessionMissing('erro');

        $this->assertDatabaseHas('meta_tokens', [
            'tenant_id'    => $tenant->id,
            'meta_user_id' => null,
            'nome_usuario' => null,
        ]);
    }
}
