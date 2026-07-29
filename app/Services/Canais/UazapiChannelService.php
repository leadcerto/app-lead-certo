<?php

namespace App\Services\Canais;

use App\Models\WhatsappCanal;
use App\Services\HumanizacaoService;
use Illuminate\Support\Facades\Log;

/**
 * Envia mensagens por um canal não-oficial (Uazapi), preservando exatamente o
 * comportamento já em produção: divide em balões, simula digitação, aplica delay.
 * Não muda nada do HumanizacaoService — só resolve o token do canal e delega.
 */
class UazapiChannelService implements CanalWhatsappInterface
{
    public function __construct(private HumanizacaoService $humanizacao) {}

    public function enviarTexto(WhatsappCanal $canal, string $telefone, string $texto): bool
    {
        $token = $canal->tokenUazapi();

        if (! $token) {
            Log::warning('UazapiChannelService: canal sem token, mensagem não enviada', ['canal_id' => $canal->id]);
            return false;
        }

        $this->humanizacao->processar($token, $telefone, $texto);

        return true;
    }
}
