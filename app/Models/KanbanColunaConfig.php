<?php

namespace App\Models;

use App\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Model;

class KanbanColunaConfig extends Model
{
    protected $table = 'kanban_coluna_configs';

    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope());
    }

    protected $fillable = [
        'tenant_id',
        'coluna_kanban',
        'objetivo',
        'seq_objetivo',
        'ia_objetivo',
        'ia_contexto',
        'ia_ativo',
        'sdr_delay_segundos',
    ];

    protected $casts = [
        'ia_ativo' => 'boolean',
    ];
}
