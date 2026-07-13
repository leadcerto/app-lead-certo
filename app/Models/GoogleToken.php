<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GoogleToken extends Model
{
    protected $table = 'google_tokens';

    protected $fillable = [
        'tenant_id',
        'google_email',
        'access_token',
        'refresh_token',
        'token_type',
        'expires_at',
        'scopes',
        'falha_renovacao_em',
    ];

    protected $casts = [
        'expires_at'         => 'datetime',
        'scopes'              => 'array',
        'falha_renovacao_em' => 'datetime',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function expirado(): bool
    {
        return $this->expires_at->isPast();
    }
}
