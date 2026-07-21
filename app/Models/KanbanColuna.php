<?php

namespace App\Models;

use App\Enums\PapelColunaKanban;
use App\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class KanbanColuna extends Model
{
    protected $table = 'kanban_colunas';

    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope());
    }

    protected $fillable = [
        'tenant_id',
        'kanban_id',
        'chave',
        'label',
        'emoji',
        'papel',
        'ordem',
    ];

    protected function casts(): array
    {
        return [
            'papel' => PapelColunaKanban::class,
            'ordem' => 'integer',
        ];
    }

    public function kanban(): BelongsTo
    {
        return $this->belongsTo(Kanban::class);
    }
}
