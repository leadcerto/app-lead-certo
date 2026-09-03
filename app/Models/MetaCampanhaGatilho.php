<?php

namespace App\Models;

use App\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MetaCampanhaGatilho extends Model
{
    protected $table = 'meta_campanhas_gatilho';

    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope());
    }

    protected $fillable = [
        'tenant_id',
        'nome',
        'canal_alvo',
        'instagram_conta_id',
        'facebook_pagina_id',
        'post_id_especifico',
        'modo_gatilho',
        'palavras_chave',
        'resposta_publica_comentario',
        'mensagem_direct',
        'ativo',
    ];

    protected function casts(): array
    {
        return [
            'palavras_chave' => 'array',
            'ativo'          => 'boolean',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function contaInstagram(): BelongsTo
    {
        return $this->belongsTo(MetaContaInstagram::class, 'instagram_conta_id');
    }

    public function paginaFacebook(): BelongsTo
    {
        return $this->belongsTo(MetaPagina::class, 'facebook_pagina_id');
    }

    /**
     * Verifica se o texto do comentário satisfaz esta regra de gatilho.
     */
    public function satisfazGatilho(string $textoComentario): bool
    {
        if (! $this->ativo) {
            return false;
        }

        if ($this->modo_gatilho === 'qualquer_comentario') {
            return true;
        }

        if (empty($this->palavras_chave) || ! is_array($this->palavras_chave)) {
            return true;
        }

        $comentarioLimpo = mb_strtolower(trim($textoComentario));

        foreach ($this->palavras_chave as $kw) {
            $termo = mb_strtolower(trim($kw));
            if ($termo !== '' && str_contains($comentarioLimpo, $termo)) {
                return true;
            }
        }

        return false;
    }
}
