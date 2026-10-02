<?php

namespace Tests\Feature;

use App\Models\GoogleToken;
use App\Models\Tenant;
use App\Services\SearchConsoleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Fase 1 do projeto de MCP pro Google Search Console (ver
 * leadcerto/_docs/mcp-google-search-console-projeto.md) — motivado por perda
 * de lead orgânico na Frete Rio (slug do blog mudou, ninguém percebeu a
 * tempo). Reaproveita o GoogleToken multi-tenant já existente (mesmo padrão
 * de GmbQualidadeService::buscarDadosLocation()).
 */
class SearchConsoleServiceTest extends TestCase
{
    use RefreshDatabase;

    private function criarTokenGoogle(Tenant $tenant, array $extra = []): GoogleToken
    {
        return GoogleToken::create(array_merge([
            'tenant_id'     => $tenant->id,
            'google_email'  => 'dono@teste.com',
            'access_token'  => 'token-valido',
            'refresh_token' => 'refresh-123',
            'token_type'    => 'Bearer',
            'expires_at'    => now()->addHour(),
            'scopes'        => ['webmasters.readonly'],
        ], $extra));
    }

    public function test_listar_sites_retorna_propriedades_verificadas(): void
    {
        $tenant = Tenant::factory()->create();
        $this->criarTokenGoogle($tenant);

        Http::fake([
            'www.googleapis.com/webmasters/v3/sites' => Http::response([
                'siteEntry' => [
                    ['siteUrl' => 'https://frete.rio.br/', 'permissionLevel' => 'siteOwner'],
                ],
            ], 200),
        ]);

        $resultado = app(SearchConsoleService::class)->listarSites($tenant);

        $this->assertTrue($resultado['sucesso']);
        $this->assertSame('https://frete.rio.br/', $resultado['dados'][0]['siteUrl']);
    }

    public function test_listar_sites_sem_token_conectado_retorna_motivo_claro(): void
    {
        $tenant = Tenant::factory()->create();

        $resultado = app(SearchConsoleService::class)->listarSites($tenant);

        $this->assertFalse($resultado['sucesso']);
        $this->assertStringContainsString('Integrações', $resultado['motivo']);
    }

    public function test_listar_sites_com_token_expirado_e_renovacao_falhando(): void
    {
        $tenant = Tenant::factory()->create();
        $this->criarTokenGoogle($tenant, ['expires_at' => now()->subHour()]);

        Http::fake([
            'oauth2.googleapis.com/*' => Http::response(['error' => 'invalid_grant'], 400),
        ]);

        $resultado = app(SearchConsoleService::class)->listarSites($tenant);

        $this->assertFalse($resultado['sucesso']);
        $this->assertStringContainsString('Reconecte', $resultado['motivo']);
    }

    public function test_listar_sites_propaga_erro_da_api(): void
    {
        $tenant = Tenant::factory()->create();
        $this->criarTokenGoogle($tenant);

        Http::fake([
            'www.googleapis.com/webmasters/v3/sites' => Http::response(['error' => ['message' => 'Forbidden']], 403),
        ]);

        $resultado = app(SearchConsoleService::class)->listarSites($tenant);

        $this->assertFalse($resultado['sucesso']);
        $this->assertStringContainsString('recusou', $resultado['motivo']);
    }

    public function test_consultar_analytics_usa_filtros_padrao_e_retorna_linhas(): void
    {
        $tenant = Tenant::factory()->create();
        $this->criarTokenGoogle($tenant);

        $corpoCapturado = null;
        Http::fake(function ($request) use (&$corpoCapturado) {
            if (str_contains($request->url(), 'searchAnalytics/query')) {
                $corpoCapturado = $request->data();
                return Http::response([
                    'rows' => [
                        ['keys' => ['frete mudança rio'], 'clicks' => 42, 'impressions' => 500, 'ctr' => 0.084, 'position' => 4.2],
                    ],
                ], 200);
            }
            return Http::response([], 200);
        });

        $resultado = app(SearchConsoleService::class)->consultarAnalytics($tenant, 'https://frete.rio.br/');

        $this->assertTrue($resultado['sucesso']);
        $this->assertSame(42, $resultado['dados'][0]['clicks']);
        $this->assertSame(['query'], $corpoCapturado['dimensions']);
        $this->assertSame(25, $corpoCapturado['rowLimit']);
    }

    public function test_consultar_analytics_aceita_filtros_customizados(): void
    {
        $tenant = Tenant::factory()->create();
        $this->criarTokenGoogle($tenant);

        $corpoCapturado = null;
        Http::fake(function ($request) use (&$corpoCapturado) {
            $corpoCapturado = $request->data();
            return Http::response(['rows' => []], 200);
        });

        app(SearchConsoleService::class)->consultarAnalytics($tenant, 'https://frete.rio.br/', [
            'startDate'  => '2026-09-01',
            'endDate'    => '2026-09-30',
            'dimensions' => ['page'],
            'rowLimit'   => 10,
        ]);

        $this->assertSame('2026-09-01', $corpoCapturado['startDate']);
        $this->assertSame('2026-09-30', $corpoCapturado['endDate']);
        $this->assertSame(['page'], $corpoCapturado['dimensions']);
        $this->assertSame(10, $corpoCapturado['rowLimit']);
    }

    public function test_inspecionar_url_retorna_status_de_indexacao(): void
    {
        $tenant = Tenant::factory()->create();
        $this->criarTokenGoogle($tenant);

        $corpoCapturado = null;
        Http::fake(function ($request) use (&$corpoCapturado) {
            $corpoCapturado = $request->data();
            return Http::response([
                'inspectionResult' => ['indexStatusResult' => ['verdict' => 'NEUTRAL', 'coverageState' => 'URL is not on Google']],
            ], 200);
        });

        $resultado = app(SearchConsoleService::class)->inspecionarUrl(
            $tenant,
            'https://frete.rio.br/',
            'https://frete.rio.br/blog/slug-antigo'
        );

        $this->assertTrue($resultado['sucesso']);
        $this->assertSame('URL is not on Google', $resultado['dados']['indexStatusResult']['coverageState']);
        $this->assertSame('https://frete.rio.br/blog/slug-antigo', $corpoCapturado['inspectionUrl']);
        $this->assertSame('https://frete.rio.br/', $corpoCapturado['siteUrl']);
    }

    public function test_listar_sitemaps_retorna_lista(): void
    {
        $tenant = Tenant::factory()->create();
        $this->criarTokenGoogle($tenant);

        Http::fake([
            '*/sitemaps*' => Http::response([
                'sitemap' => [['path' => 'https://frete.rio.br/sitemap.xml', 'isPending' => false]],
            ], 200),
        ]);

        $resultado = app(SearchConsoleService::class)->listarSitemaps($tenant, 'https://frete.rio.br/');

        $this->assertTrue($resultado['sucesso']);
        $this->assertSame('https://frete.rio.br/sitemap.xml', $resultado['dados'][0]['path']);
    }
}
