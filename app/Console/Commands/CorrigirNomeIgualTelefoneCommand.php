<?php

namespace App\Console\Commands;

use App\Models\AuditoriaContato;
use App\Models\Contato;
use Illuminate\Console\Command;

/**
 * Corrige contatos cujo nome é literalmente o próprio telefone repetido —
 * diferente das heurísticas "suspeitas" do contatos:limpar-nomes (que
 * precisam de julgamento da IA), esse caso é inequívoco: um telefone nunca
 * é um nome de verdade, não há necessidade de revisão humana.
 */
class CorrigirNomeIgualTelefoneCommand extends Command
{
    protected $signature = 'contatos:corrigir-nome-telefone {--dry-run : Mostra o que seria feito sem salvar}';

    protected $description = 'Marca como "Sem Nome" contatos cujo nome é exatamente igual ao telefone';

    public function handle(): int
    {
        $dryRun = $this->option('dry-run');

        $ids = Contato::whereColumn('nome', 'telefone')->pluck('id');

        $this->info($ids->count() . ' contatos com nome igual ao telefone.');

        if ($ids->isEmpty()) {
            return Command::SUCCESS;
        }

        if ($dryRun) {
            $this->warn('Rode sem --dry-run para aplicar a correção.');
            return Command::SUCCESS;
        }

        Contato::whereIn('id', $ids)->update(['nome' => 'Sem Nome']);

        // Resolve pendências de auditoria já cobertas por essa correção direta,
        // pra não sobrar item duplicado esperando revisão manual à toa.
        $resolvidos = AuditoriaContato::where('campo', 'nome')
            ->where('status', 'pendente')
            ->whereIn('contato_id', $ids)
            ->update(['status' => 'resolvido', 'resolvido_em' => now()]);

        $this->info("{$ids->count()} contatos corrigidos para 'Sem Nome'.");
        $this->info("{$resolvidos} pendências de auditoria resolvidas junto.");

        return Command::SUCCESS;
    }
}
