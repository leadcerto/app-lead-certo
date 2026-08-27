<?php

namespace App\Services\Canais;

use App\Models\TicketAtendimento;
use App\Models\WhatsappCanal;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Envia mensagens pelo canal oficial (Meta Cloud API, via Covercut).
 * Nunca dispara proativamente — só responde dentro da janela de conversa (seção 4
 * do design, docs/superpowers/specs/2026-07-27-canal-whatsapp-oficial-covercut-design.md).
 * Sem templates pagos: fora da janela, o envio é bloqueado, sem fallback.
 * Mídia (imagem/áudio/documento/sticker) é enviada via link público — nunca faz
 * upload prévio pra Meta (ver docs/superpowers/specs/2026-07-31-envio-midia-canal-oficial-design.md).
 */
class CovercutChannelService implements CanalWhatsappInterface
{
    /**
     * Código de erro da Meta Cloud API (repassado pela Covercut) pra "telefone
     * não é um número WhatsApp válido" — confirmado via
     * api.covercut.com.br/docs/#codigos-de-erro (2026-08-26). Casado numa regex
     * sobre o corpo bruto da resposta (em vez de decodificar um formato de
     * envelope específico) porque não temos confirmação de como a Covercut
     * aninha o erro da Meta no JSON — mais resiliente a variação de formato.
     */
    private const CODIGO_ERRO_NUMERO_INVALIDO = '131026';

    private bool $ultimoErroNumeroInvalido = false;

    public function ultimoEnvioFalhouPorNumeroInvalido(): bool
    {
        return $this->ultimoErroNumeroInvalido;
    }

    public function enviarTexto(WhatsappCanal $canal, string $telefone, string $texto): bool
    {
        return $this->enviar($canal, $telefone, [
            'type' => 'text',
            'text' => ['body' => $texto],
        ]);
    }

    /**
     * Covercut não tem pipeline de humanização (isso é exclusivo do Uazapi) — o envio
     * já é uma única mensagem imediata, então só delega para enviarTexto(). A checagem
     * de janela de conversa continua valendo normalmente.
     */
    public function enviarTextoDireto(WhatsappCanal $canal, string $telefone, string $texto): bool
    {
        return $this->enviarTexto($canal, $telefone, $texto);
    }

    public function enviarImagem(WhatsappCanal $canal, string $telefone, string $url, string $caption = ''): bool
    {
        $imagem = ['link' => $url];
        if ($caption !== '') {
            $imagem['caption'] = $caption;
        }

        return $this->enviar($canal, $telefone, ['type' => 'image', 'image' => $imagem]);
    }

    /**
     * $ptt = true pede nota de voz, mas só marca 'voice' quando o arquivo é .ogg —
     * a Covercut/Meta exige esse formato (codec opus) pra renderizar como nota de
     * voz de verdade; outro formato marcado como voice pode ser rejeitado ou
     * renderizado errado do lado do WhatsApp.
     */
    public function enviarAudio(WhatsappCanal $canal, string $telefone, string $url, bool $ptt = true): bool
    {
        $audio = ['link' => $url];
        $caminho = parse_url($url, PHP_URL_PATH) ?? $url;
        if ($ptt && strtolower(pathinfo($caminho, PATHINFO_EXTENSION)) === 'ogg') {
            $audio['voice'] = true;
        }

        return $this->enviar($canal, $telefone, ['type' => 'audio', 'audio' => $audio]);
    }

    public function enviarDocumento(WhatsappCanal $canal, string $telefone, string $url, string $filename = '', string $caption = ''): bool
    {
        $documento = ['link' => $url];
        if ($filename !== '') {
            $documento['filename'] = $filename;
        }
        if ($caption !== '') {
            $documento['caption'] = $caption;
        }

        return $this->enviar($canal, $telefone, ['type' => 'document', 'document' => $documento]);
    }

    public function enviarSticker(WhatsappCanal $canal, string $telefone, string $url): bool
    {
        return $this->enviar($canal, $telefone, ['type' => 'sticker', 'sticker' => ['link' => $url]]);
    }

    /**
     * Monta e envia qualquer tipo de mensagem via POST /messages/send — checa
     * janela de conversa e phone_number_id, nunca lança exceção. $corpo já deve
     * trazer 'type' e o campo de conteúdo específico do tipo (text/image/audio/...).
     */
    private function enviar(WhatsappCanal $canal, string $telefone, array $corpo): bool
    {
        $this->ultimoErroNumeroInvalido = false;

        if (! $this->dentroDaJanela($canal, $telefone)) {
            return false;
        }

        $phoneNumberId = $canal->config['phone_number_id'] ?? null;

        if (! $phoneNumberId) {
            Log::warning('CovercutChannelService: canal sem phone_number_id configurado', ['canal_id' => $canal->id]);
            return false;
        }

        $baseUrl = config('services.covercut.base_url');

        try {
            $response = Http::withHeaders([
                    'X-API-Key'    => config('services.covercut.api_key'),
                    'X-API-Secret' => config('services.covercut.api_secret'),
                ])
                ->post("{$baseUrl}/messages/send", array_merge([
                    'from' => $phoneNumberId,
                    'to'   => $telefone,
                ], $corpo));
        } catch (\Throwable $e) {
            // Http::post lança ConnectionException em falhas de rede (DNS, timeout, TLS,
            // conexão recusada). A interface exige nunca lançar exceção.
            Log::warning('CovercutChannelService: exceção ao enviar mensagem', [
                'canal_id' => $canal->id,
                'tipo'     => $corpo['type'] ?? 'desconhecido',
                'erro'     => $e->getMessage(),
            ]);
            return false;
        }

        if (! $response->successful()) {
            $this->ultimoErroNumeroInvalido = str_contains($response->body(), self::CODIGO_ERRO_NUMERO_INVALIDO);

            Log::warning('CovercutChannelService: falha ao enviar mensagem', [
                'canal_id'        => $canal->id,
                'tipo'            => $corpo['type'] ?? 'desconhecido',
                'status'          => $response->status(),
                'body'            => $response->body(),
                'numero_invalido' => $this->ultimoErroNumeroInvalido,
            ]);
        }

        return $response->successful();
    }

    /**
     * Checa se ainda existe janela de conversa aberta (24h, ou 72h se veio de
     * anúncio) pro telefone neste canal. Sem ticket em aberto pro telefone, não há
     * janela pra checar — não bloqueia (ex: primeiro contato antes de qualquer
     * ticket existir); a Covercut também valida a janela do lado dela.
     */
    private function dentroDaJanela(WhatsappCanal $canal, string $telefone): bool
    {
        $ticket = TicketAtendimento::withoutGlobalScopes()
            ->where('tenant_id', $canal->tenant_id)
            ->where('whatsapp_canal_id', $canal->id)
            ->whereHas('contato', fn ($q) => $q->where('telefone', $telefone))
            ->whereIn('status', ['aberto', 'aguardando'])
            ->latest()
            ->first();

        if ($ticket && $ticket->janela_expira_em && now()->greaterThan($ticket->janela_expira_em)) {
            Log::warning('CovercutChannelService: envio bloqueado, janela de conversa expirada', [
                'canal_id'   => $canal->id,
                'ticket_id'  => $ticket->id,
                'expirou_em' => $ticket->janela_expira_em->toIso8601String(),
            ]);
            return false;
        }

        return true;
    }
}
