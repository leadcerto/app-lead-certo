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
        'followup_estagio1_segundos',
        'followup_estagio2_segundos',
        'followup_estagio3_segundos',
        'auto_mover_ativo',
        'auto_mover_coluna_destino',
        'auto_mover_segundos',
        'auto_mover_mensagem',
    ];

    protected $casts = [
        'ia_ativo'         => 'boolean',
        'auto_mover_ativo' => 'boolean',
    ];
}
