<?php

namespace App\Models;

use App\Enums\PapelColunaKanban;
use App\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

class KanbanColuna extends Model
{
    protected $table = 'kanban_colunas';

    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope());

        static::saved(fn (self $coluna) => static::limparCache($coluna->tenant_id));
        static::deleted(fn (self $coluna) => static::limparCache($coluna->tenant_id));
    }

    protected $fillable = [
        'tenant_id',
        'kanban_id',
        'chave',
        'label',
        'emoji',
        'papel',
        'ordem',
    ];

    protected function casts(): array
    {
        return [
            'papel' => PapelColunaKanban::class,
            'ordem' => 'integer',
        ];
    }

    public function kanban(): BelongsTo
    {
        return $this->belongsTo(Kanban::class);
    }

    public static function limparCache(int $tenantId): void
    {
        Cache::forget("kanban_colunas:{$tenantId}");
    }

    /** @return Collection<int, self> */
    protected static function doTenant(int $tenantId): Collection
    {
        return Cache::remember("kanban_colunas:{$tenantId}", 3600, function () use ($tenantId) {
            return static::withoutGlobalScope(TenantScope::class)
                ->where('tenant_id', $tenantId)
                ->orderBy('ordem')
                ->get();
        });
    }

    public static function chavesDoTenant(int $tenantId): array
    {
        return static::doTenant($tenantId)->pluck('chave')->all();
    }

    public static function papelDe(int $tenantId, string $chave): ?PapelColunaKanban
    {
        return static::doTenant($tenantId)->firstWhere('chave', $chave)?->papel;
    }

    public static function chaveDeEntrada(int $tenantId): string
    {
        $coluna = static::doTenant($tenantId)->first(fn (self $c) => $c->papel === PapelColunaKanban::Entrada);

        if (! $coluna) {
            throw new \RuntimeException("Tenant {$tenantId} não tem nenhuma coluna de papel Entrada configurada.");
        }

        return $coluna->chave;
    }

    public static function chavesComPapel(int $tenantId, PapelColunaKanban $papel): array
    {
        return static::doTenant($tenantId)
            ->filter(fn (self $c) => $c->papel === $papel)
            ->pluck('chave')
            ->values()
            ->all();
    }

    public static function primeiraChaveComPapel(int $tenantId, PapelColunaKanban $papel): ?string
    {
        return static::doTenant($tenantId)->first(fn (self $c) => $c->papel === $papel)?->chave;
    }

    public static function proximaChave(int $tenantId, string $chaveAtual): ?string
    {
        $colunas = static::doTenant($tenantId)->values();
        $indice = $colunas->search(fn (self $c) => $c->chave === $chaveAtual);

        if ($indice === false) {
            return null;
        }

        return $colunas->get($indice + 1)?->chave;
    }
}
