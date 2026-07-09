<?php

namespace App\Console\Commands;

use App\Models\Contato;
use App\Models\VinculoContatoTenant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class DeduplicarContatos extends Command
{
    protected $signature   = 'contatos:deduplicar {--dry-run : Apenas mostra o que seria feito}';
    protected $description = 'Mescla contatos duplicados com mesmo telefone em formatos diferentes';

    public function handle(): int
    {
        $dry = $this->option('dry-run');
        if ($dry) $this->line('--- DRY RUN (nada será salvo) ---');

        $this->info('Carregando contatos...');

        // Carrega id + telefone de contatos ativos (exclui soft-deleted)
        $todos = DB::table('contatos')->select('id', 'telefone')->whereNull('deleted_at')->get();

        // Agrupa por telefone canônico: '55' + DDD (2 dígitos) + número (8-9 dígitos)
        $grupos = [];
        foreach ($todos as $row) {
            $canonical = $this->canonico($row->telefone);
            if ($canonical === null) continue;
            $grupos[$canonical][] = $row->id;
        }

        // Filtra só grupos com duplicatas
        $duplicados = array_filter($grupos, fn($ids) => count($ids) > 1);

        if (empty($duplicados)) {
            $this->info('Nenhum duplicado encontrado.');
            return 0;
        }

        $this->info(count($duplicados) . ' grupos com duplicatas.');
        $mesclados = 0;

        foreach ($duplicados as $canonical => $ids) {
            // Vencedor: o que já tem o formato canônico (começa com '55'); senão o de menor ID
            $contatos = Contato::whereIn('id', $ids)->orderByRaw("
                CASE WHEN telefone LIKE '55%' AND LENGTH(telefone) BETWEEN 12 AND 13 THEN 0 ELSE 1 END,
                id ASC
            ")->get();

            $vencedor = $contatos->first();
            $perdedores = $contatos->slice(1);

            foreach ($perdedores as $perdedor) {
                $this->line("  [MESCLAR] #{$perdedor->id} ({$perdedor->telefone}) → #{$vencedor->id} ({$vencedor->telefone}) | {$vencedor->nome}");

                if ($dry) continue;

                DB::transaction(function () use ($vencedor, $perdedor) {
                    // Preenche campos vazios do vencedor com dados do perdedor
                    foreach (['nome','email','profissao','empresa','observacoes','cidade','estado','cep','endereco'] as $campo) {
                        if (empty($vencedor->$campo) && !empty($perdedor->$campo)) {
                            $vencedor->$campo = $perdedor->$campo;
                        }
                    }
                    $vencedor->save();

                    // Transfere vinculos (skip se já existe)
                    foreach (VinculoContatoTenant::where('contato_id', $perdedor->id)->get() as $vinculo) {
                        $jaExiste = VinculoContatoTenant::where('contato_id', $vencedor->id)
                            ->where('tenant_id', $vinculo->tenant_id)
                            ->exists();

                        $jaExiste ? $vinculo->delete() : $vinculo->update(['contato_id' => $vencedor->id]);
                    }

                    $perdedor->delete();
                });

                $mesclados++;
            }
        }

        $this->info($dry ? 'Simulação concluída.' : "{$mesclados} registros mesclados.");
        return 0;
    }

    private function canonico(string $tel): ?string
    {
        $d = preg_replace('/\D/', '', $tel);

        // Já tem DDI 55 e tamanho correto: 12 ou 13 dígitos
        if (preg_match('/^55(\d{10,11})$/', $d, $m)) {
            return '55' . $m[1];
        }

        // Remove zero inicial
        $semZero = str_starts_with($d, '0') ? substr($d, 1) : $d;

        // DDI 55 depois de strip do zero
        if (preg_match('/^55(\d{10,11})$/', $semZero, $m)) {
            return '55' . $m[1];
        }

        // DDD duplicado: 0 + DDD + DDD + número (ex: "02121982223599")
        if (strlen($semZero) >= 12 && strlen($semZero) <= 14) {
            $ddd   = substr($semZero, 0, 2);
            $resto = substr($semZero, 2);
            if (str_starts_with($resto, $ddd) && strlen($resto) >= 10 && strlen($resto) <= 11) {
                return '55' . $resto;
            }
        }

        // DDD + número: 10-11 dígitos → adiciona DDI 55
        if (strlen($semZero) >= 10 && strlen($semZero) <= 11) {
            return '55' . $semZero;
        }

        return null;
    }
}
