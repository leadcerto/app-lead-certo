<?php

namespace App\Models;

use App\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Kanban extends Model
{
    protected $table = 'kanbans';

    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope());
    }

    protected $fillable = [
        'tenant_id',
        'tipo',
        'nome',
        'ordem',
    ];

    protected $casts = [
        'ordem' => 'integer',
    ];

    public function colunas(): HasMany
    {
        return $this->hasMany(KanbanColuna::class)->orderBy('ordem');
    }
}
