<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SequenciaMensagemVariacao extends Model
{
    protected $table = 'sequencia_mensagem_variacoes';

    protected $fillable = [
        'tenant_id',
        'sequencia_mensagem_id',
        'conteudo',
        'origem',
        'protegida',
        'ativa',
        'substituida_em',
    ];

    protected $casts = [
        'protegida'      => 'boolean',
        'ativa'          => 'boolean',
        'substituida_em' => 'datetime',
    ];

    public function mensagem(): BelongsTo
    {
        return $this->belongsTo(SequenciaMensagem::class, 'sequencia_mensagem_id');
    }
}
