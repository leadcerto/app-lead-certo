<?php

namespace App\Models;

use App\Scopes\TenantScope;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PerfilGmb extends Model
{
    protected $table = 'perfis_gmb';

    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope);
    }

    protected $fillable = [
        'tenant_id', 'nome', 'city', 'state', 'link_gmb', 'google_location_id', 'ativo',
    ];

    protected function casts(): array
    {
        return [
            'ativo' => 'boolean',
        ];
    }

    // ── Relacionamentos ───────────────────────────────────────────────────────

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function agendamentos(): HasMany
    {
        return $this->hasMany(AgendamentoAvaliacao::class, 'perfil_id');
    }

    public function contatos(): HasMany
    {
        return $this->hasMany(ContatoAvaliacao::class, 'perfil_id');
    }

    public function posts(): HasMany
    {
        return $this->hasMany(GmbPost::class, 'perfil_gmb_id');
    }

    // ── Métodos Auxiliares ─────────────────────────────────────────────────────

    /**
     * Retorna o avaliador com menos agendamentos na semana de referência,
     * filtrado por match geográfico (city + state).
     *
     * Utilizado pelo AtribuicaoAvaliadorService para balanceamento de carga.
     */
    public function avaliadorMaisDisponivel(Carbon $semanaReferencia): ?User
    {
        $inicioSemana = $semanaReferencia->copy()->startOfWeek(Carbon::MONDAY);
        $fimSemana = $semanaReferencia->copy()->endOfWeek(Carbon::SUNDAY);

        return User::where('tenant_id', $this->tenant_id)
            ->where('perfil', 'avaliador')
            ->where('ativo', true)
            ->where('city', $this->city)
            ->where('state', $this->state)
            ->withCount(['agendamentosAvaliacao as agendamentos_semana' => function ($q) use ($inicioSemana, $fimSemana) {
                $q->whereBetween('data_agendada', [$inicioSemana->toDateString(), $fimSemana->toDateString()]);
            }])
            ->orderBy('agendamentos_semana', 'asc')
            ->first();
    }

    // ── Scopes ────────────────────────────────────────────────────────────────

    public function scopeAtivos($query)
    {
        return $query->where('ativo', true);
    }
}
