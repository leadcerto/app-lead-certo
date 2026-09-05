<?php

namespace App\Http\Controllers\Painel;

use App\Http\Controllers\Controller;
use App\Models\GoogleToken;
use App\Services\GoogleService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Str;

class IntegracoesController extends Controller
{
    public function __construct(
        private GoogleService $google,
        private \App\Services\MetaService $meta
    ) {}

    private function getTenantId(Request $request): int
    {
        return (int) (session('tenant_id') ?? $request->user()->tenant_id);
    }

    public function view(Request $request): View
    {
        $tenantId  = $this->getTenantId($request);
        $token     = GoogleToken::where('tenant_id', $tenantId)->first();
        $metaToken = \App\Models\MetaToken::where('tenant_id', $tenantId)->first();
        $metaPaginas = $metaToken 
            ? \App\Models\MetaPagina::where('meta_token_id', $metaToken->id)->with('contasInstagram')->get() 
            : collect();

        return view('integracoes.index', [
            'google_conectado' => (bool) $token,
            'google_email'     => $token?->google_email,
            'google_expira'    => $token?->expires_at?->format('d/m/Y H:i'),
            'google_scopes'    => $token?->scopes ?? [],
            'meta_conectado'   => (bool) $metaToken,
            'meta_usuario'     => $metaToken?->nome_usuario,
            'meta_expira'      => $metaToken?->expires_at?->format('d/m/Y H:i'),
            'meta_paginas'     => $metaPaginas,
        ]);
    }

    public function googleAutorizar(Request $request): RedirectResponse
    {
        $state = Str::random(32);
        Session::put('google_oauth_state', $state);
        Session::put('google_oauth_tenant', $this->getTenantId($request));

        return redirect($this->google->urlAutorizacao($state));
    }

    public function googleCallback(Request $request): RedirectResponse
    {
        $state    = $request->query('state');
        $code     = $request->query('code');
        $error    = $request->query('error');

        if ($error || ! $code) {
            return redirect()->route('integracoes')
                ->with('erro', 'Autorização negada: ' . ($error ?? 'sem código'));
        }

        if ($state !== Session::pull('google_oauth_state')) {
            return redirect()->route('integracoes')
                ->with('erro', 'Estado OAuth inválido. Tente novamente.');
        }

        $tenantId = Session::pull('google_oauth_tenant') ?? $this->getTenantId($request);

        $tokens = $this->google->trocarCodigo($code);

        if (! $tokens || empty($tokens['access_token'])) {
            return redirect()->route('integracoes')
                ->with('erro', 'Falha ao obter tokens do Google.');
        }

        $email = $this->google->buscarEmail($tokens['access_token']);

        GoogleToken::withoutGlobalScopes()->updateOrCreate(
            ['tenant_id' => $tenantId],
            [
                'google_email'       => $email,
                'access_token'       => $tokens['access_token'],
                'refresh_token'      => $tokens['refresh_token'] ?? '',
                'token_type'         => $tokens['token_type'] ?? 'Bearer',
                'expires_at'         => Carbon::now()->addSeconds(($tokens['expires_in'] ?? 3600) - 60),
                'scopes'             => explode(' ', $tokens['scope'] ?? ''),
                'falha_renovacao_em' => null,
            ]
        );

        return redirect()->route('contatos.importar')
            ->with('google_recente', true)
            ->with('sucesso', "Google conectado! Sincronizando contatos de {$email}...");
    }

    public function googleDesconectar(Request $request): RedirectResponse
    {
        $tenantId = $this->getTenantId($request);
        $token    = GoogleToken::withoutGlobalScopes()->where('tenant_id', $tenantId)->first();

        if ($token) {
            $this->google->revogar($token->access_token);
            $token->delete();
        }

        return redirect()->route('integracoes')
            ->with('sucesso', 'Google desconectado.');
    }

