<?php

namespace App\Services;

use App\Models\GoogleToken;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GoogleService
{
    private const AUTH_URL     = 'https://accounts.google.com/o/oauth2/v2/auth';
    private const TOKEN_URL    = 'https://oauth2.googleapis.com/token';
    private const REVOKE_URL   = 'https://oauth2.googleapis.com/revoke';
    private const USERINFO_URL = 'https://www.googleapis.com/oauth2/v3/userinfo';

    // Escopos máximos — cobre Contacts, Drive, Sheets, Docs, Calendar, Gmail
    private const SCOPES = [
        'openid',
        'email',
        'profile',
        'https://www.googleapis.com/auth/contacts',
        'https://www.googleapis.com/auth/drive',
        'https://www.googleapis.com/auth/calendar',
        'https://mail.google.com/',
    ];

    private string $clientId;
    private string $clientSecret;
    private string $redirectUri;

    public function __construct()
    {
        $this->clientId     = config('services.google.client_id', '');
        $this->clientSecret = config('services.google.client_secret', '');
        $this->redirectUri  = config('services.google.redirect_uri', '');
    }

    public function urlAutorizacao(string $state): string
    {
        return self::AUTH_URL . '?' . http_build_query([
            'response_type' => 'code',
            'client_id'     => $this->clientId,
            'redirect_uri'  => $this->redirectUri,
            'scope'         => implode(' ', self::SCOPES),
            'access_type'   => 'offline',
            'prompt'        => 'consent',      // garante refresh_token sempre
            'state'         => $state,
        ]);
    }

    public function trocarCodigo(string $code): ?array
    {
        try {
            $res = Http::asForm()->post(self::TOKEN_URL, [
                'code'          => $code,
                'client_id'     => $this->clientId,
                'client_secret' => $this->clientSecret,
                'redirect_uri'  => $this->redirectUri,
                'grant_type'    => 'authorization_code',
            ]);

            if ($res->successful()) {
                return $res->json();
            }

            Log::error('Google OAuth troca de código falhou', ['response' => $res->body()]);
        } catch (\Exception $e) {
            Log::error('Google OAuth exceção', ['erro' => $e->getMessage()]);
        }

        return null;
    }

    public function renovarToken(GoogleToken $token): bool
    {
        try {
            $res = Http::asForm()->post(self::TOKEN_URL, [
                'refresh_token' => $token->refresh_token,
                'client_id'     => $this->clientId,
                'client_secret' => $this->clientSecret,
                'grant_type'    => 'refresh_token',
            ]);

            if ($res->successful()) {
                $data = $res->json();
                $token->update([
                    'access_token' => $data['access_token'],
                    'expires_at'   => Carbon::now()->addSeconds($data['expires_in'] - 60),
                ]);
                return true;
            }

            Log::error('Google OAuth renovação falhou', ['response' => $res->body()]);
        } catch (\Exception $e) {
            Log::error('Google OAuth renovar exceção', ['erro' => $e->getMessage()]);
        }

        return false;
    }

    public function tokenValido(GoogleToken $token): GoogleToken|false
    {
        if (! $token->expirado()) {
            return $token;
        }

        if ($this->renovarToken($token)) {
            return $token->fresh();
        }

        return false;
    }

    public function buscarEmail(string $accessToken): ?string
    {
        try {
            $res = Http::withToken($accessToken)->get(self::USERINFO_URL);
            if ($res->successful()) {
                return $res->json('email');
            }
        } catch (\Exception $e) {
            // silencioso
        }
        return null;
    }

    public function revogar(string $accessToken): void
    {
        try {
            Http::asForm()->post(self::REVOKE_URL, ['token' => $accessToken]);
        } catch (\Exception $e) {
            // silencioso
        }
    }

    // ── Contacts API ─────────────────────────────────────────────────────────

    public function atualizarNomeContato(
        GoogleToken $token,
        string $resourceName,
        string $etag,
        string $givenName,
        string $familyName
    ): bool {
        $token = $this->tokenValido($token);
        if (! $token) return false;

        try {
            $res = Http::withToken($token->access_token)
                ->patch(
                    "https://people.googleapis.com/v1/{$resourceName}?updatePersonFields=names",
                    [
                        'etag'  => $etag,
                        'names' => [['givenName' => $givenName, 'familyName' => $familyName]],
                    ]
                );

            return $res->successful();
        } catch (\Exception $e) {
            Log::error('Google updateContact falhou', ['resource' => $resourceName, 'erro' => $e->getMessage()]);
            return false;
        }
    }

    private const PERSON_FIELDS = 'names,nicknames,phoneNumbers,emailAddresses,organizations,addresses,birthdays,biographies,urls,photos,genders';

    /**
     * Sync completo (primeira vez) — devolve ['contatos', 'nextSyncToken']
     */
    public function listarContatos(GoogleToken $token, int $paginas = 30): array
    {
        return $this->paginacaoContatos($token, null, $paginas, requestSyncToken: true);
    }

    /**
     * Sync delta — só traz contatos novos/alterados/removidos desde o último sync.
     * Devolve ['contatos', 'deletados', 'nextSyncToken']
     */
    public function listarContatosDelta(GoogleToken $token, string $syncToken, int $paginas = 10): array
    {
        return $this->paginacaoContatos($token, $syncToken, $paginas, requestSyncToken: true);
    }

    private function paginacaoContatos(
        GoogleToken $token,
        ?string     $syncToken,
        int         $paginas,
        bool        $requestSyncToken
    ): array {
        $token = $this->tokenValido($token);
        if (! $token) return ['contatos' => [], 'deletados' => [], 'nextSyncToken' => null];

        $contatos      = [];
        $deletados     = [];
        $pageToken     = null;
        $pagina        = 0;
        $nextSyncToken = null;

        do {
            $params = [
                'personFields'     => self::PERSON_FIELDS,
                'pageSize'         => 1000,
                'requestSyncToken' => $requestSyncToken ? 'true' : 'false',
            ];
            if ($syncToken)  $params['syncToken']  = $syncToken;
            if ($pageToken)  $params['pageToken']  = $pageToken;

            $res = Http::withToken($token->access_token)
                ->get('https://people.googleapis.com/v1/people/me/connections', $params);

            if (! $res->successful()) {
                // Sync token expirado (410 Gone) — sinaliza para fazer full sync
                if ($res->status() === 410) {
                    Log::warning('Google SyncToken expirado — será feito full sync', ['tenant_id' => $token->tenant_id]);
                    return ['contatos' => [], 'deletados' => [], 'nextSyncToken' => null, 'token_expirado' => true];
                }
                break;
            }

            $data = $res->json();

            foreach ($data['connections'] ?? [] as $pessoa) {
                if (isset($pessoa['metadata']['deleted']) && $pessoa['metadata']['deleted']) {
                    $deletados[] = $pessoa;
                } else {
                    $contatos[] = $pessoa;
                }
            }

            $pageToken     = $data['nextPageToken']  ?? null;
            $nextSyncToken = $data['nextSyncToken']  ?? $nextSyncToken;
            $pagina++;
        } while ($pageToken && $pagina < $paginas);

        return [
            'contatos'      => $contatos,
            'deletados'     => $deletados,
            'nextSyncToken' => $nextSyncToken,
        ];
    }

    // ── Calendar API ─────────────────────────────────────────────────────────

    public function listarEventos(GoogleToken $token, string $dataInicio, string $dataFim): array
    {
        $token = $this->tokenValido($token);
        if (! $token) return [];

        $res = Http::withToken($token->access_token)
            ->get('https://www.googleapis.com/calendar/v3/calendars/primary/events', [
                'timeMin'      => $dataInicio,
                'timeMax'      => $dataFim,
                'singleEvents' => true,
                'orderBy'      => 'startTime',
            ]);

        return $res->successful() ? ($res->json('items') ?? []) : [];
    }

    public function criarEvento(GoogleToken $token, array $evento): ?array
    {
        $token = $this->tokenValido($token);
        if (! $token) return null;

        $res = Http::withToken($token->access_token)
            ->post('https://www.googleapis.com/calendar/v3/calendars/primary/events', $evento);

        return $res->successful() ? $res->json() : null;
    }

    // ── Drive / Sheets API ───────────────────────────────────────────────────

    public function listarArquivos(GoogleToken $token, string $query = ''): array
    {
        $token = $this->tokenValido($token);
        if (! $token) return [];

        $params = ['fields' => 'files(id,name,mimeType,webViewLink,modifiedTime)'];
        if ($query) $params['q'] = $query;

        $res = Http::withToken($token->access_token)
            ->get('https://www.googleapis.com/drive/v3/files', $params);

        return $res->successful() ? ($res->json('files') ?? []) : [];
    }

    public function lerPlanilha(GoogleToken $token, string $spreadsheetId, string $range): array
    {
        $token = $this->tokenValido($token);
        if (! $token) return [];

        $res = Http::withToken($token->access_token)
            ->get("https://sheets.googleapis.com/v4/spreadsheets/{$spreadsheetId}/values/{$range}");

        return $res->successful() ? ($res->json('values') ?? []) : [];
    }

    // ── Gmail API ────────────────────────────────────────────────────────────

    public function enviarEmail(GoogleToken $token, string $para, string $assunto, string $corpo): bool
    {
        $token = $this->tokenValido($token);
        if (! $token) return false;

        $mensagem = "To: {$para}\r\nSubject: {$assunto}\r\nContent-Type: text/html; charset=UTF-8\r\n\r\n{$corpo}";
        $raw = rtrim(strtr(base64_encode($mensagem), '+/', '-_'), '=');

        $res = Http::withToken($token->access_token)
            ->post('https://gmail.googleapis.com/gmail/v1/users/me/messages/send', [
                'raw' => $raw,
            ]);

        return $res->successful();
    }
}
