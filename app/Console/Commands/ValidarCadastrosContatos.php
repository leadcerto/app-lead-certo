<?php

namespace App\Console\Commands;

use App\Models\Etiqueta;
use App\Models\VinculoContatoTenant;
use App\Services\ContatoValidacaoService;
use App\Services\GoogleService;
use Illuminate\Console\Command;

/**
 * Roda a validação de telefone (spec seção 5) sobre os contatos de um
 * tenant marcados como "novos_leads" ou "leads_em_analise" — decide
 * lead_certo (mescla/autocorrige) ou lead_invalido, aplica a etiqueta
 * final no Google removendo a de origem. --dry-run só mostra o que faria.
 */
class ValidarCadastrosContatos extends Command
{
    protected $signature = 'contatos:validar-cadastros
                            {--tenant= : ID do tenant}
                            {--dry-run : Mostra o que seria feito sem aplicar}
                            {--chunk=200 : Quantidade de vínculos por lote}';

    protected $description = 'Valida telefone dos contatos em analise/novos de um tenant e aplica lead_certo ou lead_invalido';

    private int $certos    = 0;
    private int $invalidos = 0;

    public function __construct(
        private ContatoValidacaoService $validacao,
        private GoogleService $google,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $tenantId = (int) $this->option('tenant');
        if (! $tenantId) {
            $this->error('--tenant é obrigatório.');

            return 1;
        }

        $dryRun = (bool) $this->option('dry-run');
        $chunk  = (int) $this->option('chunk');

        $token = \App\Models\GoogleToken::where('tenant_id', $tenantId)->first();
        if (! $token) {
            $this->error('Tenant sem GoogleToken conectado.');

            return 1;
        }

        $slugsOrigem = ['novos_leads', 'leads_em_analise'];

        $etiquetaLeadCerto    = Etiqueta::whereNull('tenant_id')->where('slug', 'lead_certo')->first();
        $etiquetaLeadInvalido = Etiqueta::whereNull('tenant_id')->where('slug', 'lead_invalido')->first();
        $grupoLeadCerto       = $etiquetaLeadCerto?->googleGrupoParaTenant($tenantId);
        $grupoLeadInvalido    = $etiquetaLeadInvalido?->googleGrupoParaTenant($tenantId);

        if (! $grupoLeadCerto || ! $grupoLeadInvalido) {
            $this->error('Etiquetas de validação não provisionadas pra este tenant.');

            return 1;
        }

        $this->info($dryRun ? '[DRY-RUN] Nenhuma alteração será salva.' : 'Validando cadastros...');

        VinculoContatoTenant::where('tenant_id', $tenantId)
            ->whereNotNull('google_resource_name')
            ->whereHas('etiquetas', fn ($q) => $q->whereIn('slug', $slugsOrigem))
            ->with('contato', 'etiquetas')
            ->chunkById($chunk, function ($lote) use ($dryRun, $token, $tenantId, $grupoLeadCerto, $grupoLeadInvalido, $etiquetaLeadCerto, $etiquetaLeadInvalido, $slugsOrigem) {
                foreach ($lote as $vinculo) {
                    if (! $vinculo->contato) {
                        continue;
                    }

                    $resultado = $dryRun
                        ? $this->preverResultado($vinculo)
                        : $this->validacao->validar($vinculo->contato);

                    // Após validar(), o merge pode ter apagado o vínculo
                    // (se o contato sobrevivente já tinha vínculo pro mesmo tenant).
                    // Se isso aconteceu, ele será processado novamente neste mesmo
                    // lote (se elegível) ou na próxima varredura -- nada a fazer aqui.
                    if (! $dryRun && ! VinculoContatoTenant::find($vinculo->id)) {
                        $this->line("  contato #{$vinculo->contato_id}: vínculo mesclado/removido, pulando");
                        continue;
                    }

                    $etiquetaAlvo = $resultado === 'lead_certo' ? $etiquetaLeadCerto : $etiquetaLeadInvalido;
                    $grupoAlvo    = $resultado === 'lead_certo' ? $grupoLeadCerto : $grupoLeadInvalido;

                    $resultado === 'lead_certo' ? $this->certos++ : $this->invalidos++;

                    $this->line("  {$vinculo->contato->telefone} (contato #{$vinculo->contato_id}) -> {$resultado}");

                    if ($dryRun) {
                        continue;
                    }

                    // A API do Google (members:modify) opera em UM grupo por
                    // chamada — não dá pra "mover" entre dois grupos numa
                    // chamada só. Precisa de uma chamada de remove no grupo
                    // de origem e outra de add no grupo de destino.
                    // Remove que falha é apenas aviso; Add que falha impede
                    // a atualização local (crítico para manter coerência).
                    $etiquetasOrigemDoVinculo = $vinculo->etiquetas()->whereIn('slug', $slugsOrigem)->get();

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
                }
            });

        $this->newLine();
        $this->table(
            ['Status', 'Quantidade'],
            [
                ['LEAD CERTO', $this->certos],
                ['LEAD INVALIDO', $this->invalidos],
            ]
        );

        if ($dryRun) {
            $this->warn('Rode sem --dry-run para aplicar.');
        }

        return 0;
    }

    /**
     * Preve o resultado da validação sem tocar no banco nem no Google —
     * usa a mesma lógica de classificação de ContatoValidacaoService,
     * sem executar a ação (mescla/autocorreção).
     */
    private function preverResultado(VinculoContatoTenant $vinculo): string
    {
        return $this->validacao->classificar($vinculo->contato)['estado'];
    }
}
