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

    /**
     * Decide o que fazer com um contato SEM executar nada -- usado tanto
     * por validar() (que decide e executa) quanto pelo preview do
     * dry-run (que só quer saber o resultado, sem mutar nada).
     *
     * @return array{estado: string, acao: string, alvo: mixed, papel?: string}
     *   estado: 'lead_certo'|'lead_invalido'
     *   acao: 'nenhuma'|'mesclar'|'autocorrigir'
     *   alvo: Contato (quando acao='mesclar') | string telefone (quando acao='autocorrigir') | null
     *   papel: 'antigo'|'canonico' (quando acao='mesclar')
     */
    public function classificar(Contato $contato): array
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
                    // Duplicata tem id menor -> ela vira a canônica, e
                    // $contato (id maior) é quem tem o papel de "antigo"
                    // (é ele quem vai ser apagado na mesclagem).
                    return ['estado' => 'lead_certo', 'acao' => 'mesclar', 'alvo' => $duplicata, 'papel' => 'antigo'];
                } else {
                    return ['estado' => 'lead_certo', 'acao' => 'mesclar', 'alvo' => $duplicata, 'papel' => 'canonico'];
                }
            }

            return ['estado' => 'lead_certo', 'acao' => 'nenhuma', 'alvo' => null];
        }

        $candidatos = $this->reparo->candidatos($contato->telefone);

        if (empty($candidatos)) {
            return ['estado' => 'lead_invalido', 'acao' => 'nenhuma', 'alvo' => null];
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
            return ['estado' => 'lead_invalido', 'acao' => 'nenhuma', 'alvo' => null];
        }

        if ($paresDistintos->count() === 1) {
            return ['estado' => 'lead_certo', 'acao' => 'mesclar', 'alvo' => $paresDistintos->first(), 'papel' => 'antigo'];
        }

        // Nenhum par encontrado -- é o único registro desse número. Só
        // autocorrige se houver EXATAMENTE UM formato canônico possível
        // entre os candidatos -- mais de um formato distinto sem nenhum
        // par existente também é ambiguidade de verdade (não dá pra saber
        // qual formato é o certo), cai em lead_invalido também.
        $canonicos = collect($candidatos)->filter(fn ($c) => $this->reparo->ehCanonico($c))->unique()->values();

        if ($canonicos->count() === 1) {
            return ['estado' => 'lead_certo', 'acao' => 'autocorrigir', 'alvo' => $canonicos->first()];
        }

        return ['estado' => 'lead_invalido', 'acao' => 'nenhuma', 'alvo' => null];
    }

    public function validar(Contato $contato): string
    {
        $classificacao = $this->classificar($contato);

        match ($classificacao['acao']) {
            'mesclar' => ($classificacao['papel'] ?? 'antigo') === 'canonico'
                ? $this->merge->mesclar($classificacao['alvo'], $contato)
                : $this->merge->mesclar($contato, $classificacao['alvo']),
            'autocorrigir' => $contato->update(['telefone' => $classificacao['alvo']]),
            default => null,
        };

        return $classificacao['estado'];
    }
}
