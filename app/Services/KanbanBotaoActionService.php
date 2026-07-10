<?php

namespace App\Services;

use App\Models\TicketAtendimento;
use App\Models\VinculoContatoTenant;
use App\Services\UazapiService;
use Illuminate\Support\Facades\Log;

class KanbanBotaoActionService
{
    /**
     * Executa a ação configurada para $buttonId, validando contra os botões
     * que foram REALMENTE enviados por último a este ticket ($ticket->botoes_ativos,
     * preenchido por enviarBotoes()) — não uma config que pode ter mudado desde o envio.
     * $buttonId vem no formato "{action}:{indice}" (índice dentro desse array).
     * Retorna false se não há correspondência — o chamador deve tratar isso como
     * "não era um clique de botão conhecido".
     */
    public function executar(TicketAtendimento $ticket, string $buttonId): bool
    {
        [$action, $indice] = array_pad(explode(':', $buttonId, 2), 2, null);

        $botoes = $ticket->botoes_ativos ?? [];
        $botao  = $botoes[(int) $indice] ?? null;

        if (! $botao || ($botao['action'] ?? null) !== $action) {
            return false;
        }

        return match ($action) {
            'move_column' => $this->moverColuna($ticket, $botao['target'] ?? null),
            'trigger_ia'  => $this->acionarIa($ticket),
            'opt_out'     => $this->optOut($ticket),
            default       => false,
        };
    }

    private function moverColuna(TicketAtendimento $ticket, ?string $destino): bool
    {
        if (! $destino) {
            Log::warning('KanbanBotaoActionService: move_column sem target', ['ticket_id' => $ticket->id]);
            return false;
        }

        $ticket->update(['coluna_kanban' => $destino]);
        return true;
    }

    private function acionarIa(TicketAtendimento $ticket): bool
    {
        $ticket->update(['agente_responsavel' => 'bot']);
        return true;
    }

    private function optOut(TicketAtendimento $ticket): bool
    {
        VinculoContatoTenant::updateOrCreate(
            ['contato_id' => $ticket->contato_id, 'tenant_id' => $ticket->tenant_id],
            ['bloqueado_em' => now()]
        );

        return true;
    }

    /**
     * Monta e envia UMA mensagem de texto + até 3 botões (menu interativo) pro
     * lead. $texto é o corpo da mensagem (o conteúdo da sequência, com variáveis
     * já resolvidas) e $botoes é o button_settings da mensagem específica que
     * está sendo enviada. Em caso de sucesso, grava os botões enviados em
     * $ticket->botoes_ativos pra o clique poder ser validado depois.
     */
    public function enviarBotoes(TicketAtendimento $ticket, string $texto, array $botoes): bool
    {
        if (empty($botoes)) {
            return false;
        }

        $choices = [];
        foreach ($botoes as $i => $botao) {
            $textoBotao = $botao['text'] ?? '';
            $target     = $botao['target'] ?? '';
            $choices[] = match ($botao['action'] ?? null) {
                'open_url' => "{$textoBotao}|{$target}",
                'call'     => "{$textoBotao}|call:{$target}",
                default    => "{$textoBotao}|{$botao['action']}:{$i}",
            };
        }

        $telefone = $ticket->contato?->telefone;
        $token    = $ticket->tenant?->uazapi_instance_token;
        if (! $telefone || ! $token) {
            return false;
        }

        $enviado = app(UazapiService::class)->enviarMenuBotoes(
            $token,
            $telefone,
            $texto ?: 'Escolha uma opção:',
            $choices
        );

        if ($enviado) {
            $ticket->update(['botoes_ativos' => $botoes]);
        }

        return $enviado;
    }
}
