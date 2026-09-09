<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AgentSkill extends Model
{
    protected $fillable = [
        'tenant_id',
        'origem',
        'categoria',
        'nome',
        'titulo',
        'descricao_curta',
        'descricao_completa',
        'instrucoes_base',
        'ativa'
    ];

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }
}
