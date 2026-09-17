<?php

namespace App\Models;

use App\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Banco de Conteúdos reutilizáveis pra Postagens Meta (Facebook/Instagram) —
 * separa o CONTEÚDO (texto, imagem, CTA, gatilho de comentário) da instância
 * agendada (MetaPost). Um mesmo conteúdo pode originar várias postagens em
 * datas diferentes, sem precisar recriar tudo do zero a cada vez.
 */
class MetaPostConteudo extends Model
{
    protected $table = 'meta_post_conteudos';

    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope);
    }

    protected $fillable = [
        'tenant_id',
        'categoria',
        'titulo',
        'texto',
        'imagem_url',
        'cta_tipo',
        'cta_url',
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

    public function posts(): HasMany
    {
        return $this->hasMany(MetaPost::class, 'meta_post_conteudo_id');
    }

    public function scopeAtivos(Builder $query): Builder
    {
        return $query->where('ativo', true);
    }
}
