<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Uazapi API — endpoints verificados em 2026-07-01 contra https://app-leadcerto.uazapi.com
 *
 * Autenticação:
 *   - Operações de admin (criar/listar/deletar instâncias): header "AdminToken: <UAZAPI_KEY>"
 *   - Operações por instância (enviar msg, status, webhook):  header "token: <instance_token>"
 *
 * O instance_token é único por instância e é devolvido ao criar a instância.
 * Deve ser armazenado na tabela tenants (campo uazapi_instance_token).
 */
class UazapiService
{
    private string $baseUrl;
    private string $adminToken;

    public function __construct()
    {
        $this->baseUrl    = rtrim(config('services.uazapi.base_url', ''), '/');
        $this->adminToken = config('services.uazapi.key', '');
    }

    // -------------------------------------------------------------------------
    // Operações de admin (usam AdminToken)
    // -------------------------------------------------------------------------

    /**
     * Cria uma nova instância no servidor Uazapi.
     * Retorna o instance_token que deve ser salvo no tenant.
     */
    public function criarInstancia(string $nome): ?array
    {
        try {
            $response = Http::withHeaders(['AdminToken' => $this->adminToken])
                ->post("{$this->baseUrl}/instance/create", [
                    'name'          => $nome,
                    'msg_delay_min' => 1,
                    'msg_delay_max' => 3,
                ]);

            if ($response->successful()) {
                return [
                    'token'  => $response->json('token'),
                    'id'     => $response->json('instance.id'),
                    'name'   => $response->json('instance.name'),
                    'status' => $response->json('instance.status'),
                ];
            }

            Log::warning('Uazapi criarInstancia falhou', ['body' => $response->body()]);
            return null;
        } catch (\Exception $e) {
            Log::error('Uazapi criarInstancia exception', ['erro' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Lista todas as instâncias do servidor.
     */
    public function listarInstancias(): array
    {
        try {
            $response = Http::withHeaders(['AdminToken' => $this->adminToken])
                ->timeout(5)
                ->get("{$this->baseUrl}/instance/all");

            return $response->successful() ? ($response->json() ?? []) : [];
        } catch (\Exception $e) {
            Log::error('Uazapi listarInstancias exception', ['erro' => $e->getMessage()]);
            return [];
        }
    }

    /**
     * Deleta uma instância. Requer o token da própria instância.
     */
    public function deletarInstancia(string $instanceToken): bool
    {
        try {
            $response = Http::withHeaders(['token' => $instanceToken])
                ->delete("{$this->baseUrl}/instance");

            return $response->successful();
        } catch (\Exception $e) {
            Log::error('Uazapi deletarInstancia exception', ['erro' => $e->getMessage()]);
            return false;
        }
    }

    // -------------------------------------------------------------------------
    // Operações por instância (usam token da instância)
    // -------------------------------------------------------------------------

    /**
     * Inicia a conexão de uma instância e retorna o QR code em base64.
     * Tenta até 3 vezes com 1s de intervalo (instância pode demorar a inicializar).
     */
    public function conectar(string $instanceToken): ?string
    {
        for ($tentativa = 1; $tentativa <= 3; $tentativa++) {
            try {
                $response = Http::withHeaders(['token' => $instanceToken])
                    ->timeout(15)
                    ->withBody('{}', 'application/json')
                    ->post("{$this->baseUrl}/instance/connect");

                Log::debug('Uazapi conectar resposta', [
                    'tentativa' => $tentativa,
                    'status'    => $response->status(),
                    'body'      => substr($response->body(), 0, 200),
                ]);

                if ($response->successful()) {
                    $qr = $response->json('instance.qrcode');
                    if ($qr) {
                        return $qr; // data:image/png;base64,...
                    }
                }
            } catch (\Exception $e) {
                Log::error('Uazapi conectar exception', ['tentativa' => $tentativa, 'erro' => $e->getMessage()]);
            }

            if ($tentativa < 3) {
                sleep(1);
            }
        }

        return null;
    }

    /**
     * Retorna o status atual da instância.
     */
    public function status(string $instanceToken): array
    {
        try {
            $response = Http::withHeaders(['token' => $instanceToken])
                ->timeout(5)
                ->get("{$this->baseUrl}/instance/status");

            return $response->successful()
                ? ($response->json() ?? ['status' => ['connected' => false]])
                : ['status' => ['connected' => false]];
        } catch (\Exception $e) {
            Log::error('Uazapi status exception', ['erro' => $e->getMessage()]);
            return ['status' => ['connected' => false]];
        }
    }

    /**
     * Envia mensagem de texto.
     * O campo "number" deve ser o telefone no formato internacional sem +: 5511999999999
     */
    public function enviarTexto(string $instanceToken, string $numero, string $texto): bool
    {
        try {
            $response = Http::withHeaders(['token' => $instanceToken])
                ->post("{$this->baseUrl}/send/text", [
                    'number' => $numero,
                    'text'   => $texto,
                ]);

            if (!$response->successful()) {
                Log::warning('Uazapi enviarTexto falhou', [
                    'numero' => $numero,
                    'status' => $response->status(),
                    'body'   => $response->body(),
                ]);
            }

            return $response->successful();
        } catch (\Exception $e) {
            Log::error('Uazapi enviarTexto exception', ['erro' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * Envia mensagem com imagem. Tenta /send/image (URL) e, se falhar (405 = API mudou),
     * baixa o arquivo e envia via /send/media como upload binário.
     */
    public function enviarImagem(string $instanceToken, string $numero, string $url, string $caption = ''): bool
    {
        try {
            // Tentativa 1: endpoint original com URL em JSON
            $body = ['number' => $numero, 'url' => $url];
            if ($caption !== '') {
                $body['caption'] = $caption;
            }

            $response = Http::withHeaders(['token' => $instanceToken])
                ->post("{$this->baseUrl}/send/image", $body);

            if ($response->successful()) {
                return true;
            }

            // Tentativa 2: /send/media com upload binário (API atualizada)
            if ($response->status() === 405 || $response->status() === 404) {
                Log::info('Uazapi /send/image retornou ' . $response->status() . ', tentando /send/media com upload', [
                    'numero' => $numero,
                ]);

                $conteudo = Http::timeout(15)->get($url)->body();
                if (empty($conteudo)) {
                    Log::warning('Uazapi enviarImagem: falha ao baixar imagem', ['url' => $url]);
                    return false;
                }

                $ext      = pathinfo(parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION) ?: 'jpg';
                $mime     = match(strtolower($ext)) {
                    'png'  => 'image/png',
                    'gif'  => 'image/gif',
                    'webp' => 'image/webp',
                    default => 'image/jpeg',
                };

                $req = Http::withHeaders(['token' => $instanceToken])
                    ->attach('file', $conteudo, "image.{$ext}", ['Content-Type' => $mime]);

                if ($caption !== '') {
                    $req = $req->attach('caption', $caption, null);
                }

                // Alguns endpoints leem o number de um JSON anexo, outros de query string
                $mediaRes = $req->post("{$this->baseUrl}/send/media", ['number' => $numero]);

                if (!$mediaRes->successful()) {
                    // Última tentativa: number via query param
                    $mediaRes = $req->post(
                        "{$this->baseUrl}/send/media?" . http_build_query(['number' => $numero])
                    );
                }

                if (!$mediaRes->successful()) {
                    Log::warning('Uazapi enviarImagem /send/media falhou', [
                        'numero' => $numero,
                        'status' => $mediaRes->status(),
                        'body'   => $mediaRes->body(),
                    ]);
                }

                return $mediaRes->successful();
            }

            Log::warning('Uazapi enviarImagem /send/image falhou', [
                'numero' => $numero,
                'status' => $response->status(),
                'body'   => $response->body(),
            ]);
            return false;

        } catch (\Exception $e) {
            Log::error('Uazapi enviarImagem exception', ['erro' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * Envia documento/arquivo. url deve ser uma URL pública acessível.
     */
    public function enviarDocumento(string $instanceToken, string $numero, string $url, string $filename = '', string $caption = ''): bool
    {
        try {
            $body = ['number' => $numero, 'url' => $url];
            if ($filename !== '') $body['filename'] = $filename;
            if ($caption  !== '') $body['caption']  = $caption;

            $response = Http::withHeaders(['token' => $instanceToken])
                ->post("{$this->baseUrl}/send/document", $body);

            if (!$response->successful()) {
                Log::warning('Uazapi enviarDocumento falhou', [
                    'numero' => $numero, 'status' => $response->status(), 'body' => $response->body(),
                ]);
            }
            return $response->successful();
        } catch (\Exception $e) {
            Log::error('Uazapi enviarDocumento exception', ['erro' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * Envia mensagem de voz (PTT) ou áudio. url deve ser URL pública de arquivo de áudio.
     */
    public function enviarAudio(string $instanceToken, string $numero, string $url, bool $ptt = true): bool
    {
        try {
            $endpoint = $ptt ? '/send/ptt' : '/send/audio';
            $response = Http::withHeaders(['token' => $instanceToken])
                ->post("{$this->baseUrl}{$endpoint}", [
                    'number' => $numero,
                    'url'    => $url,
                ]);

            if ($response->successful()) {
                return true;
            }

            // Fallback: upload binário via /send/media (API atualizada após 2026-07-01)
            if ($response->status() === 405 || $response->status() === 404) {
                Log::info("Uazapi {$endpoint} retornou {$response->status()}, tentando /send/media com upload binário", [
                    'numero' => $numero,
                ]);

                $conteudo = Http::timeout(15)->get($url)->body();
                if (empty($conteudo)) {
                    Log::warning('Uazapi enviarAudio: falha ao baixar áudio', ['url' => $url]);
                    return false;
                }

                $ext  = pathinfo(parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION) ?: 'ogg';
                $mime = match(strtolower($ext)) {
                    'mp3'  => 'audio/mpeg',
                    'mp4'  => 'audio/mp4',
                    'webm' => 'audio/webm',
                    'wav'  => 'audio/wav',
                    default => 'audio/ogg',
                };

                $req      = Http::withHeaders(['token' => $instanceToken])
                    ->attach('file', $conteudo, "audio.{$ext}", ['Content-Type' => $mime]);
                $mediaRes = $req->post("{$this->baseUrl}/send/media", ['number' => $numero]);

                if (!$mediaRes->successful()) {
                    $mediaRes = $req->post(
                        "{$this->baseUrl}/send/media?" . http_build_query(['number' => $numero])
                    );
                }

                if (!$mediaRes->successful()) {
                    Log::warning('Uazapi enviarAudio /send/media falhou', [
                        'numero' => $numero,
                        'status' => $mediaRes->status(),
                        'body'   => $mediaRes->body(),
                    ]);
                }

                return $mediaRes->successful();
            }

            Log::warning("Uazapi enviarAudio {$endpoint} falhou", [
                'numero' => $numero, 'status' => $response->status(), 'body' => $response->body(),
            ]);
            return false;

        } catch (\Exception $e) {
            Log::error('Uazapi enviarAudio exception', ['erro' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * Define o status de presença da instância.
     * $presenca: 'composing' (digitando), 'recording' (gravando áudio),
     *            'available', 'unavailable'
     */
    public function setPresenca(string $instanceToken, string $presenca, ?string $para = null): bool
    {
        try {
            $body = ['presence' => $presenca];
            if ($para) {
                $body['to'] = $para;
            }

            $response = Http::withHeaders(['token' => $instanceToken])
                ->post("{$this->baseUrl}/instance/presence", $body);

            return $response->successful();
        } catch (\Exception $e) {
            Log::error('Uazapi setPresenca exception', ['erro' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * Configura o webhook da instância para receber eventos.
     */
    public function configurarWebhook(string $instanceToken, string $url, array $eventos = ['messages', 'status', 'connection']): bool
    {
        try {
            $response = Http::withHeaders(['token' => $instanceToken])
                ->post("{$this->baseUrl}/webhook", [
                    'url'                  => $url,
                    'events'               => $eventos,
                    'enabled'              => true,
                    'addUrlEvents'         => false,
                    'addUrlTypesMessages'  => false,
                    'excludeMessages'      => [],
                ]);

            return $response->successful();
        } catch (\Exception $e) {
            Log::error('Uazapi configurarWebhook exception', ['erro' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * Retorna a configuração atual do webhook da instância.
     */
    public function getWebhook(string $instanceToken): array
    {
        try {
            $response = Http::withHeaders(['token' => $instanceToken])
                ->timeout(5)
                ->get("{$this->baseUrl}/webhook");

            return $response->successful() ? ($response->json() ?? []) : [];
        } catch (\Exception $e) {
            Log::error('Uazapi getWebhook exception', ['erro' => $e->getMessage()]);
            return [];
        }
    }

    /**
     * Lista todos os grupos da instância, incluindo participantes com telefone.
     * Retorna array de grupos com campo 'Participants' contendo 'PhoneNumber'.
     */
    public function listarGrupos(string $instanceToken): array
    {
        try {
            $response = Http::withHeaders(['token' => $instanceToken])
                ->timeout(60)
                ->get("{$this->baseUrl}/group/list");

            return $response->successful() ? ($response->json('groups') ?? []) : [];
        } catch (\Exception $e) {
            Log::error('Uazapi listarGrupos exception', ['erro' => $e->getMessage()]);
            return [];
        }
    }

    /**
     * Lista todos os contatos da agenda vinculada à instância.
     * Retorna array de ['jid', 'contact_name', 'contact_FirstName']
     */
    public function listarContatos(string $instanceToken): array
    {
        try {
            $response = Http::withHeaders(['token' => $instanceToken])
                ->timeout(30)
                ->get("{$this->baseUrl}/contacts");

            return $response->successful() ? ($response->json() ?? []) : [];
        } catch (\Exception $e) {
            Log::error('Uazapi listarContatos exception', ['erro' => $e->getMessage()]);
            return [];
        }
    }

    // -------------------------------------------------------------------------
    // Método legado — mantido por compatibilidade enquanto HumanizacaoService
    // não substitui todas as chamadas. Usar enviarTexto() diretamente.
    // -------------------------------------------------------------------------

    /** @deprecated Usar HumanizacaoService::processar() em vez disso */
    public function enviarMensagem(string $telefone, string $mensagem, string $instanceToken = ''): bool
    {
        $token = $instanceToken ?: $this->adminToken;
        return $this->enviarTexto($token, $telefone, $mensagem);
    }
}
