<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CampanhaMineracao extends Model
{
    protected $table = 'campanhas_mineracao';

    protected $fillable = [
        'tenant_id', 'criado_por', 'nome', 'descricao',
        'nicho', 'regiao_alvo', 'palavras_chave',
        'status', 'data_inicio', 'data_fim',
        'meta_contatos', 'contatos_importados', 'configuracoes',
    ];

    protected function casts(): array
    {
        return [
            'configuracoes'       => 'array',
            'data_inicio'         => 'date',
            'data_fim'            => 'date',
            'contatos_importados' => 'integer',
            'meta_contatos'       => 'integer',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function criador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'criado_por');
    }

    public function agentes(): HasMany
    {
        return $this->hasMany(AgenteMinerador::class, 'campanha_id');
    }

    public function progressoPercent(): int
    {
        if (! $this->meta_contatos || $this->meta_contatos === 0) return 0;
        return (int) min(100, ($this->contatos_importados / $this->meta_contatos) * 100);
    }
}
