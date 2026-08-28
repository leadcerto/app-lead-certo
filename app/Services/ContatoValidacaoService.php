<?php

namespace App\Services;

use App\Models\Contato;

/**
 * Decide o estado de validação de um contato (spec seção 5, fluxo de
 * decisão): telefone canônico e único -> lead_certo; malformado com
 * candidato exato batendo outro registro -> mescla (ContatoMergeService)
 * e o sobrevivente vira lead_certo; malformado sem par -> autocorrige o
 * próprio registro -> lead_certo; nenhum candidato -> lead_invalido.
 *
 * Nunca decide por nome — só telefone (princípio do Leonardo, 2026-08-28).
 */
class ContatoValidacaoService
{
    public function __construct(
        private TelefoneReparoService $reparo,
        private ContatoMergeService $merge,
    ) {}

    public function validar(Contato $contato): string
    {
        if ($this->reparo->ehCanonico($contato->telefone)) {
            return 'lead_certo';
        }

        $candidatos = $this->reparo->candidatos($contato->telefone);

        if (empty($candidatos)) {
            return 'lead_invalido';
        }

        foreach ($candidatos as $candidato) {
            if ($candidato === $contato->telefone) {
                continue;
            }

            $par = Contato::where('telefone', $candidato)
                ->where('id', '!=', $contato->id)
                ->first();

            if ($par) {
                $this->merge->mesclar($contato, $par);

                return 'lead_certo';
            }
        }

        $canonico = collect($candidatos)->first(fn ($c) => $this->reparo->ehCanonico($c));

        if ($canonico) {
            $contato->update(['telefone' => $canonico]);

            return 'lead_certo';
        }

        return 'lead_invalido';
    }
}
