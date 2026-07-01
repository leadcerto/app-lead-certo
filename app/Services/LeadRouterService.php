<?php

namespace App\Services;

use App\Models\SdrPersona;

class LeadRouterService
{
    /**
     * Escolhe a melhor persona para o lead com base nas tags de afinidade.
     *
     * @param  int   $tenantId
     * @param  array $tagsLead  ex: ['atende_b2b', 'atende_rj', 'atende_pj']
     * @return SdrPersona|null
     */
    public function rotear(int $tenantId, array $tagsLead): ?SdrPersona
    {
        $personas = SdrPersona::with('regras')
            ->where('tenant_id', $tenantId)
            ->where('ativo', true)
            ->get();

        if ($personas->isEmpty()) {
            return null;
        }

        $melhorPersona = null;
        $melhorPontuacao = -1;

        foreach ($personas as $persona) {
            $pontuacao = $this->calcularPontuacao($persona->regras->toArray(), $tagsLead);

            if ($pontuacao > $melhorPontuacao) {
                $melhorPontuacao = $pontuacao;
                $melhorPersona  = $persona;
            }
        }

        // Se nenhuma tag bateu, usa a persona default
        if ($melhorPontuacao === 0) {
            $default = $personas->firstWhere('is_default', true);
            return $default ?? $personas->first();
        }

        return $melhorPersona;
    }

    /**
     * Soma os pesos das tags da persona que estão presentes nas tags do lead.
     * Algoritmo determinístico — sem IA.
     */
    private function calcularPontuacao(array $regras, array $tagsLead): int
    {
        $pontuacao = 0;

        foreach ($regras as $regra) {
            if (in_array($regra['tag'], $tagsLead, true)) {
                $pontuacao += (int) $regra['peso'];
            }
        }

        return $pontuacao;
    }
}
