<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Models\User;
use App\Services\TenantSetupService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;

class TenantCriarCommand extends Command
{
    protected $signature = 'tenant:criar
                            {--nome=      : Nome da empresa franqueada}
                            {--email=     : E-mail do dono}
                            {--telefone=  : Telefone da empresa}
                            {--senha=     : Senha inicial do dono (padrão: leadcerto123)}';

    protected $description = 'Cria um novo franqueado com a configuração padrão Lead Certo (Kanban, IA, Persona)';

    public function handle(TenantSetupService $setup): int
    {
        $nome     = $this->option('nome')     ?: $this->ask('Nome da empresa franqueada');
        $email    = $this->option('email')    ?: $this->ask('E-mail do dono');
        $telefone = $this->option('telefone') ?: $this->ask('Telefone da empresa (apenas números)');
        $senha    = $this->option('senha')    ?: 'leadcerto123';

        if (Tenant::where('email', $email)->exists()) {
            $this->error("Já existe um franqueado com o e-mail {$email}.");
            return 1;
        }

        // ── 1. Criar o tenant ────────────────────────────────────────────────
        $tenant = Tenant::create([
            'nome'     => $nome,
            'email'    => $email,
            'telefone' => $telefone,
            'status'   => 'ativo',
            'nicho'    => 'frete',
        ]);

        // ── 2. Criar usuário dono ────────────────────────────────────────────
        $user = User::create([
            'tenant_id' => $tenant->id,
            'nome'      => $nome,
            'email'     => $email,
            'password'  => Hash::make($senha),
            'perfil'    => 'dono',
            'ativo'     => true,
        ]);

        // ── 3. Aplicar configuração padrão Lead Certo ────────────────────────
        $setup->configurar($tenant);

        // ── Resumo ───────────────────────────────────────────────────────────
        $this->newLine();
        $this->info("✅ Franqueado criado com sucesso!");
        $this->table(
            ['Campo', 'Valor'],
            [
                ['Tenant ID',  $tenant->id],
                ['Nome',       $tenant->nome],
                ['E-mail',     $user->email],
                ['Senha',      $senha],
                ['Perfil',     'dono'],
                ['Status',     'ativo'],
                ['Config IA',  'Kanban + Persona padrão aplicados'],
            ]
        );
        $this->newLine();
        $this->line("Próximo passo: configure o WhatsApp em /integracoes");

        return 0;
    }
}
