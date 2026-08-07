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
        'kanban_coluna_id',
        'objetivo',
        'seq_objetivo',
        'ia_objetivo',
        'ia_contexto',
        'etapa_ia_ao_mover',
        'foco_analise_imagem',
        'transcricao_ativa',
        'ia_ativo',
        'sdr_delay_segundos',
        'followup_estagio1_segundos',
        'followup_estagio2_segundos',
        'followup_estagio3_segundos',
        'auto_mover_ativo',
        'auto_mover_coluna_destino',
        'auto_mover_segundos',
        'auto_mover_mensagem',
        'timeout_reassuncao_ativo',
        'timeout_reassuncao_segundos',
        'aguardando_orientacao_mensagem',
        'tempo_maximo_permanencia_minutos',
        'exclusao_definitiva_ativo',
        'exclusao_definitiva_dias',
    ];

    protected $casts = [
        'ia_ativo'                  => 'boolean',
        'transcricao_ativa'         => 'boolean',
        'auto_mover_ativo'          => 'boolean',
        'timeout_reassuncao_ativo'  => 'boolean',
        'exclusao_definitiva_ativo' => 'boolean',
    ];
}
