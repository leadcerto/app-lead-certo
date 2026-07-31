<?php

namespace App\Services\Canais;

use App\Models\WhatsappCanal;
use App\Services\HumanizacaoService;
use App\Services\UazapiService;
use Illuminate\Support\Facades\Log;

/**
 * Envia mensagens por um canal não-oficial (Uazapi), preservando exatamente o
 * comportamento já em produção: divide em balões, simula digitação, aplica delay.
 * Não muda nada do HumanizacaoService — só resolve o token do canal e delega.
 */
class UazapiChannelService implements CanalWhatsappInterface
{
    public function __construct(
        private HumanizacaoService $humanizacao,
        private UazapiService $uazapi,
    ) {}

    public function enviarTexto(WhatsappCanal $canal, string $telefone, string $texto): bool
    {
        $token = $canal->tokenUazapi();

        if (! $token) {
            Log::warning('UazapiChannelService: canal sem token, mensagem não enviada', ['canal_id' => $canal->id]);
            return false;
        }

        return $this->humanizacao->processar($token, $telefone, $texto);
    }

    /**
     * Envio imediato, sem humanização — mesmo caminho que a resposta manual do
     * Kanban já usava antes da Task 7 (chamada direta a UazapiService::enviarTexto()).
     */
    public function enviarTextoDireto(WhatsappCanal $canal, string $telefone, string $texto): bool
    {
        $token = $canal->tokenUazapi();

        if (! $token) {
            Log::warning('UazapiChannelService: canal sem token, mensagem não enviada', ['canal_id' => $canal->id]);
            return false;
        }

        return $this->uazapi->enviarTexto($token, $telefone, $texto);
    }

    public function enviarImagem(WhatsappCanal $canal, string $telefone, string $url, string $caption = ''): bool
    {
        $token = $canal->tokenUazapi();

        if (! $token) {
            Log::warning('UazapiChannelService: canal sem token, mensagem não enviada', ['canal_id' => $canal->id]);
            return false;
        }

        return $this->uazapi->enviarImagem($token, $telefone, $url, $caption);
    }

    public function enviarAudio(WhatsappCanal $canal, string $telefone, string $url, bool $ptt = true): bool
    {
        $token = $canal->tokenUazapi();

        if (! $token) {
            Log::warning('UazapiChannelService: canal sem token, mensagem não enviada', ['canal_id' => $canal->id]);
            return false;
        }

        return $this->uazapi->enviarAudio($token, $telefone, $url, $ptt);
    }

    public function enviarDocumento(WhatsappCanal $canal, string $telefone, string $url, string $filename = '', string $caption = ''): bool
    {
        $token = $canal->tokenUazapi();

        if (! $token) {
            Log::warning('UazapiChannelService: canal sem token, mensagem não enviada', ['canal_id' => $canal->id]);
            return false;
        }

        return $this->uazapi->enviarDocumento($token, $telefone, $url, $filename, $caption);
    }

    public function enviarSticker(WhatsappCanal $canal, string $telefone, string $url): bool
    {
        $token = $canal->tokenUazapi();

        if (! $token) {
            Log::warning('UazapiChannelService: canal sem token, mensagem não enviada', ['canal_id' => $canal->id]);
            return false;
        }

        return $this->uazapi->enviarSticker($token, $telefone, $url);
    }
}
