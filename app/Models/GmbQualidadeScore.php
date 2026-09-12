<?php

namespace App\Models;

use App\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GmbQualidadeScore extends Model
{
    protected $table = 'gmb_qualidade_scores';

    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope);
    }

    protected $fillable = [
        'tenant_id',
        'perfil_gmb_id',
        'nota_geral',
        'categorias',
        'avaliado_em',
    ];

    protected function casts(): array
    {
        return [
            'categorias'  => 'array',
            'avaliado_em' => 'datetime',
        ];
    }

    public function perfil(): BelongsTo
    {
        return $this->belongsTo(PerfilGmb::class, 'perfil_gmb_id');
    }
}