    public function googleTestarGmb(Request $request): RedirectResponse
    {
        $tenantId = $this->getTenantId($request);
        $token    = GoogleToken::withoutGlobalScopes()->where('tenant_id', $tenantId)->first();

        if (! $token) {
            return redirect()->route('integracoes')->with('erro', 'Nenhuma conta Google conectada para testar.');
        }

        if ($token->expires_at && $token->expires_at->isPast()) {
            $this->google->renovarToken($token);
            $token->refresh();
        }

        $res = \Illuminate\Support\Facades\Http::withToken($token->access_token)
            ->timeout(15)
            ->get('https://mybusinessaccountmanagement.googleapis.com/v1/accounts');

        if ($res->successful()) {
            $accounts = $res->json('accounts') ?? [];
            $qtd = count($accounts);
            return redirect()->route('integracoes')->with('sucesso', "✅ Google Meu Negócio LIBERADO! Acesso verificado com sucesso ({$qtd} conta(s) encontrada(s)).");
        }

        $erro = $res->json('error.message') ?? $res->body();
        if (str_contains($erro, 'SERVICE_DISABLED') || str_contains($erro, 'has not been used in project')) {
            return redirect()->route('integracoes')->with('erro', "⚠️ API precisa ser ativada no Google Cloud Console: 'My Business Account Management API'. Detalhes: {$erro}");
        }

        if ($res->status() === 429 || str_contains($erro, 'Quota exceeded')) {
            return redirect()->route('integracoes')->with('aviso', "⏳ Acesso ao Google Meu Negócio em análise pelo Google (Cota = 0). O Google Cloud exige aprovação burocrática para a Google Business Profile API (Protocolo enviado: 9-4101000041625). Enquanto a cota não for liberada pela equipe do Google, as postagens diretas aguardam essa liberação ou contingência via Webhook n8n.");
        }

        return redirect()->route('integracoes')->with('erro', "Google Meu Negócio retornou status {$res->status()}: {$erro}");
    }

    // ── Integração Meta (Facebook & Instagram) ──────────────────────────

    public function metaAutorizar(Request $request): RedirectResponse
    {
        $state = Str::random(32);
        Session::put('meta_oauth_state', $state);
        Session::put('meta_oauth_tenant', $this->getTenantId($request));

        return redirect($this->meta->urlAutorizacao($state));
    }

