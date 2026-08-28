<?php

use App\Models\Contato;
use App\Models\VinculoContatoTenant;
use Illuminate\Database\Migrations\Migration;

/**
 * Design: docs/superpowers/specs/2026-08-26-sync-bidirecional-google-contatos-design.md
 * seção 8. Sem isso, o primeiro pull (Task 3) depois do deploy trataria todo
 * campo local já preenchido como "não-humano" e poderia sobrescrever dado
 * real com o que estiver no Google na hora. Marca como humano por segurança
 * — só entra na regra nova a partir daqui pra frente.
 */
return new class extends Migration
{
    private const CAMPOS = ['nome', 'sobrenome', 'empresa', 'email'];

    public function up(): void
    {
        VinculoContatoTenant::with('contato')
            ->whereNull('campos_editados_humano')
            ->chunkById(200, function ($vinculos) {
                foreach ($vinculos as $vinculo) {
                    $contato = $vinculo->contato;
                    if (! $contato) {
                        continue;
                    }

                    $campos = [];
                    foreach (self::CAMPOS as $campo) {
                        $valor = $contato->$campo;
                        if (empty($valor)) {
                            continue;
                        }
                        if ($campo === 'nome' && $contato->semNomeReal()) {
                            continue;
                        }
                        $campos[$campo] = now()->toIso8601String();
                    }

                    if ($campos) {
                        $vinculo->update(['campos_editados_humano' => $campos]);
                    }
                }
            });
    }

    public function down(): void
    {
        // Backfill de dados — não há "desfazer" seguro sem saber quais linhas
        // tinham o campo preenchido antes de rodar. Intencionalmente vazio.
    }
};
