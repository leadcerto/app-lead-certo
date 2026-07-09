<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AuditoriaContato extends Model
{
    protected $table = 'auditoria_contatos';

    public $timestamps = false;

    protected $fillable = [
        'contato_id',
        'tipo',
        'campo',
        'valor_original',
        'valor_sugerido',
        'observacao',
        'status',
        'resolvido_em',
    ];

    protected function casts(): array
    {
        return [
            'created_at'   => 'datetime',
            'resolvido_em' => 'datetime',
        ];
    }

    public function contato(): BelongsTo
    {
        return $this->belongsTo(Contato::class);
    }

    public function scopePendente($query)
    {
        return $query->where('status', 'pendente');
    }
}