    public function metaCallback(Request $request): RedirectResponse
    {
        try {
            $state = $request->query('state');
            $code  = $request->query('code');
            $error = $request->query('error_description') ?: $request->query('error');

            if ($error || ! $code) {
                return redirect()->route('integracoes')
                    ->with('erro', 'Autorização da Meta negada: ' . ($error ?? 'sem código'));
            }

            $savedState = Session::pull('meta_oauth_state');
            if ($state && $savedState && $state !== $savedState) {
                return redirect()->route('integracoes')
                    ->with('erro', 'Estado OAuth da Meta inválido. Tente novamente.');
            }

            $tenantId = Session::pull('meta_oauth_tenant') ?? $this->getTenantId($request);

            if (! $tenantId) {
                return redirect()->route('integracoes')
                    ->with('erro', 'Tenant não identificado para vincular a Meta.');
            }

            // 1. Troca código por token de curta duração
            $resToken = $this->meta->trocarCodigoPorToken($code);
            if (! $resToken || empty($resToken['access_token'])) {
                return redirect()->route('integracoes')
                    ->with('erro', 'Falha ao obter token da Meta.');
            }

            // 2. Converte para token de longa duração (60 dias)
            $tokenLongaDuracao = $this->meta->obterTokenLongaDuracao($resToken['access_token']);
            $accessTokenFinal = $tokenLongaDuracao['access_token'] ?? $resToken['access_token'];
            $expiresIn = $tokenLongaDuracao['expires_in'] ?? $resToken['expires_in'] ?? (60 * 86400);

            // 3. Salva ou atualiza o MetaToken
            $metaToken = \App\Models\MetaToken::withoutGlobalScopes()->updateOrCreate(
                ['tenant_id' => $tenantId],
                [
                    'access_token' => $accessTokenFinal,
                    'expires_at'   => Carbon::now()->addSeconds($expiresIn),
                    'scopes'       => \App\Services\MetaService::SCOPES,
                ]
            );

            // 4. Não vincula páginas automaticamente — a mesma conta pessoal da
            // Meta pode administrar páginas de VÁRIOS negócios diferentes.
            // O operador escolhe manualmente quais pertencem a este tenant.
            return redirect()->route('meta.selecionar-paginas')
                ->with('sucesso', 'Meta conectada! Selecione abaixo qual(is) página(s) pertence(m) a esta empresa.');
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('Erro no metaCallback', [
                'erro'  => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return redirect()->route('integracoes')
                ->with('erro', 'Erro ao processar conexão com a Meta: ' . $e->getMessage());
        }
    }

    public function metaSelecionarPaginas(Request $request): View|RedirectResponse
    {
        $tenantId  = $this->getTenantId($request);
        $metaToken = \App\Models\MetaToken::withoutGlobalScopes()->where('tenant_id', $tenantId)->first();

        if (! $metaToken) {
            return redirect()->route('integracoes')
                ->with('erro', 'Nenhuma conta Meta conectada para esta empresa.');
        }

        $listagem = $this->meta->listarPaginasDisponiveis($metaToken);
        if (! $listagem['sucesso']) {
            return redirect()->route('integracoes')
                ->with('erro', 'Falha ao consultar páginas da Meta: ' . ($listagem['erro'] ?? 'erro desconhecido'));
        }

        $idsJaAtivos = \App\Models\MetaPagina::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('ativo', true)
            ->pluck('facebook_page_id')
            ->all();

        return view('integracoes.meta-selecionar-paginas', [
            'paginas'     => $listagem['paginas'],
            'idsJaAtivos' => $idsJaAtivos,
        ]);
    }

    public function metaVincularPaginas(Request $request): RedirectResponse
    {
        $tenantId  = $this->getTenantId($request);
        $metaToken = \App\Models\MetaToken::withoutGlobalScopes()->where('tenant_id', $tenantId)->first();

        if (! $metaToken) {
            return redirect()->route('integracoes')
                ->with('erro', 'Nenhuma conta Meta conectada para esta empresa.');
        }

        $selecionadas = (array) $request->input('paginas', []);

        if (empty($selecionadas)) {
            return redirect()->route('meta.selecionar-paginas')
                ->with('erro', 'Selecione ao menos uma página para vincular a esta empresa.');
        }

        try {
            $res = $this->meta->vincularPaginasSelecionadas($metaToken, $selecionadas);

            if (! ($res['sucesso'] ?? false)) {
                return redirect()->route('meta.selecionar-paginas')
                    ->with('erro', 'Erro ao vincular páginas: ' . ($res['erro'] ?? 'erro desconhecido'));
            }

            $paginasTotal = $res['total_paginas'] ?? 0;
            $igTotal = $res['total_instagram'] ?? 0;

            return redirect()->route('integracoes')
                ->with('sucesso', "{$paginasTotal} página(s) do Facebook e {$igTotal} conta(s) do Instagram vinculadas a esta empresa.");
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('Erro no metaVincularPaginas', ['erro' => $e->getMessage()]);
            return redirect()->route('meta.selecionar-paginas')
                ->with('erro', 'Erro ao vincular páginas: ' . $e->getMessage());
        }
    }

    public function metaSincronizar(Request $request): RedirectResponse
    {
        // Re-sincronizar sempre passa pela tela de seleção — nunca gravamos
        // de volta a lista inteira de páginas que a conta pessoal administra.
        return redirect()->route('meta.selecionar-paginas');
    }

    public function metaDesconectar(Request $request): RedirectResponse
    {
        try {
            $tenantId  = $this->getTenantId($request);
            $metaToken = \App\Models\MetaToken::withoutGlobalScopes()->where('tenant_id', $tenantId)->first();

            if ($metaToken) {
                $metaToken->delete();
            }

            return redirect()->route('integracoes')
                ->with('sucesso', 'Meta (Facebook & Instagram) desconectada.');
        } catch (\Throwable $e) {
            return redirect()->route('integracoes')
                ->with('erro', 'Erro ao desconectar Meta: ' . $e->getMessage());
        }
    }
}
