<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, Notifiable;

    protected $fillable = [
        'tenant_id', 'nome', 'email', 'password', 'perfil', 'ativo',
    ];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'ativo'    => 'boolean',
        ];
    }

    // ── Matriz de permissões por recurso ──────────────────────────────────────

    private const PERMISSOES = [
        'dashboard'         => ['admin', 'dono', 'diretor', 'gerente', 'gestor', 'vendedor', 'growth_manager', 'revops'],
        'kanban'            => ['admin', 'dono', 'diretor', 'gerente', 'gestor', 'vendedor', 'pos_venda'],
        'contatos'          => ['admin', 'dono', 'diretor', 'gerente', 'gestor', 'vendedor', 'growth_manager'],
        'integracoes'       => ['admin', 'dono', 'growth_manager'],
        'configuracoes'     => ['admin', 'dono'],
        'auditor'           => ['admin', 'dono', 'diretor', 'auditor'],
        'personas'          => ['admin', 'dono', 'diretor', 'growth_manager'],
        'campanhas'         => ['admin', 'dono', 'diretor', 'growth_manager'],
        'revops'            => ['admin', 'dono', 'diretor', 'revops'],
        'usuarios'          => ['admin', 'dono'],
        'contatos.editar'   => ['admin', 'dono', 'diretor', 'gerente', 'gestor'],
        'kanban.encerrar'   => ['admin', 'dono', 'diretor', 'gerente', 'gestor', 'vendedor'],
    ];

    // ── Helpers de permissão ──────────────────────────────────────────────────

    public function podeAcessar(string $recurso): bool
    {
        return in_array($this->perfil, self::PERMISSOES[$recurso] ?? [], true);
    }

    public function isAdmin(): bool
    {
        return $this->perfil === 'admin';
    }

    public function isDono(): bool
    {
        return in_array($this->perfil, ['admin', 'dono'], true);
    }

    public function isGerente(): bool
    {
        return in_array($this->perfil, ['admin', 'dono', 'diretor', 'gerente', 'gestor'], true);
    }

    public function perfilLabel(): string
    {
        return match ($this->perfil) {
            'admin'          => 'Administrador',
            'dono'           => 'Dono',
            'diretor'        => 'Diretor',
            'gerente'        => 'Gerente',
            'gestor'         => 'Gestor',
            'vendedor'       => 'Vendedor',
            'auditor'        => 'Auditor',
            'growth_manager' => 'Growth Manager',
            'revops'         => 'RevOps',
            'pos_venda'      => 'Pós-Venda',
            default          => ucfirst($this->perfil),
        };
    }

    // ── Relacionamentos ───────────────────────────────────────────────────────

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
