<?php

namespace App\Services;

use App\Models\Kanban;
use App\Models\WhatsappCanal;

class SelecaoCanalWhatsappService
{
    /**
     * Canal pra iniciar contato PROATIVO com um lead que ainda não mandou
     * mensagem pelo WhatsApp (ligação perdida, formulário, prospecção fria).
     * Prefere canal não-oficial — sem restrição de janela de 24h da Meta, então
     * sempre consegue iniciar a conversa.
     *
     * Cai pro canal oficial só quando não há nenhum não-oficial conectado —
     * achado em 2026-08-03: o Frete Rio ficou sem canal não-oficial desde o
     * incidente do botão "Remover" (29/07, ver [[arquitetura-canais-whatsapp]]
     * na memória), e as 3 chamadas deste método (SecretariaEletronicaController,
     * FormularioService, Internal\TicketController) sempre retornavam null,
     * deixando 163 tickets abertos sem NENHUM canal vinculado — nem dava pra
     * responder manualmente pelo painel depois. Melhor tentar pelo oficial e
     * arriscar a Meta rejeitar (lead nunca falou por WhatsApp, fora da janela
     * de 24h) do que nunca tentar e deixar o ticket travado sem canal algum.
     */
    public function naoOficialAleatorioParaKanban(Kanban $kanban): ?WhatsappCanal
    {
        return $this->conectadoParaKanban($kanban, 'nao_oficial')
            ?? $this->conectadoParaKanban($kanban, 'oficial');
    }

    private function conectadoParaKanban(Kanban $kanban, string $tipo): ?WhatsappCanal
    {
        return $kanban->canais()
            ->where('tipo', $tipo)
            ->where('status', 'connected')
            ->inRandomOrder()
            ->first();
    }
}
