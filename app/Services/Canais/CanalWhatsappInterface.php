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
}
