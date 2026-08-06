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
}
