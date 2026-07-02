<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Formulario extends Model
{
    protected $table = 'formularios';

    protected $fillable = [
        'tenant_id',
        'uuid',
        'nome',
        'acao_pos_envio',
        'mensagem_custom',
        'double_optin',
        'ativo',
    ];

    protected $casts = [
        'double_optin' => 'boolean',
        'ativo'        => 'boolean',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $model) {
            $model->uuid ??= (string) Str::uuid();
        });
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function dominios(): HasMany
    {
        return $this->hasMany(FormularioDominio::class);
    }

    public function campos(): HasMany
    {
        return $this->hasMany(FormularioCampo::class)->orderBy('ordem');
    }

    public function envios(): HasMany
    {
        return $this->hasMany(FormularioEnvio::class);
    }
}
