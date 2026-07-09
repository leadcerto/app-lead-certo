<?php

namespace App\Console\Commands;

use App\Models\AuditoriaContato;
use App\Models\Contato;
use App\Services\TelefoneService;
use Illuminate\Console\Command;

class NormalizarTelefonesCommand extends Command
{
    protected $signature = 'contatos:normalizar-telefones
                            {--dry-run : Mostra o que seria feito sem salvar}
                            {--chunk=500 : Quantidade de contatos por lote}';

    protected $description = 'Normaliza todos os telefones de contatos para o formato 55DDXXXXXXXX(X)';

    public function handle(TelefoneService $tel): int
    {
        $dryRun = $this->option('dry-run');
        $chunk  = (int) $this->option('chunk');

        $corrigidos = 0;
        $invalidos  = 0;
        $intocados  = 0;

        $this->info($dryRun ? '[DRY-RUN] Nenhuma alteração será salva.' : 'Normalizando telefones...');

        Contato::orderBy('id')->chunk($chunk, function ($contatos) use ($tel, $dryRun, &$corrigidos, &$invalidos, &$intocados) {
            foreach ($contatos as $contato) {
                $resultado = $tel->diagnosticar($contato->telefone);

                if ($resultado['status'] === 'valido') {
                    $intocados++;
                    continue;
                }

                if ($resultado['status'] === 'corrigido') {
                    $novo = $resultado['normalizado'];
                    $this->line("  CORRIGIR [{$contato->telefone}] → [{$novo}]");

                    if (! $dryRun) {
                        // Evita duplicata: se já existe contato com o número normalizado, ignora
                        $existe = Contato::where('telefone', $novo)->where('id', '!=', $contato->id)->exists();
                        if ($existe) {
                            $this->warn("    SKIP: já existe contato com [{$novo}]");
                            $invalidos++;
                            continue;
                        }

                        $contato->update(['telefone' => $novo]);

                        // Remove auditoria de telefone anterior se houver
                        AuditoriaContato::where('contato_id', $contato->id)
                            ->where('tipo', 'telefone')
                            ->where('status', 'pendente')
                            ->update(['status' => 'resolvido', 'resolvido_em' => now()]);
                    }
                    $corrigidos++;
                    continue;
                }

                // Inválido — não conseguiu normalizar
                $this->warn("  INVÁLIDO [{$contato->telefone}] — {$resultado['motivo']}");
                $invalidos++;

                if (! $dryRun) {
                    // Cria/atualiza registro de auditoria para revisão manual
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
                ['Já válidos (intocados)', $intocados],
                ['Corrigidos automaticamente', $corrigidos],
                ['Inválidos (auditoria criada)', $invalidos],
            ]
        );

        if ($dryRun) {
            $this->warn('Rode sem --dry-run para aplicar as correções.');
        }

        return 0;
    }
}
