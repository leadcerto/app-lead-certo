<?php

namespace App\Console\Commands;

use App\Models\Etiqueta;
use App\Models\VinculoContatoTenant;
use App\Services\ContatoValidacaoService;
use App\Services\GoogleService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Roda a validação de telefone (spec seção 5) sobre os contatos de um
 * tenant marcados como "novos_leads" ou "leads_em_analise" — decide
 * lead_certo (mescla/autocorrige) ou lead_invalido, aplica a etiqueta
 * final no Google removendo a de origem. --dry-run só mostra o que faria,
 * incluindo qual contato mescla em qual (gate operacional antes de aplicar
 * de verdade — spec).
 *
 * Regra do 8º dia (pedido do Leonardo, 24/09): --dias=N restringe aos
 * contatos cujo TICKET ATIVO ATUAL já está aberto há pelo menos N dias —
 * contagem reinicia se o ticket reabrir. Sem a flag, processa tudo que
 * estiver marcado, igual sempre foi (uso manual/dry-run). --tenant vira
 * opcional: sem ele, roda em todos os tenants com Google conectado — é
 * assim que o agendamento diário único (00h01, ver routes/console.php)
 * cobre a base inteira, regra global do Kanban, sem depender de canal.
 */
class ValidarCadastrosContatos extends Command
{
    protected $signature = 'contatos:validar-cadastros
                            {--tenant= : ID do tenant (padrão: todos os tenants com Google conectado)}
                            {--dry-run : Mostra o que seria feito sem aplicar}
                            {--chunk=200 : Quantidade de vínculos por lote}
                            {--dias= : Só processa contatos cujo ticket ativo está aberto há pelo menos N dias}';

    protected $description = 'Valida telefone dos contatos em analise/novos e aplica lead_certo ou lead_invalido';

    private int $certos    = 0;
    private int $invalidos = 0;
    private int $erros     = 0;

    public function __construct(
        private ContatoValidacaoService $validacao,
        private GoogleService $google,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $tenantIdOption = $this->option('tenant');

        $tokensQuery = \App\Models\GoogleToken::query();
        if ($tenantIdOption) {
            $tokensQuery->where('tenant_id', (int) $tenantIdOption);
        }
        $tokens = $tokensQuery->get();

        if ($tokens->isEmpty()) {
            $this->error($tenantIdOption ? 'Tenant sem GoogleToken conectado.' : 'Nenhum tenant com GoogleToken conectado.');

            return 1;
        }

        foreach ($tokens as $token) {
            $this->processarTenant($token, (int) $token->tenant_id);
        }

        return 0;
    }

