<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SdrPersona extends Model
{
    protected $table = 'sdr_personas';

    protected $fillable = [
        'tenant_id', 'nome_interno', 'nome_display', 'genero',
        'idade_aparente', 'localidade', 'tom_de_voz',
        'system_prompt', 'avatar_url', 'ativo', 'is_default', 'tier',
    ];

    protected function casts(): array
    {
        return [
            'ativo'       => 'boolean',
            'is_default'  => 'boolean',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function regras(): HasMany
    {
        return $this->hasMany(RegraRoteamento::class, 'sdr_persona_id');
    }

    public function qaAuditorias(): HasMany
    {
        return $this->hasMany(QaAuditoria::class, 'sdr_persona_id');
    }
}
