<?php

namespace App\Services\Canais;

use App\Models\WhatsappCanal;
use App\Services\AquecimentoWhatsappService;
use App\Services\HumanizacaoService;
use App\Services\UazapiService;
use Illuminate\Support\Facades\Log;

/**
 * Envia mensagens por um canal não-oficial (Uazapi), preservando exatamente o
 * comportamento já em produção: divide em balões, simula digitação, aplica delay.
 * Não muda nada do HumanizacaoService — só resolve o token do canal e delega.
 *
 * Achado 2026-08-19: todo número não-oficial passa por aquecimento pra sempre
 * (decisão do Leonardo) — a trava fica aqui porque é o único ponto por onde TODO
 * envio não-oficial passa, seja via SDR/sequência (enviarTexto, com humanização)
 * ou via resposta manual do atendente no Kanban (enviarTextoDireto/enviarImagem/
 * etc.) — o WhatsApp não distingue a origem do envio, então nenhum caminho pode
 * escapar do teto.
 */
class UazapiChannelService implements CanalWhatsappInterface
{
    public function __construct(
        private HumanizacaoService $humanizacao,
        private UazapiService $uazapi,
        private AquecimentoWhatsappService $aquecimento,
    ) {}

    /**
     * Checagem única de token+aquecimento, compartilhada por todo método de envio.
     * Retorna o token se pode enviar, ou null (já logado) se deve bloquear.
     */
    private function tokenSeAutorizado(WhatsappCanal $canal, string $telefone): ?string
    {
        $token = $canal->tokenUazapi();

        if (! $token) {
            Log::warning('UazapiChannelService: canal sem token, mensagem não enviada', ['canal_id' => $canal->id]);
            return null;
        }

        if (! $this->aquecimento->podeEnviar($canal, $telefone)) {
            Log::warning('UazapiChannelService: envio bloqueado pelo teto de aquecimento', [
                'canal_id' => $canal->id, 'telefone' => $telefone,
            ]);
            return null;
        }

        return $token;
    }

    public function enviarTexto(WhatsappCanal $canal, string $telefone, string $texto): bool
    {
        $token = $this->tokenSeAutorizado($canal, $telefone);
        if (! $token) {
            return false;
        }

        $enviado = $this->humanizacao->processar($token, $telefone, $texto);
        if ($enviado) {
            $this->aquecimento->registrarEnvio($canal, $telefone);
        }

        return $enviado;
    }

    /**
     * Envio imediato, sem humanização — mesmo caminho que a resposta manual do
     * Kanban já usava antes da Task 7 (chamada direta a UazapiService::enviarTexto()).
     */
    public function enviarTextoDireto(WhatsappCanal $canal, string $telefone, string $texto): bool
    {
        $token = $this->tokenSeAutorizado($canal, $telefone);
        if (! $token) {
            return false;
        }

        $enviado = $this->uazapi->enviarTexto($token, $telefone, $texto);
        if ($enviado) {
            $this->aquecimento->registrarEnvio($canal, $telefone);
        }

        return $enviado;
    }

    public function enviarImagem(WhatsappCanal $canal, string $telefone, string $url, string $caption = ''): bool
    {
        $token = $this->tokenSeAutorizado($canal, $telefone);
        if (! $token) {
            return false;
        }

        $enviado = $this->uazapi->enviarImagem($token, $telefone, $url, $caption);
        if ($enviado) {
            $this->aquecimento->registrarEnvio($canal, $telefone);
        }

        return $enviado;
    }

    public function enviarAudio(WhatsappCanal $canal, string $telefone, string $url, bool $ptt = true): bool
    {
        $token = $this->tokenSeAutorizado($canal, $telefone);
        if (! $token) {
            return false;
        }

        $enviado = $this->uazapi->enviarAudio($token, $telefone, $url, $ptt);
        if ($enviado) {
            $this->aquecimento->registrarEnvio($canal, $telefone);
        }

        return $enviado;
    }

    public function enviarDocumento(WhatsappCanal $canal, string $telefone, string $url, string $filename = '', string $caption = ''): bool
    {
        $token = $this->tokenSeAutorizado($canal, $telefone);
        if (! $token) {
            return false;
        }

        $enviado = $this->uazapi->enviarDocumento($token, $telefone, $url, $filename, $caption);
        if ($enviado) {
            $this->aquecimento->registrarEnvio($canal, $telefone);
        }

        return $enviado;
    }

    public function enviarSticker(WhatsappCanal $canal, string $telefone, string $url): bool
    {
        $token = $this->tokenSeAutorizado($canal, $telefone);
        if (! $token) {
            return false;
        }

        $enviado = $this->uazapi->enviarSticker($token, $telefone, $url);
        if ($enviado) {
            $this->aquecimento->registrarEnvio($canal, $telefone);
        }

        return $enviado;
    }
}
