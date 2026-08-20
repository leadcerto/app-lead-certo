<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Cargo extends Model
{
    protected $fillable = ['nome', 'descricao', 'cargo_pai_id', 'ordem', 'ativo', 'visivel_para_clientes'];

    protected function casts(): array
    {
        return ['ativo' => 'boolean', 'visivel_para_clientes' => 'boolean'];
    }

    public function agentes(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'agente_cargo');
    }

    public function cargoPai(): BelongsTo
    {
        return $this->belongsTo(Cargo::class, 'cargo_pai_id');
    }

    public function subordinados(): HasMany
    {
        return $this->hasMany(Cargo::class, 'cargo_pai_id');
    }
}
