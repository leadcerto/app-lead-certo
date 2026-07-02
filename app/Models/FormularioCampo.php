<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FormularioCampo extends Model
{
    protected $table = 'formulario_campos';

    protected $fillable = [
        'formulario_id',
        'chave',
        'rotulo',
        'tipo',
        'opcoes',
        'obrigatorio',
        'ordem',
    ];

    protected $casts = [
        'opcoes'      => 'array',
        'obrigatorio' => 'boolean',
    ];

    public function formulario(): BelongsTo
    {
        return $this->belongsTo(Formulario::class);
    }
}
