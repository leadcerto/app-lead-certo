<?php

namespace App\Models;

use App\Enums\PapelColunaKanban;
use App\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Collection;

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
        // Sem-efeito desde o incidente de 2026-07-30: ver nota em doTenant().
    }

    /**
     * Incidente 2026-07-30: cachear a Collection de models via Cache::remember() (Redis)
     * corrompia a classe na releitura — Cache::get() voltava __PHP_Incomplete_Class em vez
     * de KanbanColuna, com TODOS os atributos intactos (não era perda de dado, só a
     * identidade da classe). Reproduzido isolado com Cache::put()/get() direto, fora do
     * fluxo da aplicação. Causa exata não investigada a fundo (suspeita: serialização de
     * Collection+Enum via driver Redis nesta versão do stack) — a correção aplicada foi
     * parar de cachear instâncias de model. Tabela tem ~8 linhas por tenant; custo da
     * consulta direta é irrisório perto do risco de corromper o board de Kanban inteiro.
     *
     * @return Collection<int, self>
     */
    protected static function doTenant(int $tenantId): Collection
    {
        return static::withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $tenantId)
            ->orderBy('ordem')
            ->get();
    }

    public static function chavesDoTenant(int $tenantId): array
    {
        return static::doTenant($tenantId)->pluck('chave')->all();
    }

    public static function papelDe(int $tenantId, string $chave): ?PapelColunaKanban
    {
        return static::doTenant($tenantId)->firstWhere('chave', $chave)?->papel;
    }

    public static function ordemDe(int $tenantId, string $chave): ?int
    {
        return static::doTenant($tenantId)->firstWhere('chave', $chave)?->ordem;
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

    public static function descricaoParaIa(int $tenantId, string $chave): string
    {
        $coluna = static::doTenant($tenantId)->firstWhere('chave', $chave);

        return $coluna ? "{$coluna->label} — {$coluna->papel->descricao()}" : $chave;
    }
}
