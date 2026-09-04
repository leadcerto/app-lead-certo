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

            // 2. Priorização: Leads Novos/Ativos primeiro, seguido pelo Backlog antigo
            $vinculosNovosQuery = VinculoContatoTenant::where('tenant_id', $token->tenant_id)
                ->whereNotNull('google_resource_name')
                ->where(function ($q) {
                    $q->whereNull('google_sincronizado_em')
                      ->orWhereExists(function ($sub) {
                          $sub->select(\Illuminate\Support\Facades\DB::raw(1))
                              ->from('tickets_atendimento')
                              ->whereColumn('tickets_atendimento.contato_id', 'vinculos_contato_tenant.contato_id')
                              ->whereIn('tickets_atendimento.coluna_kanban', ['novo', 'em_atendimento', 'aguardando_retorno'])
                              ->whereNull('tickets_atendimento.deleted_at');
                      })
                      ->orWhere('vinculos_contato_tenant.created_at', '>=', now()->subDays(2));
                })
                ->orderByRaw('google_sincronizado_em IS NULL DESC, id DESC')
                ->with('contato');

            $vinculosNovos = $limite > 0 ? $vinculosNovosQuery->take($limite)->get() : $vinculosNovosQuery->get();
            $vinculos = collect($vinculosNovos);

            $this->info("--> [Prioridade 1] Leads novos / ativos identificados: {$vinculos->count()}");

            // Prioridade 2: Backlog de contatos antigos (preenche o restante do lote se houver margem)
            if ($limite === 0 || $vinculos->count() < $limite) {
                $restante = $limite > 0 ? ($limite - $vinculos->count()) : 0;
                $idsJaSelecionados = $vinculos->pluck('id')->toArray();

                $vinculosAntigosQuery = VinculoContatoTenant::where('tenant_id', $token->tenant_id)
                    ->whereNotNull('google_resource_name')
                    ->when(! empty($idsJaSelecionados), fn($q) => $q->whereNotIn('id', $idsJaSelecionados))
                    ->orderByRaw('google_sincronizado_em ASC, id ASC')
                    ->with('contato');

                $vinculosAntigos = $limite > 0 ? $vinculosAntigosQuery->take($restante)->get() : $vinculosAntigosQuery->get();
                $this->info("--> [Prioridade 2] Contatos do backlog antigo adicionados: {$vinculosAntigos->count()}");
                $vinculos = $vinculos->concat($vinculosAntigos);
            }

            $total = $vinculos->count();
            $this->info("--> Total consolidado para este ciclo: {$total}");

            $bar = $this->output->createProgressBar($total);
            $bar->start();

            $sucessos = 0;
            $erros    = 0;

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

                    // 3. Registrar carimbo de sincronização do Atlas
                    $vinculo->update(['google_sincronizado_em' => now()]);

                    $sucessos++;
                } catch (\Exception $e) {
                    $erros++;
                    Log::error("Falha ao sincronizar contato #{$contato->id} no Google", ['erro' => $e->getMessage()]);
                }

                $bar->advance();
                // Pequeno respiro para respeitar o rate limit da Google People API
                usleep(50000); // 50ms
            }

            $bar->finish();
            $this->newLine(2);
            $this->info("✅ Tenant {$token->tenant_id} concluído: {$sucessos} sincronizados com sucesso, {$erros} falhas.");
        }

        return 0;
    }
}
