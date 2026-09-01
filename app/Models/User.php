<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    protected $fillable = [
        'tenant_id', 'nome', 'email', 'password', 'perfil', 'ativo',
        'city', 'state', 'whatsapp', 'avatar_url',
        'is_ia', 'provedor_ia', 'gemini_email', 'gemini_api_key', 'gemini_modelo', 'gemini_instrucoes', 'base_conhecimento',
    ];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'ativo'    => 'boolean',
            'is_ia'    => 'boolean',
        ];
    }

    // ── Matriz de permissões por recurso ──────────────────────────────────────

    private const PERMISSOES = [
        'dashboard'         => ['admin', 'dono', 'diretor', 'gerente', 'coordenador', 'gestor', 'vendedor', 'sdr', 'growth_manager', 'revops', 'diretor_marketing'],
        'kanban'            => ['admin', 'dono', 'diretor', 'gerente', 'coordenador', 'gestor', 'vendedor', 'pos_venda', 'diretor_marketing'],
        'contatos'          => ['admin', 'dono', 'diretor', 'gerente', 'coordenador', 'gestor', 'vendedor', 'growth_manager', 'diretor_marketing'],
        'integracoes'       => ['admin', 'dono', 'growth_manager'],
        'configuracoes'     => ['admin', 'dono'],
        'auditor'           => ['admin', 'dono', 'diretor', 'auditor'],
        'personas'          => ['admin', 'dono', 'diretor', 'gerente', 'coordenador', 'gestor', 'vendedor', 'sdr', 'growth_manager', 'diretor_marketing'],
        'equipe'            => ['admin', 'dono', 'diretor', 'gerente', 'coordenador', 'gestor', 'vendedor', 'sdr', 'growth_manager', 'revops', 'pos_venda', 'auditor', 'diretor_marketing'],
        'campanhas'         => ['admin', 'dono', 'diretor', 'growth_manager', 'diretor_marketing'],
        'revops'            => ['admin', 'dono', 'diretor', 'revops'],
        'usuarios'          => ['admin', 'dono'],
        'contatos.editar'   => ['admin', 'dono', 'diretor', 'gerente', 'coordenador', 'gestor'],
        'kanban.encerrar'   => ['admin', 'dono', 'diretor', 'gerente', 'coordenador', 'gestor', 'vendedor'],
        'avaliacoes_gmb'    => ['admin', 'dono', 'diretor', 'diretor_marketing'],
        'avaliador_dash'    => ['admin', 'avaliador'],
        // Os 4 sub-perfis dormentes (ver EQUIPE_MARKETING abaixo) ainda não
        // aparecem em nenhuma linha acima de propósito — cada um ganha
        // permissão própria e mais estreita só quando for de fato ativado.
    ];

    /**
     * Perfis da "equipe de marketing compartilhada" (ver TAREFAS.md,
     * "Equipe Lead Certo com acesso universal a todas as empresas",
     * 2026-08-20) — atendem TODAS as empresas da Lead Certo, não uma só.
     * Só 'diretor_marketing' (Nathanel) está em uso hoje; os 4 seguintes
     * são perfis dormentes, criados de antemão pra ativação sob demanda
     * (ver organograma 2.1.1-2.1.4 em TAREFAS.md) — nenhum usuário deve
     * ter esses perfis ainda.
     */
    private const EQUIPE_MARKETING = [
        'diretor_marketing', 'gestor_trafego', 'gestor_criacao',
        'gestor_copywriting', 'gestor_seo',
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

    public function isEquipeMarketing(): bool
    {
        return in_array($this->perfil, self::EQUIPE_MARKETING, true);
    }

    /**
     * Quem pode trocar de tenant via ?tenant_id= (ver EnsureTenant) — hoje
     * admin (acesso total) e a equipe de marketing compartilhada (acesso
     * universal, mas restrito às ferramentas do próprio perfil via
     * podeAcessar()). Tenant "casa" desses usuários (ex.: diretor_marketing
     * = tenant Lead Certo) só é metadado de identidade, não limita o alcance.
     */
    public function podeTrocarTenant(): bool
    {
        return $this->isAdmin() || $this->isEquipeMarketing();
    }

    /**
     * Tenant que o usuário está de fato operando AGORA — respeita a troca
     * feita via ?tenant_id= (ver EnsureTenant/podeTrocarTenant), cai pro
     * tenant "casa" do próprio usuário quando não há troca em sessão.
     *
     * Achado real 2026-08-20: ~30 controllers do painel liam
     * $request->user()->tenant_id direto, ignorando essa troca por completo
     * — inclusive os 4 controllers de GMB que a Nathanel usa. Este helper
     * existe pra ser o único ponto de leitura correto; controllers que
     * ainda leem tenant_id direto do usuário devem migrar pra ele.
     */
    public function tenantAtual(): ?int
    {
        return session('tenant_id') ?? $this->tenant_id;
    }

    public function isDono(): bool
    {
        return in_array($this->perfil, ['admin', 'dono'], true);
    }

    public function isGerente(): bool
    {
        return in_array($this->perfil, ['admin', 'dono', 'diretor', 'gerente', 'gestor'], true);
    }

    public function isAvaliador(): bool
    {
        return $this->perfil === 'avaliador';
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
            'avaliador'      => 'Avaliador',
            'diretor_marketing'   => 'Diretora de Marketing',
            'gestor_trafego'      => 'Gestor de Tráfego',
            'gestor_criacao'      => 'Gestor de Criação',
            'gestor_copywriting'  => 'Gestor de Copywriting',
            'gestor_seo'          => 'Gestor de SEO',
            default          => ucfirst($this->perfil),
        };
    }

    // ── Relacionamentos ───────────────────────────────────────────────────────

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function cargos(): BelongsToMany
    {
        return $this->belongsToMany(Cargo::class, 'agente_cargo');
    }

    public function servicosExecutados(): HasMany
    {
        return $this->hasMany(ServicoExecutado::class);
    }

    public function acessos(): HasMany
    {
        return $this->hasMany(AcessoAgente::class);
    }

    public function agendamentosAvaliacao(): HasMany
    {
        return $this->hasMany(AgendamentoAvaliacao::class, 'avaliador_id');
    }
}
