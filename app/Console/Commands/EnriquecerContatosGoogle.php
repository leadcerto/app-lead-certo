<?php

namespace App\Console\Commands;

use App\Models\GoogleToken;
use App\Models\VinculoContatoTenant;
use App\Services\GoogleService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class EnriquecerContatosGoogle extends Command
{
    protected $signature   = 'google:enriquecer {--tenant= : ID do tenant} {--batch=50 : Contatos por lote} {--limit= : Limita total de contatos processados (para teste)}';
    protected $description = 'Atualiza contatos existentes no Google com middleName=ID do banco e adiciona ao marcador "Lead Certo"';

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

        $limit = $this->option('limit') ? (int) $this->option('limit') : null;

        foreach ($tokens as $token) {
            $this->info("Tenant #{$token->tenant_id}");

            // Garante que o marcador "Lead Certo" existe no Google
            $grupoResourceName = $this->obterOuCriarGrupo($google, $token);
            if ($grupoResourceName) {
                $this->line("  Marcador Google: {$grupoResourceName}");
            } else {
                $this->warn('  Não foi possível criar/encontrar o marcador "Lead Certo" — contatos serão atualizados sem ele.');
            }

            $query = VinculoContatoTenant::with('contato')
                ->where('tenant_id', $token->tenant_id)
                ->whereNotNull('google_resource_name')
                ->whereNotNull('google_etag');

            $vinculos = $limit ? $query->limit($limit)->get() : $query->get();

            $total       = $vinculos->count();
            $atualizados = 0;
            $falhas      = 0;
            $etagStale   = 0;

            // Buffer de resourceNames para adicionar ao grupo em lotes
            $buffer = [];

            $this->line("  {$total} contatos para enriquecer no Google");

            if ($total === 0) {
                $this->info('  Nada a fazer.');
                continue;
            }

            $bar = $this->output->createProgressBar($total);
            $bar->start();

            foreach ($vinculos->chunk($batch) as $lote) {
                foreach ($lote as $vinculo) {
                    $contato = $vinculo->contato;
                    if (! $contato) {
                        $bar->advance();
                        continue;
                    }

                    // Detecta contatos sem nome real (já limpados pelo contatos:limpar-nomes)
                    $nomeDB  = $contato->nome ?? '';
                    $semNome = ! $nomeDB
                        || $nomeDB === 'Sem Nome'
                        || $nomeDB === $contato->telefone;

                    // Usa contato.nome limpo como fonte canônica — NÃO usa google_given_name
                    // (o google_given_name é do sistema antigo e pode estar sujo)
                    $givenName  = $semNome ? 'Sem Nome' : $google->limparNome($nomeDB);
                    $familyName = $contato->sobrenome ?: null; // descritor legado salvo pelo limpar-nomes
                    $middleName = (string) $contato->id;

                    $nameEntry = ['givenName' => $givenName, 'middleName' => $middleName];
                    if ($familyName) {
                        $nameEntry['familyName'] = $familyName;
                    }

                    $updateFields = 'names';
                    $body = ['etag' => $vinculo->google_etag, 'names' => [$nameEntry]];

                    if ($contato->email) {
                        $body['emailAddresses'] = [['value' => $contato->email, 'type' => 'work']];
                        $updateFields .= ',emailAddresses';
                    }

                    // Normaliza telefone: inclui se tiver DDD + número (≥10 dígitos)
                    $teleNorm = $this->normalizarTelefone($contato->telefone ?? '');
                    if ($teleNorm) {
                        $body['phoneNumbers'] = [['value' => $teleNorm, 'type' => 'mobile']];
                        $updateFields .= ',phoneNumbers';
                    }

                    try {
                        $validToken = $google->tokenValido($token);
                        if (! $validToken) {
                            $this->newLine();
                            $this->error("Token inválido para tenant #{$token->tenant_id}");
                            break 2;
                        }

                        $res = Http::withToken($validToken->access_token)
                            ->patch(
                                "https://people.googleapis.com/v1/{$vinculo->google_resource_name}:updateContact?updatePersonFields={$updateFields}",
                                $body
                            );

                        // Etag stale: re-busca etag atual do Google e tenta de novo
                        if (($res->status() === 409 || $res->status() === 400) &&
                            str_contains($res->body(), 'etag')) {
                            $etag = $this->buscarEtagAtual($validToken->access_token, $vinculo->google_resource_name);
                            if ($etag) {
                                $vinculo->update(['google_etag' => $etag]);
                                $body['etag'] = $etag;
                                $res = Http::withToken($validToken->access_token)
                                    ->patch(
                                        "https://people.googleapis.com/v1/{$vinculo->google_resource_name}:updateContact?updatePersonFields={$updateFields}",
                                        $body
                                    );
                            }
                        }

                        if ($res->successful()) {
                            $novoEtag = $res->json('etag');
                            if ($novoEtag) {
                                $vinculo->update(['google_etag' => $novoEtag]);
                            }
                            $atualizados++;

                            // Acumula para adicionar ao marcador "Lead Certo"
                            if ($grupoResourceName) {
                                $buffer[] = $vinculo->google_resource_name;

                                // Envia em lotes de 50 para não sobrecarregar a API
                                if (count($buffer) >= 50) {
                                    $google->modificarMembrosGrupo($validToken, $grupoResourceName, $buffer);
                                    $buffer = [];
                                    usleep(200_000); // 200ms após adição em lote
                                }
                            }
                        } elseif ($res->status() === 404) {
                            // Contato deletado do Google — limpa o resource name no banco
                            $vinculo->update(['google_resource_name' => null, 'google_etag' => null]);
                            $falhas++;
                        } else {
                            Log::warning('EnriquecerContatosGoogle: falha na API', [
                                'contato_id' => $contato->id,
                                'resource'   => $vinculo->google_resource_name,
                                'status'     => $res->status(),
                                'body'       => substr($res->body(), 0, 200),
                            ]);
                            $falhas++;
                        }
                    } catch (\Exception $e) {
                        $falhas++;
                        Log::error('EnriquecerContatosGoogle: exception', ['erro' => $e->getMessage()]);
                    }

                    $bar->advance();
                    usleep(60_000); // 60ms entre requests (quota 60 req/min)
                }
            }

            // Envia restantes do buffer
            if ($grupoResourceName && ! empty($buffer)) {
                $validToken = $google->tokenValido($token);
                if ($validToken) {
                    $google->modificarMembrosGrupo($validToken, $grupoResourceName, $buffer);
                }
            }

            $bar->finish();
            $this->newLine();
            $this->info("  ✓ Atualizados: {$atualizados}");

            if ($etagStale > 0) {
                $this->warn("  ⚠ Etag desatualizada: {$etagStale} — rode 'google:sincronizar' depois");
            }
            if ($falhas > 0) {
                $this->warn("  ✗ Falhas: {$falhas}");
            }
        }

        return Command::SUCCESS;
    }

    /**
     * Busca o etag atual de um contato no Google (usado para resolver conflito de etag).
     */
    private function buscarEtagAtual(string $accessToken, string $resourceName): ?string
    {
        try {
            $res = Http::withToken($accessToken)
                ->get("https://people.googleapis.com/v1/{$resourceName}", [
                    'personFields' => 'metadata',
                ]);
            return $res->successful() ? $res->json('etag') : null;
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Normaliza telefone para formato internacional brasileiro.
     * Retorna "+55XXXXXXXXXXX" se tiver DDD + número (≥10 dígitos).
     * Retorna null para números incompletos que não sabemos o DDD.
     */
    private function normalizarTelefone(string $telefone): ?string
    {
        $digitos = preg_replace('/\D/', '', $telefone);

        // Remove zero inicial (ex: "021..." → "21...")
        $digitos = ltrim($digitos, '0');

        if (strlen($digitos) === 0) return null;

        // Já tem DDI 55 + DDD + número (12-13 dígitos)
        if (str_starts_with($digitos, '55') && strlen($digitos) >= 12) {
            return '+' . $digitos;
        }

        // Tem DDD (2 dígitos) + número (8-9 dígitos) = 10-11 dígitos
        if (strlen($digitos) >= 10 && strlen($digitos) <= 11) {
            return '+55' . $digitos;
        }

        // Número incompleto (sem DDD) — não alteramos para não corromper
        return null;
    }

    /**
     * Busca o grupo "Lead Certo" no Google ou cria se não existir.
     * Retorna o resourceName do grupo ou null em caso de erro.
     */
    private function obterOuCriarGrupo(GoogleService $google, GoogleToken $token): ?string
    {
        $validToken = $google->tokenValido($token);
        if (! $validToken) return null;

        try {
            // Lista grupos existentes
            $res = Http::withToken($validToken->access_token)
                ->get('https://people.googleapis.com/v1/contactGroups', [
                    'pageSize' => 200,
                ]);

            if ($res->successful()) {
                foreach ($res->json('contactGroups') ?? [] as $grupo) {
                    if (($grupo['name'] ?? '') === 'Lead Certo') {
                        return $grupo['resourceName'];
                    }
                }
            }
        } catch (\Exception $e) {
            Log::warning('EnriquecerContatosGoogle: erro ao listar grupos', ['erro' => $e->getMessage()]);
        }

        // Não encontrou — cria
        return $google->criarGrupoContato($validToken, 'Lead Certo');
    }
}
