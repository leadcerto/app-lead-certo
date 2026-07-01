<?php

namespace App\Console\Commands;

use App\Models\Etiqueta;
use App\Models\EtiquetaGoogleGrupo;
use App\Models\GoogleToken;
use App\Services\GoogleService;
use Illuminate\Console\Command;

class SetupEtiquetasGoogle extends Command
{
    protected $signature   = 'etiquetas:setup-google {--tenant= : ID do tenant (padrão: todos)}';
    protected $description = 'Cria os grupos de etiquetas do sistema no Google Contacts de cada tenant';

    public function handle(GoogleService $google): int
    {
        $etiquetasSistema = Etiqueta::whereNull('tenant_id')->where('ativo', true)->get();

        if ($etiquetasSistema->isEmpty()) {
            $this->warn('Nenhuma etiqueta do sistema encontrada. Execute: php artisan db:seed --class=EtiquetaSeeder');
            return Command::FAILURE;
        }

        $tokenQuery = GoogleToken::query();
        if ($tenantId = $this->option('tenant')) {
            $tokenQuery->where('tenant_id', $tenantId);
        }

        $tokens = $tokenQuery->get();

        if ($tokens->isEmpty()) {
            $this->warn('Nenhum tenant com Google conectado.');
            return Command::SUCCESS;
        }

        foreach ($tokens as $token) {
            $this->info("Tenant #{$token->tenant_id}");

            foreach ($etiquetasSistema as $etiqueta) {
                $jaExiste = EtiquetaGoogleGrupo::where('etiqueta_id', $etiqueta->id)
                    ->where('tenant_id', $token->tenant_id)
                    ->exists();

                if ($jaExiste) {
                    $this->line("  - [{$etiqueta->slug}] já existe no Google");
                    continue;
                }

                $resourceName = $google->criarGrupoContato($token, $etiqueta->nome);

                if ($resourceName) {
                    EtiquetaGoogleGrupo::create([
                        'etiqueta_id'               => $etiqueta->id,
                        'tenant_id'                 => $token->tenant_id,
                        'google_group_resource_name' => $resourceName,
                    ]);
                    $this->info("  ✓ [{$etiqueta->slug}] criado: {$resourceName}");
                } else {
                    $this->warn("  ✗ [{$etiqueta->slug}] falhou ao criar no Google");
                }

                usleep(200_000); // 200ms entre criações de grupo
            }
        }

        return Command::SUCCESS;
    }
}
