<?php

namespace App\Models;

use App\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GestorKanbanRelatorio extends Model
{
    protected $table = 'gestor_kanban_relatorios';

    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope());
    }

    protected $fillable = [
        'tenant_id',
        'semana_inicio',
        'semana_fim',
        'dados',
        'sintese_geral',
    ];

    protected function casts(): array
    {
        return [
            'semana_inicio' => 'date',
            'semana_fim'    => 'date',
            'dados'         => 'array',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
