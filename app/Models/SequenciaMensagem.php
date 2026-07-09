<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SequenciaMensagem extends Model
{
    protected $table = 'sequencia_mensagens';

    protected $fillable = [
        'sequencia_id',
        'tenant_id',
        'ordem',
        'conteudo',
        'imagem_url',
        'delay_segundos',
        'ativo',
    ];

    protected $casts = [
        'ativo' => 'boolean',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
