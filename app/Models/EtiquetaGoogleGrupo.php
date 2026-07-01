<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EtiquetaGoogleGrupo extends Model
{
    protected $table = 'etiqueta_google_grupos';

    protected $fillable = ['etiqueta_id', 'tenant_id', 'google_group_resource_name'];

    public function etiqueta(): BelongsTo
    {
        return $this->belongsTo(Etiqueta::class);
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
