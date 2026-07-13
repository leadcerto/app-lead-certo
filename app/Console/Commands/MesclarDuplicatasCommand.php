<?php

namespace App\Console\Commands;

use App\Models\AuditoriaContato;
use App\Models\ChamadaPerdida;
use App\Models\Contato;
use App\Models\FormularioEnvio;
use App\Models\NotaContato;
use App\Models\TicketAtendimento;
use App\Models\VinculoContatoTenant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class MesclarDuplicatasCommand extends Command
{
    protected $signature = 'contatos:mesclar-duplicatas
                            {--dry-run : Mostra o que seria feito sem salvar}
                            {--chunk=200 : Quantidade de contatos por lote}';

    protected $description = 'Mescla contatos duplicados por formato de telefone: 12 digitos (sem 9) ou 11 digitos (sem 55) no 13 digitos canonico';

    private int $mesclados = 0;
    private int $ignorados = 0;
    private int $erros     = 0;

    public function handle(): int
    {
        $dryRun = $this->option('dry-run');
        $chunk  = (int) $this->option('chunk');

        $this->info($dryRun ? '[DRY-RUN] Nenhuma alteracao sera salva.' : 'Mesclando duplicatas...');

        $this->info('Fase 1: telefones de 12 digitos (sem o 9)');
        // Somente 12 digitos onde o 1o digito local (pos 5) e movel (6/7/8/9)
        // Landlines legítimos (iniciam com 2/3/4/5) sao ignorados
        Contato::withTrashed()
            ->whereRaw("LENGTH(telefone) = 12")
            ->whereRaw("SUBSTRING(telefone, 1, 2) = '55'")
            ->whereRaw("SUBSTRING(telefone, 5, 1) IN ('6','7','8','9')")
            ->orderBy('id')
            ->chunk($chunk, function ($lote) use ($dryRun) {
                foreach ($lote as $antigo) {
                    // Constroi o numero 13 digitos: 55DD + 9 + 8 digitos
                    $tel13 = substr($antigo->telefone, 0, 4) . '9' . substr($antigo->telefone, 4);
                    $this->mesclarSeTiverPar($antigo, $tel13, $dryRun);
                }
            });

        $this->info('Fase 2: telefones de 11 digitos (sem o 55) — sempre celular (DDD + 9 digitos)');
        Contato::withTrashed()
            ->whereRaw('LENGTH(telefone) = 11')
            ->orderBy('id')
            ->chunk($chunk, function ($lote) use ($dryRun) {
                foreach ($lote as $antigo) {
                    $tel13 = '55' . $antigo->telefone;
                    $this->mesclarSeTiverPar($antigo, $tel13, $dryRun);
                }
            });

        $this->newLine();
        $this->table(
            ['Status', 'Quantidade'],
            [
                ['Pares mesclados (antigo removido)', $this->mesclados],
                ['Sem par 13 digitos (ignorados)',    $this->ignorados],
                ['Erros de transacao',                $this->erros],
            ]
        );

        if ($dryRun) {
            $this->warn('Rode sem --dry-run para aplicar as mesclagens.');
        }

        return $this->erros > 0 ? 1 : 0;
    }

    private function mesclarSeTiverPar(Contato $antigo, string $tel13, bool $dryRun): void
    {
        $canonico = Contato::withTrashed()->where('telefone', $tel13)->first();

        if (! $canonico) {
            $this->ignorados++;
            return;
        }

        $this->line("  MESCLAR [{$antigo->telefone}] id={$antigo->id} → [{$tel13}] id={$canonico->id}");

        if ($dryRun) {
            $this->mesclados++;
            return;
        }

        try {
            DB::transaction(function () use ($antigo, $canonico) {
                // 1. Tickets (sem global scope de tenant)
                TicketAtendimento::withoutGlobalScopes()
                    ->where('contato_id', $antigo->id)
                    ->update(['contato_id' => $canonico->id]);

                // 2. Notas
                NotaContato::where('contato_id', $antigo->id)
                    ->update(['contato_id' => $canonico->id]);

                // 3. Chamadas perdidas
                ChamadaPerdida::where('contato_id', $antigo->id)
                    ->update(['contato_id' => $canonico->id]);

                // 4. Formulario envios
                FormularioEnvio::where('contato_id', $antigo->id)
                    ->update(['contato_id' => $canonico->id]);

                // 5. Auditoria
                AuditoriaContato::where('contato_id', $antigo->id)
                    ->update(['contato_id' => $canonico->id]);

                // 6. Vinculos tenant — unique (contato_id, tenant_id) — merge cuidadoso
                $vinculosAntigo = VinculoContatoTenant::where('contato_id', $antigo->id)->get();
                foreach ($vinculosAntigo as $vinculo) {
                    $jaExiste = VinculoContatoTenant::where('contato_id', $canonico->id)
                        ->where('tenant_id', $vinculo->tenant_id)
                        ->exists();
                    if ($jaExiste) {
                        $vinculo->delete();
                    } else {
                        $vinculo->update(['contato_id' => $canonico->id]);
                    }
                }

                // 7. Enriquece canonico com dados do antigo (nao sobrescreve campos preenchidos)
                $campos = ['nome', 'email', 'email_2', 'profissao', 'empresa', 'observacoes', 'foto_url', 'tags'];
                $updates = [];
                foreach ($campos as $campo) {
                    if (empty($canonico->{$campo}) && ! empty($antigo->{$campo})) {
                        $updates[$campo] = $antigo->{$campo};
                    }
                }
                if (! empty($updates)) {
                    $canonico->update($updates);
                }

                // 8. Remove o contato antigo (soft delete)
                $antigo->delete();

                Log::info('MesclarDuplicatas: mesclado', [
                    'antigo_id'    => $antigo->id,
                    'antigo_tel'   => $antigo->telefone,
                    'canonico_id'  => $canonico->id,
                    'canonico_tel' => $canonico->telefone,
                ]);
            });

            $this->mesclados++;
        } catch (\Throwable $e) {
            $this->erros++;
            $this->error("  ERRO id={$antigo->id}: " . $e->getMessage());
            Log::error('MesclarDuplicatas: erro', ['id' => $antigo->id, 'erro' => $e->getMessage()]);
        }
    }
}
