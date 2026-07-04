<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SequenciaMensagem extends Model
{
    protected $fillable = [
        'tenant_id',
        'ordem',
        'conteudo',
        'delay_minutos',
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
