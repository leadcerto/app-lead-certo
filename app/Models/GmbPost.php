<?php

namespace App\Models;

use App\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GmbPost extends Model
{
    protected $table = 'gmb_posts';

    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope);
    }

    protected $fillable = [
        'tenant_id',
        'perfil_gmb_id',
        'user_id',
        'tipo',
        'titulo',
        'texto',
        'imagem_url',
        'cta_tipo',
        'cta_url',
        'data_inicio_evento',
        'data_fim_evento',
        'codigo_cupom',
        'link_resgate',
        'termos_condicoes',
        'data_agendada',
        'publicado_em',
        'status',
        'google_post_id',
        'google_post_url',
        'log_erro',
        'tentativas',
        'gerado_por_ia',
        'prompt_ia_utilizado',
    ];

    protected function casts(): array
    {
        return [
            'data_inicio_evento' => 'datetime',
            'data_fim_evento'    => 'datetime',
            'data_agendada'      => 'datetime',
            'publicado_em'       => 'datetime',
            'gerado_por_ia'      => 'boolean',
            'tentativas'         => 'integer',
        ];
    }

    // ── Relacionamentos ──

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function perfil(): BelongsTo
    {
        return $this->belongsTo(PerfilGmb::class, 'perfil_gmb_id');
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

    public function scopePublicados(Builder $query): Builder
    {
        return $query->where('status', 'publicado');
    }

    public function scopeAgendados(Builder $query): Builder
    {
        return $query->where('status', 'agendado');
    }

    public function scopeFalhas(Builder $query): Builder
    {
        return $query->where('status', 'falha');
    }

    // ── Helpers ──

    public function podeEditar(): bool
    {
        return in_array($this->status, ['rascunho', 'agendado', 'falha']);
    }

    public function podeCancelar(): bool
    {
        return in_array($this->status, ['agendado', 'rascunho']);
    }

    public function statusBadge(): array
    {
        return match ($this->status) {
            'publicado'  => ['label' => 'Publicado', 'class' => 'bg-green-100 text-green-800 border-green-200'],
            'agendado'   => ['label' => 'Agendado', 'class' => 'bg-amber-100 text-amber-800 border-amber-200'],
            'publicando' => ['label' => 'Publicando...', 'class' => 'bg-blue-100 text-blue-800 border-blue-200'],
            'falha'      => ['label' => 'Falha no Envio', 'class' => 'bg-red-100 text-red-800 border-red-200'],
            'cancelado'  => ['label' => 'Cancelado', 'class' => 'bg-gray-100 text-gray-800 border-gray-200'],
            default      => ['label' => 'Rascunho', 'class' => 'bg-gray-100 text-gray-700 border-gray-200'],
        };
    }
}
