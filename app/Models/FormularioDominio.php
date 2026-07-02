<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FormularioDominio extends Model
{
    protected $table = 'formulario_dominios';

    protected $fillable = ['formulario_id', 'dominio'];

    public function formulario(): BelongsTo
    {
        return $this->belongsTo(Formulario::class);
    }
}
