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
                    $etiquetasOrigemDoVinculo = $vinculo->etiquetas()->whereIn('slug', $slugsOrigem)->get();

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

                    $this->google->modificarMembrosGrupo(
                        $token,
                        $grupoAlvo->google_group_resource_name,
                        resourceNamesToAdd: [$vinculo->google_resource_name],
                    );

                    $vinculo->etiquetas()->detach($etiquetasOrigemDoVinculo->pluck('id'));
                    $vinculo->etiquetas()->syncWithoutDetaching([$etiquetaAlvo->id]);
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
     * Simula o resultado da validação sem tocar no banco nem no Google —
     * usa a mesma leitura de candidatos do serviço, só não aplica a
     * mesclagem/autocorreção.
     */
    private function preverResultado(VinculoContatoTenant $vinculo): string
    {
        $reparo = app(\App\Services\TelefoneReparoService::class);

        if ($reparo->ehCanonico($vinculo->contato->telefone)) {
            return 'lead_certo';
        }

        return empty($reparo->candidatos($vinculo->contato->telefone)) ? 'lead_invalido' : 'lead_certo';
    }
}
