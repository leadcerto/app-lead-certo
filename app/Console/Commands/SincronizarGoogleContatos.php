<?php

namespace App\Console\Commands;

use App\Models\GoogleToken;
use App\Services\ContatoSyncService;
use Illuminate\Console\Command;

class SincronizarGoogleContatos extends Command
{
    protected $signature   = 'contatos:sincronizar-google {--tenant= : Sincronizar apenas este tenant ID}';
    protected $description = 'Sincroniza contatos do Google para todos os tenants conectados (delta a cada 6h)';

    public function handle(ContatoSyncService $sync): int
    {
        $query = GoogleToken::query();

        if ($tenantId = $this->option('tenant')) {
            $query->where('tenant_id', $tenantId);
        }

        $tokens = $query->get();

        if ($tokens->isEmpty()) {
            $this->warn('Nenhum tenant com Google conectado.');
            return Command::SUCCESS;
        }

        foreach ($tokens as $token) {
            $this->info("Tenant #{$token->tenant_id} ({$token->google_email})");
            $tipo = $token->sync_token ? '[delta]' : '[full]';
            $this->line("  → Modo: {$tipo}");

            try {
                $resultado = $sync->sincronizar($token, $token->tenant_id);

                $this->info("  ✓ Importados: {$resultado['importados']}");
                $this->info("  ✓ Atualizados: {$resultado['atualizados']}");

                if ($resultado['conflitos'] > 0) {
                    $this->warn("  ⚠ Conflitos (auditoria): {$resultado['conflitos']}");
                }
                if ($resultado['ignorados'] > 0) {
                    $this->line("  - Ignorados (sem telefone): {$resultado['ignorados']}");
                }
                foreach ($resultado['erros'] as $erro) {
                    $this->error("  ✗ {$erro}");
                }
            } catch (\Exception $e) {
                $this->error("  ✗ Falha no tenant #{$token->tenant_id}: " . $e->getMessage());
            }
        }

        return Command::SUCCESS;
    }
}
