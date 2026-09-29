<?php

namespace App\Services\Canais;

use App\Models\WhatsappCanal;

/**
 * Capacidade separada de CanalWhatsappInterface de propósito: listar grupos
 * é exclusivo de canais não-oficiais (Uazapi, Messenger próprio) — a API
 * oficial (Covercut) não expõe grupos, e forçar CovercutChannelService a
 * implementar isso (só pra devolver array vazio sempre) esconderia essa
 * limitação estrutural em vez de deixá-la explícita no tipo. Quem chama usa
 * `$canal->servico() instanceof CanalComGruposInterface` pra saber se o
 * canal suporta.
 */
interface CanalComGruposInterface
{
    /**
     * Retorna os grupos/comunidades que a sessão do canal participa, com
     * participantes. Só leitura — nenhuma implementação desta interface pode
     * enviar mensagem nenhuma.
     *
     * Formato: [['jid' => string, 'nome' => string, 'participantes' => [['telefone' => string], ...]], ...]
     */
    public function listarGrupos(WhatsappCanal $canal): array;
}
