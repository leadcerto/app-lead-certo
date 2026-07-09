<?php

namespace App\Models;

use App\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Sequencia extends Model
{
    protected $table = 'sequencias';

    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope());
    }

    protected $fillable = [
        'tenant_id',
        'nome',
        'descricao',
        'coluna_kanban',
        'ativo',
    ];

    protected $casts = [
        'ativo' => 'boolean',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function mensagens(): HasMany
    {
        return $this->hasMany(SequenciaMensagem::class, 'sequencia_id')->orderBy('ordem');
    }
}
