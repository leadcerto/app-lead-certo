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

    /**
     * Pedido do Leonardo (2026-08-26): números de spam/telemarketing que ligam
     * pra Secretária Eletrônica não têm WhatsApp — o sistema mandava mensagem
     * assim mesmo e marcava como "enviada", deixando o ticket esperando resposta
     * de um número que nunca vai receber nada. Indica se a falha do ÚLTIMO envio
     * (enviarTexto/enviarTextoDireto/enviarImagem) nesta instância foi
     * especificamente porque o telefone não é um número WhatsApp válido — quem
     * chama usa isso pra distinguir de outras falhas (janela fechada, rede, token)
     * e marcar a origem (ex: ChamadaPerdida) corretamente. Deve retornar false
     * quando não houve falha, e também quando o canal não tem como detectar isso.
     */
    public function ultimoEnvioFalhouPorNumeroInvalido(): bool;
}
