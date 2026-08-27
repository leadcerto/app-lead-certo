<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class VinculoContatoTenant extends Model
{
    protected $table = 'vinculos_contato_tenant';

    public $timestamps = false;

    protected $casts = [
        'created_at'                 => 'datetime',
        'auditoria_pendente'         => 'boolean',
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
        'google_given_name',
        'nome_sugerido',
        'auditoria_pendente',
        'bloqueado_em',
        'google_valores_enviados',
        'campos_editados_humano',
        'campos_pendentes_auditoria',
    ];

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
