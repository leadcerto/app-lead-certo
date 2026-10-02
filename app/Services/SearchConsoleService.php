<?php

namespace App\Services;

use App\Models\GoogleToken;
use App\Models\Tenant;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Consulta a API do Google Search Console (dados de SEO: cliques, impressões,
 * posição, indexação) usando o mesmo GoogleToken multi-tenant já usado por
 * Contacts/GMB — ver leadcerto/_docs/mcp-google-search-console-projeto.md
 * pro projeto completo (motivado por perda de lead orgânico na Frete Rio).
 *
 * Mesma convenção de retorno já usada em GmbQualidadeService::buscarDadosLocation():
 * ['sucesso' => true, 'dados' => [...]] ou ['sucesso' => false, 'motivo' => '...'].
 */
class SearchConsoleService
{
    private const WEBMASTERS_BASE_URL     = 'https://www.googleapis.com/webmasters/v3';
    private const SEARCH_CONSOLE_BASE_URL = 'https://searchconsole.googleapis.com/v1';

    public function __construct(private GoogleService $google) {}

    /**
     * Lista as propriedades do Search Console acessíveis pela conta Google
     * conectada deste tenant.
     */
    public function listarSites(Tenant $tenant): array
    {
        $resolucao = $this->resolverTokenValido($tenant);
        if (! $resolucao['sucesso']) {
            return $resolucao;
        }

        try {
            $res = Http::withToken($resolucao['token']->access_token)
                ->get(self::WEBMASTERS_BASE_URL . '/sites');

            if (! $res->successful()) {
                Log::warning('SearchConsoleService::listarSites falhou', ['tenant_id' => $tenant->id, 'body' => $res->body()]);
                return ['sucesso' => false, 'motivo' => 'A Search Console recusou a consulta: ' . $res->body()];
            }

            return ['sucesso' => true, 'dados' => $res->json('siteEntry', [])];
        } catch (\Exception $e) {
            Log::error('SearchConsoleService::listarSites exceção', ['tenant_id' => $tenant->id, 'erro' => $e->getMessage()]);
            return ['sucesso' => false, 'motivo' => 'Erro de conexão com a Search Console: ' . $e->getMessage()];
        }
    }

    /**
     * Consulta cliques/impressões/CTR/posição média. $filtros aceita:
     * startDate, endDate (Y-m-d), dimensions (array, ex: ['query'], ['page']),
     * rowLimit (default 25).
     */
    public function consultarAnalytics(Tenant $tenant, string $siteUrl, array $filtros = []): array
    {
        $resolucao = $this->resolverTokenValido($tenant);
        if (! $resolucao['sucesso']) {
            return $resolucao;
        }

        $corpo = [
            'startDate' => $filtros['startDate'] ?? now()->subDays(28)->toDateString(),
            'endDate'   => $filtros['endDate'] ?? now()->toDateString(),
            'dimensions' => $filtros['dimensions'] ?? ['query'],
            'rowLimit'  => $filtros['rowLimit'] ?? 25,
        ];

        try {
            $res = Http::withToken($resolucao['token']->access_token)
                ->post(self::WEBMASTERS_BASE_URL . '/sites/' . rawurlencode($siteUrl) . '/searchAnalytics/query', $corpo);

            if (! $res->successful()) {
                Log::warning('SearchConsoleService::consultarAnalytics falhou', ['tenant_id' => $tenant->id, 'site' => $siteUrl, 'body' => $res->body()]);
                return ['sucesso' => false, 'motivo' => 'A Search Console recusou a consulta: ' . $res->body()];
            }

            return ['sucesso' => true, 'dados' => $res->json('rows', [])];
        } catch (\Exception $e) {
            Log::error('SearchConsoleService::consultarAnalytics exceção', ['tenant_id' => $tenant->id, 'erro' => $e->getMessage()]);
            return ['sucesso' => false, 'motivo' => 'Erro de conexão com a Search Console: ' . $e->getMessage()];
        }
    }

    /**
     * Status de indexação de uma URL específica — essencial pro caso de uso
     * que motivou este serviço: confirmar se uma URL de blog saiu do índice
     * depois de uma mudança de slug.
     */
    public function inspecionarUrl(Tenant $tenant, string $siteUrl, string $url): array
    {
        $resolucao = $this->resolverTokenValido($tenant);
        if (! $resolucao['sucesso']) {
            return $resolucao;
        }

        try {
            $res = Http::withToken($resolucao['token']->access_token)
                ->post(self::SEARCH_CONSOLE_BASE_URL . '/urlInspection/index:inspect', [
                    'inspectionUrl' => $url,
                    'siteUrl'       => $siteUrl,
                ]);

            if (! $res->successful()) {
                Log::warning('SearchConsoleService::inspecionarUrl falhou', ['tenant_id' => $tenant->id, 'url' => $url, 'body' => $res->body()]);
                return ['sucesso' => false, 'motivo' => 'A Search Console recusou a consulta: ' . $res->body()];
            }

            return ['sucesso' => true, 'dados' => $res->json('inspectionResult', [])];
        } catch (\Exception $e) {
            Log::error('SearchConsoleService::inspecionarUrl exceção', ['tenant_id' => $tenant->id, 'erro' => $e->getMessage()]);
            return ['sucesso' => false, 'motivo' => 'Erro de conexão com a Search Console: ' . $e->getMessage()];
        }
    }

    public function listarSitemaps(Tenant $tenant, string $siteUrl): array
    {
        $resolucao = $this->resolverTokenValido($tenant);
        if (! $resolucao['sucesso']) {
            return $resolucao;
        }

        try {
            $res = Http::withToken($resolucao['token']->access_token)
                ->get(self::WEBMASTERS_BASE_URL . '/sites/' . rawurlencode($siteUrl) . '/sitemaps');

            if (! $res->successful()) {
                Log::warning('SearchConsoleService::listarSitemaps falhou', ['tenant_id' => $tenant->id, 'site' => $siteUrl, 'body' => $res->body()]);
                return ['sucesso' => false, 'motivo' => 'A Search Console recusou a consulta: ' . $res->body()];
            }

            return ['sucesso' => true, 'dados' => $res->json('sitemap', [])];
        } catch (\Exception $e) {
            Log::error('SearchConsoleService::listarSitemaps exceção', ['tenant_id' => $tenant->id, 'erro' => $e->getMessage()]);
            return ['sucesso' => false, 'motivo' => 'Erro de conexão com a Search Console: ' . $e->getMessage()];
        }
    }

    /**
     * Resolve e garante um GoogleToken válido (renovando se expirado) pro
     * tenant — mesmo padrão já usado em GmbQualidadeService::buscarDadosLocation().
     */
    private function resolverTokenValido(Tenant $tenant): array
    {
        $token = GoogleToken::withoutGlobalScopes()->where('tenant_id', $tenant->id)->first();

        if (! $token) {
            return ['sucesso' => false, 'motivo' => 'Nenhuma conta Google conectada para este tenant. Acesse "Integrações" e conecte a conta Google.'];
        }

        $tokenValido = $this->google->tokenValido($token);

        if (! $tokenValido) {
            return ['sucesso' => false, 'motivo' => 'Não foi possível renovar o acesso à conta Google (autorização expirada ou revogada). Reconecte a conta Google em "Integrações".'];
        }

        return ['sucesso' => true, 'token' => $tokenValido];
    }
}
