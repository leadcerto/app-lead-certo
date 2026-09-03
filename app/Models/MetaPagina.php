<?php

namespace App\Models;

use App\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MetaPagina extends Model
{
    protected $table = 'meta_paginas';

    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope());
    }

    protected $fillable = [
        'tenant_id',
        'meta_token_id',
        'facebook_page_id',
        'nome',
        'categoria',
        'page_access_token',
        'foto_url',
        'ativo',
    ];

    protected function casts(): array
    {
        return [
            'ativo' => 'boolean',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function token(): BelongsTo
    {
        return $this->belongsTo(MetaToken::class, 'meta_token_id');
    }

    public function contasInstagram(): HasMany
    {
        return $this->hasMany(MetaContaInstagram::class, 'meta_pagina_id');
    }
}
