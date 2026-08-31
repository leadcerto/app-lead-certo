<?php

namespace App\Models;

use App\Jobs\EnriquecerContatoNovoViaGoogleJob;
use App\Jobs\MarcarNovoLeadEtiquetaJob;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class VinculoContatoTenant extends Model
{
    protected $table = 'vinculos_contato_tenant';

    public $timestamps = false;

    protected $casts = [
        'created_at'                 => 'datetime',
        'bloqueado_em'               => 'datetime',
        'google_valores_enviados'    => 'array',
        'campos_editados_humano'     => 'array',
        'campos_pendentes_auditoria' => 'array',
    ];

    protected $fillable = [
        'contato_id',
        'tenant_id',
        'google_resource_name',
        'google_etag',
        'bloqueado_em',
        'google_valores_enviados',
        'campos_editados_humano',
        'campos_pendentes_auditoria',
    ];

    /**
     * Busca em tempo real no Google assim que um lead novo ganha vínculo —
     * pra não esperar até 15 min do próximo cron pra mostrar o nome real.
     * Ver design seção 10 / EnriquecerContatoNovoViaGoogleJob.
     */
    protected static function booted(): void
    {
        static::created(function (VinculoContatoTenant $vinculo) {
            EnriquecerContatoNovoViaGoogleJob::dispatch($vinculo->id);
            MarcarNovoLeadEtiquetaJob::dispatch($vinculo->id)->delay(now()->addMinutes(2));
        });
    }

    public function contato(): BelongsTo
    {
        return $this->belongsTo(Contato::class, 'contato_id');
    }

    public function etiquetas(): BelongsToMany
    {
        return $this->belongsToMany(Etiqueta::class, 'vinculo_etiqueta', 'vinculo_id', 'etiqueta_id')
            ->withPivot('created_at');
    }
}
