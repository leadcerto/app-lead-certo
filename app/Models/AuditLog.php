<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AuditLog extends Model
{
    protected $table      = 'audit_logs';
    const CREATED_AT      = 'criado_em';
    const UPDATED_AT      = null;
    public $timestamps    = false;

    protected $fillable = [
        'usuario_id', 'usuario_nome', 'tabela', 'registro_id',
        'acao', 'campo', 'valor_antigo', 'valor_novo', 'contexto', 'criado_em',
    ];

    protected function casts(): array
    {
        return [
            'contexto'   => 'array',
            'criado_em'  => 'datetime',
        ];
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'usuario_id');
    }

    public static function registrar(
        string $tabela,
        int    $registroId,
        string $acao,
        ?string $campo = null,
        mixed  $valorAntigo = null,
        mixed  $valorNovo = null,
        array  $contexto = []
    ): void {
        static::create([
            'usuario_id'   => auth()->id(),
            'usuario_nome' => auth()->user()?->nome ?? 'Sistema',
            'tabela'       => $tabela,
            'registro_id'  => $registroId,
            'acao'         => $acao,
            'campo'        => $campo,
            'valor_antigo' => is_array($valorAntigo) ? json_encode($valorAntigo) : $valorAntigo,
            'valor_novo'   => is_array($valorNovo) ? json_encode($valorNovo) : $valorNovo,
            'contexto'     => $contexto ?: null,
            'criado_em'    => now(),
        ]);
    }
}
