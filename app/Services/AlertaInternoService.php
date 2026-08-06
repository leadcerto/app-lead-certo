<?php
// app/Services/AlertaInternoService.php
namespace App\Services;

use App\Models\AlertaInterno;

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
            'tipo'      => $tipo,
            'titulo'    => $titulo,
            'conteudo'  => $conteudo,
        ]);
    }
}
