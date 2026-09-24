<?php

namespace App\Services;

use App\Services\Canais\EnvioBrutoWhatsappInterface;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Cliente HTTP do microserviço próprio de WhatsApp Messenger (Baileys
 * direto, sem Uazapi) — Fase 3 do plano em
 * C:\Users\PICHAU\.claude\plans\nested-churning-prism.md. Código do
 * microserviço em leadcerto/integracoes/whatsapp-proprio/ (fora deste repo).
 *
 * Diferença de autenticação em relação à UazapiService: não existe token por
 * instância — uma chave só (MESSENGER_PROPRIO_KEY), compartilhada, manda no
 * header X-Api-Key em todo request. Quem identifica a conexão é o sessionId
 * na URL (salvo em WhatsappCanal.config['session_id'], ver
 * WhatsappCanal::sessionIdMessengerProprio()).
 *
 * Espelha a forma dos métodos de UazapiService (mesmo contrato de retorno)
 * de propósito — facilita ler os dois lado a lado e reaproveitar o mesmo
 * padrão em MessengerProprioChannelService.
 */
class MessengerProprioService implements EnvioBrutoWhatsappInterface
{
    private string $baseUrl;
    private string $apiKey;

    public function __construct()
    {
        // Achado do TDD (23/09): config()'s default só vale quando a chave
        // dotted NÃO existe — quando existe mas resolve pra null (env var
        // não setada), o default é ignorado e config() devolve null mesmo.
        // Precisa do ?? explícito pra nunca atribuir null numa propriedade
        // string tipada.
        $this->baseUrl = rtrim(config('services.messenger_proprio.base_url') ?? '', '/');
        $this->apiKey  = config('services.messenger_proprio.key') ?? '';
    }

    private function http()
    {
        return Http::withHeaders(['X-Api-Key' => $this->apiKey]);
    }

    /**
     * Cria uma sessão nova no microserviço. $webhookUrl já vem pronto com o
     * webhook_token do canal embutido (mesmo padrão de
     * UazapiService::configurarWebhook(), só que aqui é passado na criação,
     * não configurado depois).
     */
    public function criarSessao(string $sessionId, string $webhookUrl): bool
    {
        try {
            $response = $this->http()->post("{$this->baseUrl}/sessoes", [
                'sessionId'  => $sessionId,
                'webhookUrl' => $webhookUrl,
            ]);

            if (! $response->successful()) {
                Log::warning('MessengerProprio criarSessao falhou', [
                    'sessionId' => $sessionId, 'status' => $response->status(), 'body' => $response->body(),
                ]);
            }

            return $response->successful();
        } catch (\Exception $e) {
            Log::error('MessengerProprio criarSessao exception', ['erro' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * Retorna o QR code em base64, com retry — mesma robustez de
     * UazapiService::conectar() (a sessão pode levar um instante pra gerar
     * o primeiro QR depois de criada).
     */
    public function conectar(string $sessionId): ?string
    {
        for ($tentativa = 1; $tentativa <= 3; $tentativa++) {
            try {
                $response = $this->http()->timeout(15)->get("{$this->baseUrl}/sessoes/{$sessionId}/qrcode");

                if ($response->successful()) {
                    $qr = $response->json('qrcode');
                    if ($qr) {
                        return $qr; // data:image/png;base64,...
                    }
                }
            } catch (\Exception $e) {
                Log::error('MessengerProprio conectar exception', ['tentativa' => $tentativa, 'erro' => $e->getMessage()]);
            }

            if ($tentativa < 3) {
                sleep(1);
            }
        }

        return null;
    }

    public function status(string $sessionId): array
    {
        try {
            $response = $this->http()->timeout(5)->get("{$this->baseUrl}/sessoes/{$sessionId}/status");

            return $response->successful()
                ? ($response->json() ?? ['status' => 'disconnected', 'conectado' => false])
                : ['status' => 'disconnected', 'conectado' => false];
        } catch (\Exception $e) {
            Log::error('MessengerProprio status exception', ['erro' => $e->getMessage()]);
            return ['status' => 'disconnected', 'conectado' => false];
        }
    }

    public function deletarSessao(string $sessionId): bool
    {
        try {
            $response = $this->http()->delete("{$this->baseUrl}/sessoes/{$sessionId}");
            return $response->successful();
        } catch (\Exception $e) {
            Log::error('MessengerProprio deletarSessao exception', ['erro' => $e->getMessage()]);
            return false;
        }
    }

    public function enviarTexto(string $sessionId, string $numero, string $texto): bool
    {
        try {
            $response = $this->http()->post("{$this->baseUrl}/sessoes/{$sessionId}/enviar/texto", [
                'numero' => $numero,
                'texto'  => $texto,
            ]);

            if (! $response->successful()) {
                Log::warning('MessengerProprio enviarTexto falhou', [
                    'numero' => $numero, 'status' => $response->status(), 'body' => $response->body(),
                ]);
            }

            return $response->successful();
        } catch (\Exception $e) {
            Log::error('MessengerProprio enviarTexto exception', ['erro' => $e->getMessage()]);
            return false;
        }
    }

    public function setPresenca(string $sessionId, string $presenca, ?string $para = null): bool
    {
        try {
            $body = ['presenca' => $presenca];
            if ($para) {
                $body['numero'] = $para;
            }

            $response = $this->http()->post("{$this->baseUrl}/sessoes/{$sessionId}/enviar/presenca", $body);
            return $response->successful();
        } catch (\Exception $e) {
            Log::error('MessengerProprio setPresenca exception', ['erro' => $e->getMessage()]);
            return false;
        }
    }

    private function enviarMedia(string $sessionId, string $numero, string $tipo, string $url, array $extra = []): bool
    {
        try {
            $body = array_merge(['numero' => $numero, 'tipo' => $tipo, 'url' => $url], $extra);

            $response = $this->http()->post("{$this->baseUrl}/sessoes/{$sessionId}/enviar/media", $body);

            if (! $response->successful()) {
                Log::warning("MessengerProprio enviarMedia ({$tipo}) falhou", [
                    'numero' => $numero, 'status' => $response->status(), 'body' => $response->body(),
                ]);
            }

            return $response->successful();
        } catch (\Exception $e) {
            Log::error('MessengerProprio enviarMedia exception', ['tipo' => $tipo, 'erro' => $e->getMessage()]);
            return false;
        }
    }

    public function enviarImagem(string $sessionId, string $numero, string $url, string $caption = ''): bool
    {
        $extra = $caption !== '' ? ['legenda' => $caption] : [];
        return $this->enviarMedia($sessionId, $numero, 'imagem', $url, $extra);
    }

    public function enviarDocumento(string $sessionId, string $numero, string $url, string $filename = '', string $caption = ''): bool
    {
        $extra = [];
        if ($filename !== '') $extra['nomeArquivo'] = $filename;
        if ($caption  !== '') $extra['legenda']     = $caption;

        return $this->enviarMedia($sessionId, $numero, 'documento', $url, $extra);
    }

    public function enviarAudio(string $sessionId, string $numero, string $url, bool $ptt = true): bool
    {
        return $this->enviarMedia($sessionId, $numero, 'audio', $url, ['ptt' => $ptt]);
    }

    public function enviarSticker(string $sessionId, string $numero, string $url): bool
    {
        return $this->enviarMedia($sessionId, $numero, 'sticker', $url);
    }
}
