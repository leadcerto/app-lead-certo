<?php

namespace Tests\Feature;

use App\Models\Contato;
use App\Models\GoogleToken;
use App\Models\Tenant;
use App\Models\User;
use App\Models\VinculoContatoTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ContatoAtualizarSincronizaGoogleTest extends TestCase
{
    use RefreshDatabase;

    private function criarTenantComGoogle(): Tenant
    {
        $tenant = Tenant::factory()->create();
        GoogleToken::create([
            'tenant_id'     => $tenant->id,
            'google_email'  => 'franqueado@empresa.com',
            'access_token'  => 'access-token-teste',
            'refresh_token' => 'refresh-token-teste',
            'token_type'    => 'Bearer',
            'expires_at'    => now()->addHour(),
            'scopes'        => ['contacts'],
        ]);

        return $tenant;
    }

    public function test_editar_nome_empurra_atualizacao_pro_google_quando_ja_vinculado(): void
    {
        Http::fake(['*updateContact*' => Http::response(['etag' => 'novo-etag-123'], 200)]);

        $tenant  = $this->criarTenantComGoogle();
        $contato = Contato::factory()->create(['nome' => 'Nome Antigo']);
        VinculoContatoTenant::create([
            'contato_id'            => $contato->id,
            'tenant_id'             => $tenant->id,
            'google_resource_name'  => 'people/c123456789',
            'google_etag'           => 'etag-velho-456',
        ]);
        $user = User::factory()->create(['tenant_id' => $tenant->id, 'perfil' => 'dono', 'ativo' => true]);

        $response = $this->actingAs($user)->patchJson("/api/painel/contato/{$contato->id}", [
            'nome' => 'Nome Novo',
        ]);

        $response->assertOk();

        Http::assertSent(fn ($request) => str_contains($request->url(), 'updateContact'));

        $this->assertSame('novo-etag-123', VinculoContatoTenant::where('contato_id', $contato->id)->first()->google_etag);
    }

    public function test_nao_chama_google_quando_tenant_nao_tem_google_conectado(): void
    {
        Http::fake();

        $tenant  = Tenant::factory()->create();
        $contato = Contato::factory()->create(['nome' => 'Nome Antigo']);
        VinculoContatoTenant::create([
            'contato_id'           => $contato->id,
            'tenant_id'            => $tenant->id,
            'google_resource_name' => 'people/c123456789',
            'google_etag'          => 'etag-velho-456',
        ]);
        $user = User::factory()->create(['tenant_id' => $tenant->id, 'perfil' => 'dono', 'ativo' => true]);

        $response = $this->actingAs($user)->patchJson("/api/painel/contato/{$contato->id}", [
            'nome' => 'Nome Novo',
        ]);

        $response->assertOk();
        Http::assertNothingSent();
    }

    public function test_nao_chama_google_quando_contato_nao_esta_vinculado_ao_google(): void
    {
        Http::fake();

        $tenant  = $this->criarTenantComGoogle();
        $contato = Contato::factory()->create(['nome' => 'Nome Antigo']);
        VinculoContatoTenant::create(['contato_id' => $contato->id, 'tenant_id' => $tenant->id]);
        $user = User::factory()->create(['tenant_id' => $tenant->id, 'perfil' => 'dono', 'ativo' => true]);

        $response = $this->actingAs($user)->patchJson("/api/painel/contato/{$contato->id}", [
            'nome' => 'Nome Novo',
        ]);

        $response->assertOk();
        Http::assertNothingSent();
    }

    /**
     * Achado real (2026-08-12): o painel de edição do contato só expunha "Nome
     * completo" — o campo `sobrenome` (complemento que ajuda a identificar o
     * contato, ex: profissão) já existia no banco e já era usado no push pro
     * Google (`familyName`), mas não dava pra ver nem editar na nossa UI.
     */
    public function test_editar_sobrenome_persiste_e_empurra_pro_google_quando_ja_vinculado(): void
    {
        Http::fake(['*updateContact*' => Http::response(['etag' => 'novo-etag-789'], 200)]);

        $tenant  = $this->criarTenantComGoogle();
        $contato = Contato::factory()->create(['nome' => 'Ricardo', 'sobrenome' => null]);
        VinculoContatoTenant::create([
            'contato_id'            => $contato->id,
            'tenant_id'             => $tenant->id,
            'google_resource_name'  => 'people/c123456789',
            'google_etag'           => 'etag-velho-456',
        ]);
        $user = User::factory()->create(['tenant_id' => $tenant->id, 'perfil' => 'dono', 'ativo' => true]);

        $response = $this->actingAs($user)->patchJson("/api/painel/contato/{$contato->id}", [
            'sobrenome' => 'Encanador — indicação do seu Zé',
        ]);

        $response->assertOk();
        $this->assertSame('Encanador — indicação do seu Zé', $contato->fresh()->sobrenome);
        Http::assertSent(fn ($request) => str_contains($request->url(), 'updateContact'));
    }

    public function test_editar_apenas_profissao_nao_chama_google(): void
    {
        Http::fake();

        $tenant  = $this->criarTenantComGoogle();
        $contato = Contato::factory()->create(['nome' => 'Nome Antigo']);
        VinculoContatoTenant::create([
            'contato_id'           => $contato->id,
            'tenant_id'            => $tenant->id,
            'google_resource_name' => 'people/c123456789',
            'google_etag'          => 'etag-velho-456',
        ]);
        $user = User::factory()->create(['tenant_id' => $tenant->id, 'perfil' => 'dono', 'ativo' => true]);

        $response = $this->actingAs($user)->patchJson("/api/painel/contato/{$contato->id}", [
            'profissao' => 'Motorista',
        ]);

        $response->assertOk();
        Http::assertNothingSent();
    }
}
