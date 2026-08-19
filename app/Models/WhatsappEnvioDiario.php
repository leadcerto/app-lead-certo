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
    ];

    protected $casts = [
        'data' => 'date',
    ];

    public function canal(): BelongsTo
    {
        return $this->belongsTo(WhatsappCanal::class, 'whatsapp_canal_id');
    }
}
