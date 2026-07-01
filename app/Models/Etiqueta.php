<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Etiqueta extends Model
{
    protected $table = 'etiquetas';

    protected $fillable = ['tenant_id', 'nome', 'slug', 'cor', 'ativo'];

    protected $casts = ['ativo' => 'boolean'];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function googleGrupos(): HasMany
    {
        return $this->hasMany(EtiquetaGoogleGrupo::class);
    }

    public function googleGrupoParaTenant(int $tenantId): ?EtiquetaGoogleGrupo
    {
        return $this->googleGrupos()->where('tenant_id', $tenantId)->first();
    }

    /** Retorna todas as etiquetas visíveis para um tenant (sistema + próprias). */
    public static function paraTenant(int $tenantId)
    {
        return static::where(function ($q) use ($tenantId) {
            $q->whereNull('tenant_id')->orWhere('tenant_id', $tenantId);
        })->where('ativo', true);
    }
}
