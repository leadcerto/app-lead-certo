<?php
// app/Services/AlertaInternoService.php
namespace App\Services;

use App\Models\AlertaInterno;
use Illuminate\Support\Str;

class AlertaInternoService
{
    public function criar(
        int $tenantId,
        string $tipo,
        string $titulo,
        string $conteudo,
        ?int $ticketId = null,
    ): AlertaInterno {
        return AlertaInterno::create([
            'tenant_id' => $tenantId,
            'ticket_id' => $ticketId,
            'tipo'      => Str::limit($tipo, 50, ''),
            'titulo'    => Str::limit($titulo, 150, ''),
            'conteudo'  => $conteudo,
        ]);
    }

    /**
     * Bloco 5 — fecha o alerta de dúvida pendente (tipo 'duvida_ia', sem
     * resposta ainda) de um ticket, sem exigir uma resposta real do humano.
     * Usado quando a pausa termina por outro motivo que não uma orientação
     * de verdade: timeout (ExpirarPausaOrientacao) ou mudança de coluna
     * (TicketAtendimento::updating()). Mesma query que KanbanController::orientar()
     * já usa pra achar o alerta certo. Sem-efeito se não houver alerta
     * pendente (idempotente — seguro chamar mesmo sem ter certeza que existe).
     */
    public function fecharDuvidaPendente(int $tenantId, int $ticketId, string $motivo): void
    {
        AlertaInterno::where('tenant_id', $tenantId)
            ->where('ticket_id', $ticketId)
            ->where('tipo', 'duvida_ia')
            ->whereNull('resposta')
            ->latest('id')
            ->first()
            ?->update(['resposta' => $motivo, 'respondido_em' => now()]);
    }
}
