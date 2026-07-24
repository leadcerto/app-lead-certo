<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SequenciaMensagem extends Model
{
    protected $table = 'sequencia_mensagens';

    protected $fillable = [
        'sequencia_id',
        'tenant_id',
        'ordem',
        'conteudo',
        'imagem_url',
        'button_settings',
        'obrigatorio',
        'delay_segundos',
        'ativo',
    ];

    protected $casts = [
        'ativo'            => 'boolean',
        'obrigatorio'      => 'boolean',
        'button_settings'  => 'array',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function variacoes(): HasMany
    {
        return $this->hasMany(SequenciaMensagemVariacao::class, 'sequencia_mensagem_id');
    }
}
