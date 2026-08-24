<?php

namespace App\Services;

use App\Models\TicketAtendimento;

/**
 * Camada 2 de detecção de idioma (ver
 * docs/superpowers/specs/2026-08-21-idioma-multilingue-atendimento-design.md):
 * pergunta explicitamente ao cliente qual idioma prefere, quando o DDI
 * diverge do locale do tenant. Uazapi manda botão interativo de verdade
 * (`KanbanBotaoActionService::enviarBotoes()`, infra já existente); Covercut
 * não tem suporte a botão interativo hoje (limitação já documentada em
 * docs/paridade-canais-whatsapp.md, não nova), então recebe um fallback de
 * texto numerado com o mesmo efeito.
 */
class IdiomaEscolhaService
{
    /**
     * @param array<string,string> $idiomasDisponiveis ['pt-BR' => 'Português', ...]
     */
    public function enviarEscolha(TicketAtendimento $ticket, array $idiomasDisponiveis): bool
    {
        $canal = $ticket->canal;
        if (! $canal) {
            return false;
        }

        $texto = '🌍 Notamos que seu número é de outro país. Em qual idioma você prefere ser atendido?';

        $enviado = $canal->provider === 'covercut'
            ? $this->enviarTextoNumerado($ticket, $texto, $idiomasDisponiveis)
            : $this->enviarBotoes($ticket, $texto, $idiomasDisponiveis);

        if ($enviado) {
            $ticket->update(['idioma_aguardando_escolha' => true]);
        }

        return $enviado;
    }

    private function enviarBotoes(TicketAtendimento $ticket, string $texto, array $idiomasDisponiveis): bool
    {
        $botoes = [];
        foreach ($idiomasDisponiveis as $codigo => $label) {
            $botoes[] = ['text' => $label, 'action' => 'idioma', 'target' => $codigo];
        }

        return app(KanbanBotaoActionService::class)->enviarBotoes($ticket, $texto, $botoes);
    }

    private function enviarTextoNumerado(TicketAtendimento $ticket, string $texto, array $idiomasDisponiveis): bool
    {
        $opcoes = [];
        $i = 1;
        foreach ($idiomasDisponiveis as $label) {
            $opcoes[] = "{$i}) {$label}";
            $i++;
        }

        $mensagemCompleta = $texto . "\n\nResponda com o número:\n" . implode("\n", $opcoes);

        $telefone = $ticket->contato?->telefone;
        if (! $telefone) {
            return false;
        }

        return $ticket->canal->servico()->enviarTextoDireto($ticket->canal, $telefone, $mensagemCompleta);
    }
}
