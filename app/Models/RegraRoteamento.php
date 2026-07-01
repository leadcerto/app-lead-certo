<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RegraRoteamento extends Model
{
    protected $table = 'regras_roteamento';

    protected $fillable = ['sdr_persona_id', 'tag', 'peso'];

    public function persona(): BelongsTo
    {
        return $this->belongsTo(SdrPersona::class, 'sdr_persona_id');
    }
}
