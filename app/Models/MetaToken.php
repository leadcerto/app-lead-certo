<?php

namespace App\Models;

use App\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MetaToken extends Model
{
    protected $table = 'meta_tokens';

    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope());
    }

    protected $fillable = [
        'tenant_id',
        'meta_user_id',
        'nome_usuario',
        'access_token',
        'expires_at',
        'scopes',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'scopes'     => 'array',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function paginas(): HasMany
    {
        return $this->hasMany(MetaPagina::class, 'meta_token_id');
    }
}
