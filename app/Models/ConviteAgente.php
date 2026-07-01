<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ConviteAgente extends Model
{
    protected $table = 'convites_agente';

    protected $fillable = ['tenant_id', 'email', 'token', 'perfil', 'nome', 'expires_at', 'accepted_at'];

    protected $casts = [
        'expires_at'  => 'datetime',
        'accepted_at' => 'datetime',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function isPendente(): bool
    {
        return is_null($this->accepted_at) && $this->expires_at->isFuture();
    }
}
