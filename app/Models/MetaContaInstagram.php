<?php

namespace App\Models;

use App\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MetaContaInstagram extends Model
{
    protected $table = 'meta_contas_instagram';

    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope());
    }

    protected $fillable = [
        'tenant_id',
        'meta_pagina_id',
        'instagram_business_id',
        'username',
        'nome',
        'foto_perfil_url',
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

    public function pagina(): BelongsTo
    {
        return $this->belongsTo(MetaPagina::class, 'meta_pagina_id');
    }
}
