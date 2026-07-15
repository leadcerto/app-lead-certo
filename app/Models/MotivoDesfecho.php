<?php

namespace App\Models;

use App\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Model;

class MotivoDesfecho extends Model
{
    protected $table = 'motivos_desfecho';

    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope());
    }

    protected $fillable = [
        'tenant_id',
        'chave',
        'label',
        'e_venda',
        'ordem',
    ];

    protected $casts = [
        'e_venda' => 'boolean',
        'ordem'   => 'integer',
    ];
}