    private function processarTenant(\App\Models\GoogleToken $token, int $tenantId): void
    {
        $dryRun      = (bool) $this->option('dry-run');
        $chunk       = (int) $this->option('chunk');
        $diasMinimos = $this->option('dias') !== null ? (int) $this->option('dias') : null;

        $this->certos = $this->invalidos = $this->erros = 0;

        $slugsOrigem = ['novos_leads', 'leads_em_analise'];

        $etiquetaLeadCerto    = Etiqueta::whereNull('tenant_id')->where('slug', 'lead_certo')->first();
        $etiquetaLeadInvalido = Etiqueta::whereNull('tenant_id')->where('slug', 'lead_invalido')->first();
        $grupoLeadCerto       = $etiquetaLeadCerto?->googleGrupoParaTenant($tenantId);
        $grupoLeadInvalido    = $etiquetaLeadInvalido?->googleGrupoParaTenant($tenantId);

        if (! $grupoLeadCerto || ! $grupoLeadInvalido) {
            $this->error("Tenant #{$tenantId}: etiquetas de validação não provisionadas.");

            return;
        }

        $this->info("Tenant #{$tenantId}: " . ($dryRun ? '[DRY-RUN] Nenhuma alteração será salva.' : 'Validando cadastros...'));

        $query = VinculoContatoTenant::where('tenant_id', $tenantId)
            ->whereNotNull('google_resource_name')
            ->whereHas('etiquetas', fn ($q) => $q->whereIn('slug', $slugsOrigem));

        if ($diasMinimos !== null) {
            $query->whereExists(function ($sub) use ($diasMinimos) {
                $sub->selectRaw('1')
                    ->from('tickets_atendimento')
                    ->whereColumn('tickets_atendimento.contato_id', 'vinculos_contato_tenant.contato_id')
                    ->whereIn('tickets_atendimento.status', ['aberto', 'aguardando'])
                    ->where('tickets_atendimento.aberto_em', '<=', now()->subDays($diasMinimos));
            });
        }

        $query->with('contato', 'etiquetas')
            ->chunkById($chunk, function ($lote) use ($dryRun, $token, $tenantId, $grupoLeadCerto, $grupoLeadInvalido, $etiquetaLeadCerto, $etiquetaLeadInvalido, $slugsOrigem) {
                foreach ($lote as $vinculo) {
                    if (! $vinculo->contato) {
                        continue;
                    }

                    if ($dryRun) {
                        $classificacao = $this->validacao->classificar($vinculo->contato);
                        $resultado     = $classificacao['estado'];

                        $linha = "  {$vinculo->contato->telefone} (contato #{$vinculo->contato_id}) -> {$resultado}";
                        if ($classificacao['acao'] === 'mesclar') {
                            $alvo   = $classificacao['alvo'];
                            $linha .= " [mesclaria com contato #{$alvo->id}, telefone {$alvo->telefone}]";
                        } elseif ($classificacao['acao'] === 'autocorrigir') {
                            $linha .= " [autocorrigiria telefone pra {$classificacao['alvo']}]";
                        }
                        $this->line($linha);

                        $resultado === 'lead_certo' ? $this->certos++ : $this->invalidos++;
                        continue;
                    }

                    // Guarda as etiquetas de origem ANTES de chamar validar() --
                    // se o merge apagar este vínculo, o card dele no Google já
                    // tinha sido adicionado ao grupo de origem antes, e ninguém
                    // mais vai remover isso depois (nenhum registro local aponta
                    // mais pra ele). Buscar isso DEPOIS da possível exclusão
                    // arriscaria vir vazio -- por isso a busca é feita aqui,
                    // enquanto o vínculo (e o pivot) ainda existem garantidamente.
                    $etiquetasOrigemDoVinculo = $vinculo->etiquetas()->whereIn('slug', $slugsOrigem)->get();

                    try {
                        $resultado = $this->validacao->validar($vinculo->contato);

                        // Após validar(), o merge pode ter apagado o vínculo (se
                        // o contato sobrevivente já tinha vínculo pro mesmo
                        // tenant). O google_resource_name ainda está disponível
                        // no objeto $vinculo em memória, mesmo com a linha
                        // apagada do banco -- usa isso pra remover o card dos
                        // grupos de origem no Google agora, já que nenhum
                        // registro local vai sobrar pra fazer essa limpeza depois.
                        if (! VinculoContatoTenant::find($vinculo->id)) {
                            foreach ($etiquetasOrigemDoVinculo as $etiquetaOrigem) {
                                $grupoOrigem = $etiquetaOrigem->googleGrupoParaTenant($tenantId);
                                if ($grupoOrigem) {
                                    $this->google->modificarMembrosGrupo(
                                        $token,
                                        $grupoOrigem->google_group_resource_name,
                                        resourceNamesToRemove: [$vinculo->google_resource_name],
                                    );
                                }
                            }
                            $this->line("  contato #{$vinculo->contato_id}: vínculo mesclado/removido, etiqueta de origem removida do Google");
                            continue;
                        }

                        $etiquetaAlvo = $resultado === 'lead_certo' ? $etiquetaLeadCerto : $etiquetaLeadInvalido;
                        $grupoAlvo    = $resultado === 'lead_certo' ? $grupoLeadCerto : $grupoLeadInvalido;

                        $resultado === 'lead_certo' ? $this->certos++ : $this->invalidos++;

                        $this->line("  {$vinculo->contato->telefone} (contato #{$vinculo->contato_id}) -> {$resultado}");

                        // A API do Google (members:modify) opera em UM grupo por
                        // chamada — não dá pra "mover" entre dois grupos numa
                        // chamada só. Precisa de uma chamada de remove no grupo
                        // de origem e outra de add no grupo de destino.
                        // Remove que falha é apenas aviso; Add que falha impede
                        // a atualização local (crítico para manter coerência).
                        $removidoOk = true;
                        foreach ($etiquetasOrigemDoVinculo as $etiquetaOrigem) {
                            $grupoOrigem = $etiquetaOrigem->googleGrupoParaTenant($tenantId);
                            if ($grupoOrigem) {
                                $ok = $this->google->modificarMembrosGrupo(
                                    $token,
                                    $grupoOrigem->google_group_resource_name,
                                    resourceNamesToRemove: [$vinculo->google_resource_name],
                                );
                                $removidoOk = $removidoOk && $ok;
                            }
                        }

                        $adicionadoOk = $this->google->modificarMembrosGrupo(
                            $token,
                            $grupoAlvo->google_group_resource_name,
                            resourceNamesToAdd: [$vinculo->google_resource_name],
                        );

                        if (! $adicionadoOk) {
                            $this->warn("  contato #{$vinculo->contato_id}: falha ao adicionar etiqueta no Google, não atualizado localmente");
                            continue;
                        }

                        $vinculo->etiquetas()->detach($etiquetasOrigemDoVinculo->pluck('id'));
                        $vinculo->etiquetas()->syncWithoutDetaching([$etiquetaAlvo->id]);

                        if (! $removidoOk) {
                            $this->warn("  contato #{$vinculo->contato_id}: falha ao remover etiqueta de origem no Google (corrigido localmente, Google pode ficar com as duas etiquetas até a próxima varredura)");
                        }
                    } catch (\Throwable $e) {
                        // Isola a falha NESTE vínculo -- um lote tem até
                        // {$chunk} contatos, e um erro (ex: UniqueConstraintViolationException
                        // ao autocorrigir telefone pra um valor que um contato
                        // soft-deleted ainda segura) não pode abortar o comando
                        // inteiro no meio de um lote de milhares de contatos sem
                        // deixar rastro de onde parou.
                        $this->erros++;
                        $this->error("  contato #{$vinculo->contato_id}: erro ao processar -- {$e->getMessage()}");
                        Log::error('ValidarCadastrosContatos: erro ao processar vinculo', [
                            'vinculo_id' => $vinculo->id,
                            'contato_id' => $vinculo->contato_id,
                            'erro'       => $e->getMessage(),
                        ]);
                        continue;
                    }
                }
            });

        $this->newLine();
        $this->table(
            ['Status', 'Quantidade'],
            [
                ['LEAD CERTO', $this->certos],
                ['LEAD INVALIDO', $this->invalidos],
                ['ERROS', $this->erros],
            ]
        );

        if ($dryRun) {
            $this->warn('Rode sem --dry-run para aplicar.');
        }
    }
}
