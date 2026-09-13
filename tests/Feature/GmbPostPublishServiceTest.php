<?php

namespace Tests\Feature;

use App\Models\GmbPost;
use App\Models\GoogleToken;
use App\Models\PerfilGmb;
use App\Models\Tenant;
use App\Services\GmbPostPublishService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Cobre especificamente qual token Google o publicador escolhe (próprio do
 * tenant vs. fallback para a conta central da Lead Certo) — comportamento
 * decidido em 2026-09-13 (ver Tenant::CENTRAL_ID). Não cobre o restante do
 * fluxo de publicação (payload, contas do Google, erros da API).
 */
class GmbPostPublishServiceTest extends TestCase
{
    use RefreshDatabase;

    private function criarPerfilComGoogle(Tenant $tenant): PerfilGmb
    {
        return PerfilGmb::create([
            'tenant_id'          => $tenant->id,
            'nome'               => 'Frete Rio — Copacabana',
            'city'               => 'Rio de Janeiro',
            'state'              => 'RJ',
            'link_gmb'           => 'https://maps.google.com/?cid=123',
            'google_location_id' => 'locations/999888777',
            'ativo'              => true,
        ]);
    }

    private function criarToken(Tenant $tenant, string $accessToken): GoogleToken
    {
        Bus::fake([\App\Jobs\ProvisionarEtiquetasGoogleJob::class]);

        return GoogleToken::create([
            'tenant_id'     => $tenant->id,
            'google_email'  => 'x@teste.com',
            'access_token'  => $accessToken,
            'refresh_token' => 'refresh-123',
            'token_type'    => 'Bearer',
            'expires_at'    => now()->addHour(),
            'scopes'        => ['business.manage'],
        ]);
    }

    private function criarPost(PerfilGmb $perfil): GmbPost
    {
        return GmbPost::create([
            'tenant_id'     => $perfil->tenant_id,
            'perfil_gmb_id' => $perfil->id,
            'tipo'          => 'novidade',
            'texto'         => 'Post de teste',
            'data_agendada' => now(),
            'status'        => 'agendado',
        ]);
    }

    private function fakeGooglePublishSuccess(): void
    {
        Http::fake([
            'mybusinessaccountmanagement.googleapis.com/*' => Http::response(['accounts' => [['name' => 'accounts/123']]], 200),
            'mybusiness.googleapis.com/*'                  => Http::response(['name' => 'localPosts/abc', 'searchUrl' => 'https://x'], 200),
        ]);
    }

    public function test_usa_token_proprio_do_tenant_quando_existe(): void
    {
        // Tenant::CENTRAL_ID precisa existir ANTES dos outros tenants pra garantir
        // o id fixo 2 (auto-increment segue a partir do maior id ja inserido).
        $tenantCentral = Tenant::factory()->create(['id' => Tenant::CENTRAL_ID]);
        $this->criarToken($tenantCentral, 'token-central');

        $tenantCliente = Tenant::factory()->create();
        $this->criarToken($tenantCliente, 'token-do-proprio-cliente');

        session(['tenant_id' => $tenantCliente->id]);
        $perfil = $this->criarPerfilComGoogle($tenantCliente);
        $post   = $this->criarPost($perfil);

        $this->fakeGooglePublishSuccess();

        app(GmbPostPublishService::class)->publicar($post);

        Http::assertSent(fn ($req) => $req->hasHeader('Authorization', 'Bearer token-do-proprio-cliente'));
    }

    public function test_fallback_usa_especificamente_a_conta_central_lead_certo(): void
    {
        // Ordem deliberada: o token de "outro" tenant eh criado (e portanto
        // recebe id mais baixo) ANTES do token central — se o codigo caisse
        // de volta pra "pega o primeiro token da tabela" isso escolheria o
        // token errado. So um lookup por Tenant::CENTRAL_ID passa neste teste.
        $tenantOutro = Tenant::factory()->create();
        $this->criarToken($tenantOutro, 'token-de-outro-tenant-nao-deveria-ser-usado');

        $tenantCentral = Tenant::factory()->create(['id' => Tenant::CENTRAL_ID]);
        $this->criarToken($tenantCentral, 'token-central-correto');

        $tenantSemToken = Tenant::factory()->create();
        session(['tenant_id' => $tenantSemToken->id]);
        $perfil = $this->criarPerfilComGoogle($tenantSemToken);
        $post   = $this->criarPost($perfil);

        $this->fakeGooglePublishSuccess();

        app(GmbPostPublishService::class)->publicar($post);

        Http::assertSent(fn ($req) => $req->hasHeader('Authorization', 'Bearer token-central-correto'));
    }
}
