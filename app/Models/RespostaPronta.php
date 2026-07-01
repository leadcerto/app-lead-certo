<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RespostaPronta extends Model
{
    protected $table = 'respostas_prontas';

    protected $fillable = ['tenant_id', 'codigo_curto', 'conteudo', 'ativo', 'created_by'];

    protected $casts = ['ativo' => 'boolean'];

    public function scopeDoTenant($query, int $tenantId)
    {
        return $query->where('tenant_id', $tenantId);
    }

    public function scopeAtivo($query)
    {
        return $query->where('ativo', true);
    }
}
