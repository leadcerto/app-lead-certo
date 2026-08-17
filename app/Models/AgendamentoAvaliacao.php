<?php

namespace App\Models;

use App\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Carbon\Carbon;

class AgendamentoAvaliacao extends Model
{
    protected $table = 'agendamentos_avaliacao';

    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope());
    }

    protected $fillable = [
        'tenant_id', 'perfil_id', 'template_id', 'avaliador_id',
        'data_agendada', 'status', 'concluido_em',
    ];

    protected function casts(): array
    {
        return [
            'data_agendada' => 'date',
            'concluido_em'  => 'datetime',
        ];
    }

    // ── Constantes de Status ──────────────────────────────────────────────────

    const STATUS_PENDENTE  = 'pendente';
    const STATUS_ENVIADO   = 'enviado';
    const STATUS_CONCLUIDO = 'concluido';

    // ── Relacionamentos ───────────────────────────────────────────────────────

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function perfil(): BelongsTo
    {
        return $this->belongsTo(PerfilGmb::class, 'perfil_id');
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(TemplateAvaliacao::class, 'template_id');
    }

    public function avaliador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'avaliador_id');
    }

    // ── Scopes ────────────────────────────────────────────────────────────────

    /**
     * Agendamentos que ainda não foram concluídos.
     */
    public function scopePendentes($query)
    {
        return $query->whereIn('status', [self::STATUS_PENDENTE, self::STATUS_ENVIADO]);
    }

    /**
     * Agendamentos em atraso: não concluídos e com data no passado.
     */
    public function scopeAtrasados($query)
    {
        return $query->pendentes()
                     ->where('data_agendada', '<', Carbon::today());
    }

    /**
     * Agendamentos da semana que contém a data informada.
     */
    public function scopeDaSemana($query, ?Carbon $data = null)
    {
        $ref = $data ?? Carbon::today();
        $inicio = $ref->copy()->startOfWeek(Carbon::MONDAY);
        $fim    = $ref->copy()->endOfWeek(Carbon::SUNDAY);

        return $query->whereBetween('data_agendada', [
            $inicio->toDateString(),
            $fim->toDateString(),
        ]);
    }

    /**
     * Filtra por avaliador específico.
     */
    public function scopeDoAvaliador($query, int $avaliadorId)
    {
        return $query->where('avaliador_id', $avaliadorId);
    }

    // ── Métodos ───────────────────────────────────────────────────────────────

    /**
     * Marca o agendamento como concluído.
     */
    public function concluir(): bool
    {
        return $this->update([
            'status'       => self::STATUS_CONCLUIDO,
            'concluido_em' => Carbon::now(),
        ]);
    }

    /**
     * Verifica se o agendamento está em atraso.
     */
    public function estaAtrasado(): bool
    {
        return $this->status !== self::STATUS_CONCLUIDO
            && $this->data_agendada->lt(Carbon::today());
    }

    /**
     * Verifica se foi concluído com atraso (concluído após a data agendada).
     */
    public function concluidoComAtraso(): bool
    {
        return $this->status === self::STATUS_CONCLUIDO
            && $this->concluido_em
            && $this->concluido_em->gt($this->data_agendada->endOfDay());
    }
}
