<?php

namespace App\Console\Commands;

use App\Models\Contato;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class NormalizarTelefones extends Command
{
    protected $signature   = 'contatos:normalizar-telefones {--dry-run : Mostra o que seria feito sem alterar}';
    protected $description = 'Funde duplicados (com/sem 55) e normaliza telefones para o formato 55DDXXXXXXXX';

    public function handle(): int
    {
        $dry = $this->option('dry-run');
        $this->info($dry ? '[DRY-RUN] Nenhuma alteração será feita' : 'Iniciando...');

        // ── 1. Agrupa todos os contatos pelo número sem o prefixo 55 ───────────
        $this->info('Passo 1: Identificando duplicados...');

        // Inclui soft-deleted para resolver todos os conflitos de unicidade
        $grupos = DB::table('contatos')
            ->select(
                DB::raw("REGEXP_REPLACE(telefone, '^55', '') as tel_base"),
                DB::raw('COUNT(*) as total'),
                DB::raw('GROUP_CONCAT(id ORDER BY id ASC) as ids')
            )
            ->groupBy('tel_base')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        $this->info("  Grupos com duplicados: {$grupos->count()}");
        $fundidos = 0;
        $excluidos = 0;

        // ── 2. Para cada grupo: elege vencedor, migra dados, exclui perdedores ─
        foreach ($grupos as $grupo) {
            $ids      = explode(',', $grupo->ids);
            $contatos = Contato::withTrashed()->whereIn('id', $ids)->get()
                ->sortByDesc(fn($c) => ($c->deleted_at ? -1 : 0) + $this->pontuacao($c));

            $vencedor  = $contatos->first();
            $perdedores = $contatos->slice(1);

            $this->line("  [{$grupo->tel_base}] mantém #{$vencedor->id} ({$vencedor->nome}), remove: " .
                $perdedores->pluck('id')->join(', '));

            if (! $dry) {
                foreach ($perdedores as $p) {
                    // Migra vínculos (apenas os que o vencedor não tem)
                    $tenantsDono = DB::table('vinculos_contato_tenant')
                        ->where('contato_id', $vencedor->id)
                        ->pluck('tenant_id')->toArray();

                    DB::table('vinculos_contato_tenant')
                        ->where('contato_id', $p->id)
                        ->whereNotIn('tenant_id', $tenantsDono)
                        ->update(['contato_id' => $vencedor->id]);

                    DB::table('vinculos_contato_tenant')
                        ->where('contato_id', $p->id)
                        ->delete();

                    // Migra tickets, auditoria e notas
                    foreach (['tickets_atendimento', 'auditoria_contatos', 'notas_contato'] as $tabela) {
                        if (DB::getSchemaBuilder()->hasTable($tabela)) {
                            DB::table($tabela)
                                ->where('contato_id', $p->id)
                                ->update(['contato_id' => $vencedor->id]);
                        }
                    }

                    // Herda campos vazios do perdedor
                    $update = [];
                    foreach ($this->camposHerdaveis() as $campo) {
                        if (empty($vencedor->$campo) && ! empty($p->$campo)) {
                            $update[$campo] = $p->$campo;
                            $vencedor->$campo = $p->$campo;
                        }
                    }
                    if ($update) {
                        DB::table('contatos')->where('id', $vencedor->id)->update($update);
                    }

                    // Remove o duplicado definitivamente
                    DB::table('auditoria_contatos')->where('contato_id', $p->id)->delete();
                    DB::table('vinculos_contato_tenant')->where('contato_id', $p->id)->delete();
                    $p->forceDelete();
                    $excluidos++;
                }
            }

            $fundidos++;
        }

        $this->info("  Grupos fundidos: {$fundidos} | Contatos removidos: {$excluidos}");

        // ── 3. Normaliza os telefones sobreviventes para 55DDXXXXXXXX ──────────
        $this->info('Passo 2: Normalizando telefones...');

        $atualizados = 0;
        $invalidos   = 0;

        Contato::withTrashed()->chunkById(500, function ($contatos) use ($dry, &$atualizados, &$invalidos) {
            foreach ($contatos as $c) {
                $norm = self::normalizar($c->telefone);
                if ($norm === null) { $invalidos++; continue; }
                if ($norm === $c->telefone) continue;

                if (! $dry) {
                    // Se existir outro registro (inclusive soft-deleted) com o número normalizado,
                    // remove-o antes para liberar o slot único
                    DB::table('contatos')
                        ->where('id', '!=', $c->id)
                        ->where('telefone', $norm)
                        ->delete(); // força exclusão (bypassa softDeletes)

                    DB::table('contatos')->where('id', $c->id)->update(['telefone' => $norm]);
                }

                $this->line("  [{$c->id}] {$c->telefone} → {$norm}");
                $atualizados++;
            }
        });

        $this->info("  Atualizados: {$atualizados} | Inválidos/ignorados: {$invalidos}");
        $this->info($dry ? 'DRY-RUN concluído.' : 'Tudo pronto!');

        return Command::SUCCESS;
    }

    public static function normalizar(?string $raw): ?string
    {
        if (! $raw) return null;

        $digits = preg_replace('/\D/', '', $raw);
        if (strlen($digits) < 8) return null;

        // Remove o DDI 55 se já presente para trabalhar com o número local
        $local = str_starts_with($digits, '55') && strlen($digits) >= 12
            ? substr($digits, 2)
            : $digits;

        // Valida: DDD (2) + número (8 ou 9) = 10 ou 11 dígitos
        if (strlen($local) < 10 || strlen($local) > 11) return null;

        return '55' . $local;
    }

    private function pontuacao(Contato $c): int
    {
        $score = 0;
        foreach ($this->camposHerdaveis() as $campo) {
            if (! empty($c->$campo) && $c->$campo !== $c->telefone) $score++;
        }
        return $score;
    }

    private function camposHerdaveis(): array
    {
        return [
            'nome','email','email_2','profissao','empresa','departamento',
            'endereco','cidade','estado','cep','pais',
            'instagram','facebook','linkedin','twitter','website','observacoes',
            'genero','estado_civil','aniversario','cpf','rg',
        ];
    }
}
