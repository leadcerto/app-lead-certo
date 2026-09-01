<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class GmbPostCategoria extends Model
{
    protected $table = 'gmb_post_categorias';

    protected $fillable = [
        'tenant_id',
        'nome',
        'slug',
        'palavras_chave',
        'ativo',
    ];

    protected $casts = [
        'palavras_chave' => 'array',
        'ativo'          => 'boolean',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function templates(): HasMany
    {
        return $this->hasMany(GmbPostTemplate::class, 'categoria', 'slug');
    }
}
