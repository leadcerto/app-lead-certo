<?php

namespace Tests\Feature;

use App\Models\GoogleToken;
use App\Models\Tenant;
use App\Services\GoogleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GoogleServiceRenovacaoTest extends TestCase
{
    use RefreshDatabase;

    private function criarToken(Tenant $tenant, array $extra = []): GoogleToken
    {
        return GoogleToken::create(array_merge([
            'tenant_id'     => $tenant->id,
            'google_email'  => 'teste@gmail.com',
            'access_token'  => 'access-antigo',
            'refresh_token' => 'refresh-token-123',
            'token_type'    => 'Bearer',
            'expires_at'    => Carbon::now()->subMinute(),
            'scopes'        => ['contacts'],
        ], $extra));
    }

    public function test_renovacao_com_sucesso_atualiza_token_e_limpa_falha_anterior(): void
    {
        Http::fake([
            'oauth2.googleapis.com/token' => Http::response([
                'access_token' => 'access-novo',
                'expires_in'   => 3600,
            ], 200),
        ]);

        $tenant = Tenant::factory()->create();
        $token  = $this->criarToken($tenant, ['falha_renovacao_em' => Carbon::now()->subDay()]);

        $ok = app(GoogleService::class)->renovarToken($token);

        $this->assertTrue($ok);
        $token->refresh();
        $this->assertSame('access-novo', $token->access_token);
        $this->assertNull($token->falha_renovacao_em);
    }

    public function test_renovacao_com_invalid_grant_marca_falha(): void
    {
        Http::fake([
            'oauth2.googleapis.com/token' => Http::response(['error' => 'invalid_grant'], 400),
        ]);

        $tenant = Tenant::factory()->create();
        $token  = $this->criarToken($tenant);

        $ok = app(GoogleService::class)->renovarToken($token);

        $this->assertFalse($ok);
        $token->refresh();
        $this->assertNotNull($token->falha_renovacao_em);
    }

    public function test_banner_de_reconexao_aparece_quando_falha_esta_marcada(): void
    {
        $tenant = Tenant::factory()->create();
        $user   = \App\Models\User::factory()->create(['tenant_id' => $tenant->id, 'perfil' => 'dono', 'ativo' => true]);
        $this->criarToken($tenant, ['falha_renovacao_em' => now()]);

        $response = $this->actingAs($user)->get('/kanban');

        $response->assertSee('Conexão com o Google caiu');
        $response->assertSee('Reconectar agora');
    }

    public function test_banner_nao_aparece_quando_conexao_esta_ok(): void
    {
        $tenant = Tenant::factory()->create();
        $user   = \App\Models\User::factory()->create(['tenant_id' => $tenant->id, 'perfil' => 'dono', 'ativo' => true]);
        $this->criarToken($tenant, ['falha_renovacao_em' => null]);

        $response = $this->actingAs($user)->get('/kanban');

        $response->assertDontSee('Conexão com o Google caiu');
    }
}
