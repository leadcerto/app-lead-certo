<?php

namespace App\Services;

use App\Models\AuditoriaContato;
use App\Models\ChamadaPerdida;
use App\Models\Contato;
use App\Models\FormularioEnvio;
use App\Models\NotaContato;
use App\Models\TicketAtendimento;
use App\Models\VinculoContatoTenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Mescla dois registros de Contato que representam a mesma pessoa (mesmo
 * telefone em formatos diferentes, ou uma correção de auditoria que esbarrou
 * num contato já existente com o telefone certo). Move todo o histórico do
 * contato antigo para o canônico, enriquece campos vazios e remove o antigo
 * (soft delete). Usado tanto pelo comando `contatos:mesclar-duplicatas`
 * quanto pela resolução de auditoria de telefone.
 */
class ContatoMergeService
{
    public function mesclar(Contato $antigo, Contato $canonico): void
    {
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

            // 7. Enriquece canonico com dados do antigo (nao sobrescreve campos ja preenchidos)
            $campos  = ['nome', 'email', 'email_2', 'profissao', 'empresa', 'observacoes', 'foto_url', 'tags'];
            $updates = [];
            foreach ($campos as $campo) {
                $vazioNoCanonico = $campo === 'nome'
                    ? $this->semNomeReal($canonico)
                    : empty($canonico->{$campo});

                if ($vazioNoCanonico && ! empty($antigo->{$campo})) {
                    $updates[$campo] = $antigo->{$campo};
                }
            }
            if (! empty($updates)) {
                $canonico->update($updates);
            }

            // 8. Remove o contato antigo (soft delete)
            $antigo->delete();

            Log::info('ContatoMergeService: mesclado', [
                'antigo_id'    => $antigo->id,
                'antigo_tel'   => $antigo->telefone,
                'canonico_id'  => $canonico->id,
                'canonico_tel' => $canonico->telefone,
            ]);
        });
    }

    /**
     * "Sem Nome" é um placeholder, não um nome de verdade — trata como vazio
     * pra decidir se o nome do contato antigo deve substituir o do canônico.
     */
    private function semNomeReal(Contato $c): bool
    {
        $nome = trim((string) $c->nome);

        return $nome === '' || $nome === $c->telefone || mb_strtolower($nome) === 'sem nome';
    }
}
