<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ServicoExecutado extends Model
{
    protected $table = 'servicos_executados';

    protected $fillable = [
        'user_id', 'descricao', 'motivo', 'grau_dificuldade', 'tempo_gasto_minutos', 'executado_em',
    ];

    protected function casts(): array
    {
        return ['executado_em' => 'datetime'];
    }

    public function agente(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
