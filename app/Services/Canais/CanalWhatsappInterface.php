<?php

namespace App\Services\Canais;

use App\Models\WhatsappCanal;

interface CanalWhatsappInterface
{
    /**
     * Envia uma mensagem de texto para o telefone informado através do canal dado.
     * Retorna false (sem lançar exceção) em qualquer falha de envio — quem chama
     * decide como reagir (log, retry, etc), igual ao padrão já usado em UazapiService.
     */
    public function enviarTexto(WhatsappCanal $canal, string $telefone, string $texto): bool;

    /**
     * Envio imediato, sem humanização (usado pela resposta manual do atendente).
     * Uma única mensagem, sem divisão em balões nem delays simulados de digitação —
     * o atendente humano já digitou a mensagem, não há necessidade de simular nada.
     */
    public function enviarTextoDireto(WhatsappCanal $canal, string $telefone, string $texto): bool;

    /**
     * Envia uma imagem. $url deve ser uma URL pública acessível.
     * Retorna false (sem lançar exceção) em qualquer falha de envio.
     */
    public function enviarImagem(WhatsappCanal $canal, string $telefone, string $url, string $caption = ''): bool;

    /**
     * Envia um arquivo de áudio. $url deve ser uma URL pública acessível.
     * $ptt = true pede que apareça como nota de voz gravada na hora — cada
     * provedor decide se/como atender isso conforme suas próprias regras de formato.
     */
    public function enviarAudio(WhatsappCanal $canal, string $telefone, string $url, bool $ptt = true): bool;

    /**
     * Envia um documento/arquivo. $url deve ser uma URL pública acessível.
     */
    public function enviarDocumento(WhatsappCanal $canal, string $telefone, string $url, string $filename = '', string $caption = ''): bool;

    /**
     * Envia uma figurinha (.webp) — tipo de mídia próprio do WhatsApp, separado
     * de imagem comum.
     */
    public function enviarSticker(WhatsappCanal $canal, string $telefone, string $url): bool;
}
