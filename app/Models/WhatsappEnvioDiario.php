<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WhatsappEnvioDiario extends Model
{
    protected $table = 'whatsapp_envios_diarios';

    protected $fillable = [
        'whatsapp_canal_id',
        'data',
        'contador_frio',
        'contador_quente',
        'sequencia_frios_atual',
        'ultima_conversa_fria_em',
        'frio_50_atingido_em',
    ];

    protected $casts = [
        'data'                    => 'date',
        'ultima_conversa_fria_em' => 'datetime',
        'frio_50_atingido_em'     => 'datetime',
    ];

    public function canal(): BelongsTo
    {
        return $this->belongsTo(WhatsappCanal::class, 'whatsapp_canal_id');
    }
}
