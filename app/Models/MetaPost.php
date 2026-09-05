<?php

namespace App\Models;

use App\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MetaPost extends Model
{
    protected $table = 'meta_posts';

    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope());
    }

    protected $fillable = [
        'tenant_id',
        'user_id',
        'canal_alvo',
        'meta_pagina_id',
        'meta_conta_instagram_id',
        'texto',
        'imagem_url',
        'cta_tipo',
        'cta_url',
        'modo_gatilho',
        'palavras_chave',
        'resposta_publica_comentario',
        'mensagem_direct',
        'data_agendada',
        'publicado_em',
        'status',
        'facebook_post_id',
        'instagram_media_id',
        'log_erro',
        'tentativas',
    ];

    protected function casts(): array
    {
        return [
            'palavras_chave' => 'array',
            'data_agendada'  => 'datetime',
            'publicado_em'   => 'datetime',
            'tentativas'     => 'integer',
        ];
    }

    // ── Relacionamentos ──

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function pagina(): BelongsTo
    {
        return $this->belongsTo(MetaPagina::class, 'meta_pagina_id');
    }

    public function contaInstagram(): BelongsTo
    {
        return $this->belongsTo(MetaContaInstagram::class, 'meta_conta_instagram_id');
    }

    public function autor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    // ── Scopes ──

    public function scopeProntosParaPublicar(Builder $query): Builder
    {
        return $query->where('status', 'agendado')
                     ->where('data_agendada', '<=', now());
    }

    public function scopeAgendados(Builder $query): Builder
    {
        return $query->where('status', 'agendado');
    }

    public function scopePublicados(Builder $query): Builder
    {
        return $query->where('status', 'publicado');
    }

    public function scopeFalhas(Builder $query): Builder
    {
        return $query->where('status', 'falha');
    }

    // ── Helpers ──

    public function podeCancelar(): bool
    {
        return $this->status === 'agendado';
    }

    public function statusBadge(): array
    {
        return match ($this->status) {
            'publicado'  => ['label' => 'Publicado', 'class' => 'bg-green-100 text-green-800 border-green-200'],
            'agendado'   => ['label' => 'Agendado', 'class' => 'bg-amber-100 text-amber-800 border-amber-200'],
            'publicando' => ['label' => 'Publicando...', 'class' => 'bg-blue-100 text-blue-800 border-blue-200'],
            'falha'      => ['label' => 'Falha no Envio', 'class' => 'bg-red-100 text-red-800 border-red-200'],
            'cancelado'  => ['label' => 'Cancelado', 'class' => 'bg-gray-100 text-gray-800 border-gray-200'],
            default      => ['label' => ucfirst($this->status), 'class' => 'bg-gray-100 text-gray-700 border-gray-200'],
        };
    }
}
