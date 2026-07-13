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
    public function __construct(private GoogleService $google) {}

    public function view(Request $request): View
    {
        $tenantId = $request->user()->tenant_id;
        $token    = GoogleToken::where('tenant_id', $tenantId)->first();

        return view('integracoes.index', [
            'google_conectado' => (bool) $token,
            'google_email'     => $token?->google_email,
            'google_expira'    => $token?->expires_at?->format('d/m/Y H:i'),
            'google_scopes'    => $token?->scopes ?? [],
        ]);
    }

    public function googleAutorizar(Request $request): RedirectResponse
    {
        $state = Str::random(32);
        Session::put('google_oauth_state', $state);
        Session::put('google_oauth_tenant', $request->user()->tenant_id);

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

        $tenantId = Session::pull('google_oauth_tenant');

        $tokens = $this->google->trocarCodigo($code);

        if (! $tokens || empty($tokens['access_token'])) {
            return redirect()->route('integracoes')
                ->with('erro', 'Falha ao obter tokens do Google.');
        }

        $email = $this->google->buscarEmail($tokens['access_token']);

        GoogleToken::updateOrCreate(
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
        $tenantId = $request->user()->tenant_id;
        $token    = GoogleToken::where('tenant_id', $tenantId)->first();

        if ($token) {
            $this->google->revogar($token->access_token);
            $token->delete();
        }

        return redirect()->route('integracoes')
            ->with('sucesso', 'Google desconectado.');
    }
}
