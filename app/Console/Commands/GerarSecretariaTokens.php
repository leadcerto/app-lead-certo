<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class GerarSecretariaTokens extends Command
{
    protected $signature   = 'secretaria:gerar-tokens';
    protected $description = 'Gera secretaria_token para todos os tenants que ainda não têm';

    public function handle(): void
    {
        $sem = Tenant::whereNull('secretaria_token')->get();

        if ($sem->isEmpty()) {
            $this->info('Todos os tenants já têm secretaria_token.');
            return;
        }

        foreach ($sem as $tenant) {
            $tenant->update(['secretaria_token' => Str::random(48)]);
            $this->line("  ✓ Tenant #{$tenant->id} ({$tenant->nome}) — token gerado");
        }

        $this->info("Pronto: {$sem->count()} token(s) gerado(s).");
    }
}
