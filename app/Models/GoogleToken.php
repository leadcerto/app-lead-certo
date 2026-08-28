<?php

namespace App\Models;

use App\Jobs\ProvisionarEtiquetasGoogleJob;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GoogleToken extends Model
{
    protected $table = 'google_tokens';

    protected static function booted(): void
    {
        // Pedido do Leonardo (2026-08-28): a mesma orientação de etiquetas
        // vale pra qualquer empresa — assim que uma conecta o Google pela
        // primeira vez, já provisiona os grupos sozinha, sem passo manual.
        static::created(function (GoogleToken $token) {
            ProvisionarEtiquetasGoogleJob::dispatch($token->id);
        });
    }

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
