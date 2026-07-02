<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DispositivoRegistrado extends Model
{
    protected $table = 'dispositivos_registrados';

    protected $fillable = [
        'tenant_id',
        'user_id',
        'fcm_token',
        'dispositivo',
        'ativo',
        'ultimo_ping_em',
    ];

    protected $casts = [
        'ativo'          => 'boolean',
        'ultimo_ping_em' => 'datetime',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
