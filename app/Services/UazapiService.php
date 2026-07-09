<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Uazapi API (uazapiGO V2) — endpoints verificados em 2026-07-09 contra https://docs.uazapi.com
 *
 * Autenticação:
 *   - Operações de admin (criar/listar/deletar instâncias): header "AdminToken: <UAZAPI_KEY>"
 *   - Operações por instância (enviar msg, status, webhook):  header "token: <instance_token>"
 *
 * O instance_token é único por instância e é devolvido ao criar a instância.
 * Deve ser armazenado na tabela tenants (campo uazapi_instance_token).
 *
 * Envio de mídia: endpoint único POST /send/media, body em JSON com
 * {number, type, file, ...}. Os antigos /send/image, /send/audio, /send/ptt,
 * /send/document, /send/buttons, /send/list etc. não existem mais na API atual.
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
     * Envia mídia via /send/media (endpoint único desde a atualização de 2026-07).
     * Body em JSON: number, type, file (URL ou base64) + campos opcionais.
     * type válidos: image, video, videoplay, document, audio, myaudio, ptt, ptv, sticker.
     */
    private function enviarMedia(string $instanceToken, string $numero, string $type, string $file, array $extra = []): bool
    {
        try {
            $body = array_merge(['number' => $numero, 'type' => $type, 'file' => $file], $extra);

            $response = Http::withHeaders(['token' => $instanceToken])
                ->post("{$this->baseUrl}/send/media", $body);

            if (!$response->successful()) {
                Log::warning("Uazapi enviarMedia ({$type}) falhou", [
                    'numero' => $numero,
                    'status' => $response->status(),
                    'body'   => $response->body(),
                ]);
            }

            return $response->successful();
        } catch (\Exception $e) {
            Log::error('Uazapi enviarMedia exception', ['tipo' => $type, 'erro' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * Envia mensagem com imagem. url deve ser uma URL pública acessível (ou base64).
     */
    public function enviarImagem(string $instanceToken, string $numero, string $url, string $caption = ''): bool
    {
        $extra = $caption !== '' ? ['text' => $caption] : [];
        return $this->enviarMedia($instanceToken, $numero, 'image', $url, $extra);
    }

    /**
     * Envia documento/arquivo. url deve ser uma URL pública acessível (ou base64).
     */
    public function enviarDocumento(string $instanceToken, string $numero, string $url, string $filename = '', string $caption = ''): bool
    {
        $extra = [];
        if ($filename !== '') $extra['docName'] = $filename;
        if ($caption  !== '') $extra['text']    = $caption;

        return $this->enviarMedia($instanceToken, $numero, 'document', $url, $extra);
    }

    /**
     * Envia mensagem de voz (PTT) ou áudio. url deve ser URL pública de arquivo de áudio (ou base64).
     */
    public function enviarAudio(string $instanceToken, string $numero, string $url, bool $ptt = true): bool
    {
        return $this->enviarMedia($instanceToken, $numero, $ptt ? 'ptt' : 'audio', $url);
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
