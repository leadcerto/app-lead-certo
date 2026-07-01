<?php

namespace App\Console\Commands;

use App\Models\GoogleToken;
use App\Models\VinculoContatoTenant;
use App\Services\GoogleService;
use Illuminate\Console\Command;

class PushContatosParaGoogle extends Command
{
    protected $signature   = 'contatos:push-google {--tenant= : ID do tenant} {--batch=50 : Contatos por lote}';
    protected $description = 'Envia para o Google Contacts todos os contatos do CRM que ainda não estão lá';

    public function handle(GoogleService $google): int
    {
        $tenantId = $this->option('tenant');
        $batch    = (int) $this->option('batch');

        $tokenQuery = GoogleToken::query();
        if ($tenantId) {
            $tokenQuery->where('tenant_id', $tenantId);
        }

        $tokens = $tokenQuery->get();

        if ($tokens->isEmpty()) {
            $this->warn('Nenhum tenant com Google conectado.');
            return Command::SUCCESS;
        }

        foreach ($tokens as $token) {
            $this->info("Tenant #{$token->tenant_id}");

            // Busca vínculos SEM google_resource_name (não estão no Google ainda)
            $vinculos = VinculoContatoTenant::with('contato')
                ->where('tenant_id', $token->tenant_id)
                ->whereNull('google_resource_name')
                ->get();

            $total     = $vinculos->count();
            $enviados  = 0;
            $falhas    = 0;

            $this->line("  {$total} contatos para enviar ao Google");

            if ($total === 0) {
                $this->info('  Tudo sincronizado.');
                continue;
            }

            $bar = $this->output->createProgressBar($total);
            $bar->start();

            foreach ($vinculos->chunk($batch) as $lote) {
                foreach ($lote as $vinculo) {
                    if (! $vinculo->contato) {
                        $bar->advance();
                        continue;
                    }

                    $resourceName = $google->criarContato($token, $vinculo->contato);

                    if ($resourceName) {
                        $vinculo->update(['google_resource_name' => $resourceName]);
                        $enviados++;
                    } else {
                        $falhas++;
                    }

                    $bar->advance();

                    // 50ms entre requests para não estourar quota da API
                    usleep(50_000);
                }
            }

            $bar->finish();
            $this->newLine();
            $this->info("  ✓ Enviados: {$enviados}");

            if ($falhas > 0) {
                $this->warn("  ✗ Falhas: {$falhas}");
            }
        }

        return Command::SUCCESS;
    }
}
