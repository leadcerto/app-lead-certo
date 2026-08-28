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
            $duplicata = Contato::where('telefone', $contato->telefone)
                ->where('id', '!=', $contato->id)
                ->orderBy('id')
                ->first();

            if ($duplicata) {
                // Telefone EXATO igual não é ambíguo -- os dois
                // representam o mesmo número real. O de menor id vira o
                // canônico, o outro é mesclado nele.
                if ($duplicata->id < $contato->id) {
                    $this->merge->mesclar($contato, $duplicata);
                } else {
                    $this->merge->mesclar($duplicata, $contato);
                }
            }

            return 'lead_certo';
        }

        $candidatos = $this->reparo->candidatos($contato->telefone);

        if (empty($candidatos)) {
            return 'lead_invalido';
        }

        // Verifica TODOS os candidatos, não só o primeiro -- se mais de um
        // registro EXISTENTE distinto bater (via candidatos diferentes),
        // é ambiguidade real: não dá pra saber qual é a mesma pessoa. Cai
        // em lead_invalido em vez de mesclar no primeiro que aparecer.
        $paresEncontrados = collect();
        foreach ($candidatos as $candidato) {
            if ($candidato === $contato->telefone) {
                continue;
            }

            $par = Contato::where('telefone', $candidato)
                ->where('id', '!=', $contato->id)
                ->first();

            if ($par) {
                $paresEncontrados->push($par);
            }
        }

        $paresDistintos = $paresEncontrados->unique('id');

        if ($paresDistintos->count() > 1) {
            return 'lead_invalido';
        }

        if ($paresDistintos->count() === 1) {
            $this->merge->mesclar($contato, $paresDistintos->first());

            return 'lead_certo';
        }

        // Nenhum par encontrado -- é o único registro desse número. Só
        // autocorrige se houver EXATAMENTE UM formato canônico possível
        // entre os candidatos -- mais de um formato distinto sem nenhum
        // par existente também é ambiguidade de verdade (não dá pra saber
        // qual formato é o certo), cai em lead_invalido também.
        $canonicos = collect($candidatos)->filter(fn ($c) => $this->reparo->ehCanonico($c))->unique()->values();

        if ($canonicos->count() === 1) {
            $contato->update(['telefone' => $canonicos->first()]);

            return 'lead_certo';
        }

        return 'lead_invalido';
    }
}
