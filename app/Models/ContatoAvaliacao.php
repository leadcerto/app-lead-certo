<?php

namespace App\Models;

use App\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ContatoAvaliacao extends Model
{
    protected $table = 'contatos_avaliacao';

    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope());
    }

    protected $fillable = [
        'tenant_id', 'perfil_id', 'nome', 'telefone', 'contatado_em',
    ];

    protected function casts(): array
    {
        return [
            'contatado_em' => 'datetime',
        ];
    }

    public function perfil(): BelongsTo
    {
        return $this->belongsTo(PerfilGmb::class, 'perfil_id');
    }

    public function scopeNaoContatados($query)
    {
        return $query->whereNull('contatado_em');
    }

    public function marcarContatado(): bool
    {
        return $this->update(['contatado_em' => now()]);
    }
}
