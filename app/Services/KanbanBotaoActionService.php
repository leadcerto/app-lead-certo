<?php

namespace App\Services;

use App\Models\TicketAtendimento;
use App\Models\KanbanColunaConfig;
use App\Models\VinculoContatoTenant;
use App\Services\UazapiService;
use Illuminate\Support\Facades\Log;

class KanbanBotaoActionService
{
    /**
     * Executa a ação configurada para $buttonId na coluna ATUAL do ticket.
     * $buttonId vem no formato "{action}:{indice}" (ver enviarBotoesDaColuna()
     * em UazapiWebhookController). Retorna false se não há config correspondente
     * — o chamador deve tratar isso como "não era um clique de botão conhecido".
     */
    public function executar(TicketAtendimento $ticket, string $buttonId): bool
    {
        [$action, $indice] = array_pad(explode(':', $buttonId, 2), 2, null);

        $config = KanbanColunaConfig::where('tenant_id', $ticket->tenant_id)
            ->where('coluna_kanban', $ticket->coluna_kanban)
            ->first();

        $botoes = $config?->button_settings ?? [];
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
     * Monta e envia o menu de botões configurado pra coluna ATUAL do ticket.
     * Retorna false sem erro se a coluna não tiver button_settings — chamado
     * de pontos que nem sempre têm botões configurados (ex: toda sequência).
     */
    public function enviarBotoesDaColuna(TicketAtendimento $ticket): bool
    {
        $config = KanbanColunaConfig::where('tenant_id', $ticket->tenant_id)
            ->where('coluna_kanban', $ticket->coluna_kanban)
            ->first();

        $botoes = $config?->button_settings ?? [];
        if (empty($botoes)) {
            return false;
        }

        $choices = [];
        foreach ($botoes as $i => $botao) {
            $texto  = $botao['text'] ?? '';
            $target = $botao['target'] ?? '';
            $choices[] = match ($botao['action'] ?? null) {
                'open_url' => "{$texto}|{$target}",
                'call'     => "{$texto}|call:{$target}",
                default    => "{$texto}|{$botao['action']}:{$i}",
            };
        }

        $telefone = $ticket->contato?->telefone;
        $token    = $ticket->tenant?->uazapi_instance_token;
        if (! $telefone || ! $token) {
            return false;
        }

        return app(UazapiService::class)->enviarMenuBotoes(
            $token,
            $telefone,
            $config->objetivo ?: 'Escolha uma opção:',
            $choices
        );
    }
}
