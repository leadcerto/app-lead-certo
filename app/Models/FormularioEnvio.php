<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FormularioEnvio extends Model
{
    protected $table = 'formulario_envios';

    protected $fillable = [
        'formulario_id',
        'contato_id',
        'ticket_id',
        'dominio_origem',
        'dados_envio',
        'confirmado',
        'processado',
    ];

    protected $casts = [
        'dados_envio' => 'array',
        'confirmado'  => 'boolean',
        'processado'  => 'boolean',
    ];

    public function formulario(): BelongsTo
    {
        return $this->belongsTo(Formulario::class);
    }

    public function contato(): BelongsTo
    {
        return $this->belongsTo(Contato::class);
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(TicketAtendimento::class, 'ticket_id');
    }
}
