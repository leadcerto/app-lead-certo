<?php

namespace App\Console\Commands;

use App\Models\AuditoriaContato;
use App\Models\Contato;
use App\Services\TelefoneService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class NormalizarTelefonesCommand extends Command
{
    protected $signature = 'contatos:normalizar-telefones
                            {--dry-run : Mostra o que seria feito sem salvar}
                            {--chunk=500 : Quantidade de contatos por lote}';

    protected $description = 'Normaliza telefones para 55DDXXXXXXXX e mescla duplicatas geradas por formatos diferentes';

    private array $camposHerdaveis = [
        'nome','email','email_2','profissao','empresa','departamento',
        'endereco','cidade','estado','cep','pais',
        'instagram','facebook','linkedin','twitter','website','observacoes',
        'genero','estado_civil','aniversario','cpf','rg','foto_url',
    ];

    public function handle(TelefoneService $tel): int
    {
        $dryRun = $this->option('dry-run');
        $chunk  = (int) $this->option('chunk');

        $this->info($dryRun ? '[DRY-RUN] Nenhuma alteração será salva.' : 'Iniciando normalização + fusão de duplicatas...');

        $mesclados  = 0;
        $corrigidos = 0;
        $invalidos  = 0;
        $intocados  = 0;

        Contato::orderBy('id')->chunk($chunk, function ($contatos) use ($tel, $dryRun, &$mesclados, &$corrigidos, &$invalidos, &$intocados) {
            foreach ($contatos as $contato) {
                $resultado = $tel->diagnosticar($contato->telefone);

                if ($resultado['status'] === 'valido') {
                    $intocados++;
                    continue;
                }

                if ($resultado['status'] === 'corrigido') {
                    $novo = $resultado['normalizado'];

                    $canonico = Contato::where('telefone', $novo)->where('id', '!=', $contato->id)->first();

                    if ($canonico) {
                        // Já existe contato com o número normalizado → MESCLAR
                        $this->line("  MESCLAR [{$contato->telefone}] #{$contato->id} → [{$novo}] #{$canonico->id}");

                        if (! $dryRun) {
                            $this->mesclar($contato, $canonico);
                        }
                        $mesclados++;
                    } else {
                        // Número não existe ainda → apenas corrigir o formato
                        $this->line("  CORRIGIR [{$contato->telefone}] → [{$novo}]");

                        if (! $dryRun) {
                            $contato->update(['telefone' => $novo]);

                            AuditoriaContato::where('contato_id', $contato->id)
                                ->where('tipo', 'telefone')
                                ->where('status', 'pendente')
                                ->update(['status' => 'resolvido', 'resolvido_em' => now()]);
                        }
                        $corrigidos++;
                    }
                    continue;
                }

                // Inválido — não conseguiu normalizar
                $this->warn("  INVÁLIDO [{$contato->telefone}] — {$resultado['motivo']}");
                $invalidos++;

                if (! $dryRun) {
                    AuditoriaContato::updateOrCreate(
                        ['contato_id' => $contato->id, 'tipo' => 'telefone', 'status' => 'pendente'],
                        [
                            'campo'          => 'telefone',
                            'valor_original' => $contato->telefone,
                            'valor_sugerido' => null,
                            'observacao'     => "Formato desconhecido — {$resultado['motivo']}",
                        ]
                    );
                }
            }
        });

        $this->newLine();
        $this->table(
            ['Status', 'Quantidade'],
            [
                ['Já válidos (intocados)',          $intocados],
                ['Duplicatas mescladas (removidas)', $mesclados],
                ['Corrigidos (só formato)',          $corrigidos],
                ['Inválidos (auditoria criada)',     $invalidos],
            ]
        );

        if ($dryRun) {
            $this->warn('Rode sem --dry-run para aplicar as alterações.');
        }

        return 0;
    }

    private function mesclar(Contato $antigo, Contato $canonico): void
    {
        DB::transaction(function () use ($antigo, $canonico) {
            // 1. Tickets
            DB::table('tickets_atendimento')
                ->where('contato_id', $antigo->id)
                ->update(['contato_id' => $canonico->id]);

            // 2. Notas
            if (DB::getSchemaBuilder()->hasTable('notas_contato')) {
                DB::table('notas_contato')
                    ->where('contato_id', $antigo->id)
                    ->update(['contato_id' => $canonico->id]);
            }

            // 3. Auditoria
            DB::table('auditoria_contatos')
                ->where('contato_id', $antigo->id)
                ->update(['contato_id' => $canonico->id]);

            // 4. Vínculos tenant — unique (contato_id, tenant_id)
            $tenantsDoCanonico = DB::table('vinculos_contato_tenant')
                ->where('contato_id', $canonico->id)
                ->pluck('tenant_id')
                ->toArray();

            DB::table('vinculos_contato_tenant')
                ->where('contato_id', $antigo->id)
                ->whereNotIn('tenant_id', $tenantsDoCanonico)
                ->update(['contato_id' => $canonico->id]);

            DB::table('vinculos_contato_tenant')
                ->where('contato_id', $antigo->id)
                ->delete();

            // 5. Herda campos vazios do antigo
            $updates = [];
            foreach ($this->camposHerdaveis as $campo) {
                if (empty($canonico->{$campo}) && ! empty($antigo->{$campo})) {
                    $updates[$campo] = $antigo->{$campo};
                }
            }
            if ($updates) {
                DB::table('contatos')->where('id', $canonico->id)->update($updates);
            }

            // 6. Remove o duplicado definitivamente
            DB::table('auditoria_contatos')->where('contato_id', $antigo->id)->delete();
            DB::table('vinculos_contato_tenant')->where('contato_id', $antigo->id)->delete();
            $antigo->forceDelete();
        });
    }
}
