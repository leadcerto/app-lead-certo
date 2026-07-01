<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VinculoContatoTenant extends Model
{
    protected $table = 'vinculos_contato_tenant';

    public $timestamps = false;

    protected $casts = [
        'created_at'        => 'datetime',
        'auditoria_pendente' => 'boolean',
    ];

    protected $fillable = [
        'contato_id',
        'tenant_id',
        'google_resource_name',
        'google_etag',
        'google_given_name',
        'nome_sugerido',
        'auditoria_pendente',
    ];

    public function contato(): BelongsTo
    {
        return $this->belongsTo(Contato::class, 'contato_id');
    }
}
