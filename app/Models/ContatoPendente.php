<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ContatoPendente extends Model
{
    protected $table   = 'contatos_pendentes';
    const CREATED_AT   = 'criado_em';
    const UPDATED_AT   = null;
    public $timestamps = false;

    protected $fillable = [
        'tenant_id', 'telefone', 'nome', 'dados_brutos',
        'tipo_conflito',
        'contato_existente_id', 'nome_existente', 'similaridade_nome',
        'status', 'resolvido_por', 'resolvido_em', 'observacoes',
        'criado_em',
    ];

    protected function casts(): array
    {
        return [
            'dados_brutos' => 'array',
            'criado_em'    => 'datetime',
            'resolvido_em' => 'datetime',
        ];
    }

    public function contatoExistente(): BelongsTo
    {
        return $this->belongsTo(Contato::class, 'contato_existente_id');
    }
}
