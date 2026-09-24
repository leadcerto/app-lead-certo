<?php

namespace App\Services\Canais;

use App\Models\WhatsappCanal;
use App\Services\AquecimentoWhatsappService;
use App\Services\HumanizacaoService;
use App\Services\MessengerProprioService;
use Illuminate\Support\Facades\Log;

/**
 * Fase 3 do plano do canal WhatsApp Messenger próprio (23/09) — espelha
 * UazapiChannelService de propósito: mesma trava de aquecimento
 * (AquecimentoWhatsappService, já 100% genérica por canal, zero mudança) e
 * mesmo motor de humanização (HumanizacaoService, generalizado na Fase 1).
 * Só troca o "enviador bruto" de UazapiService pra MessengerProprioService.
 */
class MessengerProprioChannelService implements CanalWhatsappInterface
{
    public function __construct(
        private HumanizacaoService $humanizacao,
        private MessengerProprioService $messengerProprio,
        private AquecimentoWhatsappService $aquecimento,
    ) {}

    /**
     * Checagem única de sessionId+aquecimento, compartilhada por todo método
     * de envio — mesmo papel que tokenSeAutorizado() em UazapiChannelService.
     */
    private function sessionIdSeAutorizado(WhatsappCanal $canal, string $telefone): ?string
    {
        $sessionId = $canal->sessionIdMessengerProprio();

        if (! $sessionId) {
            Log::warning('MessengerProprioChannelService: canal sem sessionId, mensagem não enviada', ['canal_id' => $canal->id]);
            return null;
        }

        if (! $this->aquecimento->podeEnviar($canal, $telefone)) {
            Log::warning('MessengerProprioChannelService: envio bloqueado pelo teto de aquecimento', [
                'canal_id' => $canal->id, 'telefone' => $telefone,
            ]);
            return null;
        }

        return $sessionId;
    }

    public function enviarTexto(WhatsappCanal $canal, string $telefone, string $texto): bool
    {
        $sessionId = $this->sessionIdSeAutorizado($canal, $telefone);
        if (! $sessionId) {
            return false;
        }

        $enviado = $this->humanizacao->processar($this->messengerProprio, $sessionId, $telefone, $texto);
        if ($enviado) {
            $this->aquecimento->registrarEnvio($canal, $telefone);
        }

        return $enviado;
    }

    /**
     * Envio imediato, sem humanização — mesmo caminho da resposta manual do
     * Kanban (ver UazapiChannelService::enviarTextoDireto()).
     */
    public function enviarTextoDireto(WhatsappCanal $canal, string $telefone, string $texto): bool
    {
        $sessionId = $this->sessionIdSeAutorizado($canal, $telefone);
        if (! $sessionId) {
            return false;
        }

        $enviado = $this->messengerProprio->enviarTexto($sessionId, $telefone, $texto);
        if ($enviado) {
            $this->aquecimento->registrarEnvio($canal, $telefone);
        }

        return $enviado;
    }

    public function enviarImagem(WhatsappCanal $canal, string $telefone, string $url, string $caption = ''): bool
    {
        $sessionId = $this->sessionIdSeAutorizado($canal, $telefone);
        if (! $sessionId) {
            return false;
        }

        $enviado = $this->messengerProprio->enviarImagem($sessionId, $telefone, $url, $caption);
        if ($enviado) {
            $this->aquecimento->registrarEnvio($canal, $telefone);
        }

        return $enviado;
    }

    public function enviarAudio(WhatsappCanal $canal, string $telefone, string $url, bool $ptt = true): bool
    {
        $sessionId = $this->sessionIdSeAutorizado($canal, $telefone);
        if (! $sessionId) {
            return false;
        }

        $enviado = $this->messengerProprio->enviarAudio($sessionId, $telefone, $url, $ptt);
        if ($enviado) {
            $this->aquecimento->registrarEnvio($canal, $telefone);
        }

        return $enviado;
    }

    public function enviarDocumento(WhatsappCanal $canal, string $telefone, string $url, string $filename = '', string $caption = ''): bool
    {
        $sessionId = $this->sessionIdSeAutorizado($canal, $telefone);
        if (! $sessionId) {
            return false;
        }

        $enviado = $this->messengerProprio->enviarDocumento($sessionId, $telefone, $url, $filename, $caption);
        if ($enviado) {
            $this->aquecimento->registrarEnvio($canal, $telefone);
        }

        return $enviado;
    }

    public function enviarSticker(WhatsappCanal $canal, string $telefone, string $url): bool
    {
        $sessionId = $this->sessionIdSeAutorizado($canal, $telefone);
        if (! $sessionId) {
            return false;
        }

        $enviado = $this->messengerProprio->enviarSticker($sessionId, $telefone, $url);
        if ($enviado) {
            $this->aquecimento->registrarEnvio($canal, $telefone);
        }

        return $enviado;
    }

    /**
     * O microserviço próprio não tem hoje nenhum código de erro equivalente
     * ao 131026 da Meta pra número inválido — mesma situação documentada em
     * UazapiChannelService::ultimoEnvioFalhouPorNumeroInvalido().
     */
    public function ultimoEnvioFalhouPorNumeroInvalido(): bool
    {
        return false;
    }

    /**
     * WhatsApp Messenger (não-oficial) não tem o conceito de janela de 24h
     * da Meta — mesmo padrão de UazapiChannelService.
     */
    public function ultimoEnvioFalhouPorJanelaExpirada(): bool
    {
        return false;
    }
}
