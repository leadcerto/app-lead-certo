<?php

namespace App\Models;

use App\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CategoriaTemplate extends Model
{
    protected $table = 'categorias_template';

    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope());
    }

    protected $fillable = [
        'tenant_id', 'nome', 'palavras_chave',
    ];

    protected function casts(): array
    {
        return [
            'palavras_chave' => 'array',
        ];
    }

    // ── Relacionamentos ───────────────────────────────────────────────────────

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function templates(): HasMany
    {
        return $this->hasMany(TemplateAvaliacao::class, 'categoria_id');
    }
}
