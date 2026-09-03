<?php

namespace App\Console\Commands;

use App\Models\Contato;
use App\Models\GoogleToken;
use App\Models\VinculoContatoTenant;
use App\Services\GoogleEtiquetaService;
use App\Services\GoogleService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SincronizarGoogleEtiquetasCommand extends Command
{
    protected $signature = 'contatos:sincronizar-google-etiquetas
                            {--tenant= : ID do Tenant específico}
                            {--dry-run : Apenas simula as alterações sem gravar no Google}
                            {--limite=0 : Limite máximo de contatos a processar (0 = todos)}
                            {--lote=0 : Alias para limite}';

    protected $description = 'Sincroniza estrutura de nomes (Nome, ID no Nome do Meio, Sobrenome) e move contatos de 🚩 NOVOS LEADS para 🚩 LEAD CERTO no Google Contatos';

    public function handle(GoogleService $google, GoogleEtiquetaService $etiquetaService): int
    {
        $tenantId = $this->option('tenant');
        $dryRun   = (bool) $this->option('dry-run');
        $limite   = (int) ($this->option('lote') ?: $this->option('limite'));

        $tokensQuery = GoogleToken::query();
        if ($tenantId) {
            $tokensQuery->where('tenant_id', $tenantId);
        }
        $tokens = $tokensQuery->get();

        if ($tokens->isEmpty()) {
            $this->error('Nenhum GoogleToken ativo encontrado.');
            return 1;
        }

        foreach ($tokens as $token) {
            $this->info("==================================================");
            $this->info("Processando Tenant ID: {$token->tenant_id} ({$token->email})");
            $this->info("==================================================");

            $tokenValido = $google->tokenValido($token);
            if (! $tokenValido) {
                $this->error("Token do tenant {$token->tenant_id} expirado ou inválido.");
                continue;
            }

            // 1. Mapear e sincronizar grupos
            $this->info("--> Sincronizando grupos e marcadores padrão no Google...");
            $grupos = $etiquetaService->sincronizarGrupos($tokenValido);
            foreach ($grupos as $slug => $res) {
                $this->line("   [Etiqueta: {$slug}] => {$res}");
            }

            // 2. Buscar vínculos com Google Resource Name
            $vinculosQuery = VinculoContatoTenant::where('tenant_id', $token->tenant_id)
                ->whereNotNull('google_resource_name')
                ->with('contato');

            if ($limite > 0) {
                $vinculosQuery->take($limite);
            }

            $total = $vinculosQuery->count();
            $this->info("--> Total de contatos vinculados para sincronizar: {$total}");

            $bar = $this->output->createProgressBar($total);
            $bar->start();

            $sucessos = 0;
            $erros    = 0;

            $vinculosQuery->chunk(50, function ($vinculos) use ($google, $etiquetaService, $tokenValido, $dryRun, &$sucessos, &$erros, $bar) {
                foreach ($vinculos as $vinculo) {
                    $contato = $vinculo->contato;
                    if (! $contato || ! $vinculo->google_resource_name) {
                        $bar->advance();
                        continue;
                    }

                    if ($dryRun) {
                        $nameEntry = $google->formatarNomeParaGoogle($contato);
                        $this->line(" [DRY-RUN] Contato #{$contato->id}: {$nameEntry['givenName']} | Middle: {$nameEntry['middleName']} | Family: " . ($nameEntry['familyName'] ?? ''));
                        $bar->advance();
                        continue;
                    }

                    try {
                        // 1. Atualizar estrutura de nomes no Google
                        $nameEntry = $google->formatarNomeParaGoogle($contato);
                        $google->atualizarNomeContato(
                            $tokenValido,
                            $vinculo->google_resource_name,
                            $vinculo->google_etag ?? '*',
                            $nameEntry['givenName'],
                            $nameEntry['familyName'] ?? '',
                            $nameEntry['middleName'] ?? (string) $contato->id
                        );

                        // 2. Atualizar marcadores (adiciona em LEAD CERTO, remove de NOVOS LEADS)
                        $etiquetaService->atualizarMembrosContato($tokenValido, $contato, $vinculo);

                        $sucessos++;
                    } catch (\Exception $e) {
                        $erros++;
                        Log::error("Falha ao sincronizar contato #{$contato->id} no Google", ['erro' => $e->getMessage()]);
                    }

                    $bar->advance();
                    // Pequeno respiro para respeitar o rate limit da Google People API
                    usleep(50000); // 50ms
                }
            });

            $bar->finish();
            $this->newLine(2);
            $this->info("✅ Tenant {$token->tenant_id} concluído: {$sucessos} sincronizados com sucesso, {$erros} falhas.");
        }

        return 0;
    }
}
