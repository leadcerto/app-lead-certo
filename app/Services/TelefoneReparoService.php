<?php

namespace App\Services;

/**
 * Produz candidatos EXATOS de correção pra um telefone malformado — nunca
 * por semelhança/sufixo, só reparo de formato conhecido. Ver spec seção 4
 * (docs/superpowers/specs/2026-08-28-validacao-sincronizacao-contatos-design.md).
 *
 * Telefone é a ÚNICA chave de deduplicação do sistema — nome nunca entra
 * nessa decisão (princípio do Leonardo, 2026-08-28).
 */
class TelefoneReparoService
{
    /**
     * Códigos de país reconhecidos além do Brasil (55), com o tamanho total
     * esperado do número (código + número nacional). Lista enxuta dos
     * países com contatos reais confirmados na base do Frete Rio — pode
     * crescer conforme aparecerem novos.
     */
    private const PAISES_RECONHECIDOS = [
        '351' => [12],       // Portugal
        '44'  => [12, 13],   // Reino Unido
        '39'  => [12, 13],   // Itália
        '49'  => [12, 13, 14], // Alemanha
        '52'  => [12, 13],   // México
        '54'  => [12, 13],   // Argentina
        '34'  => [11, 12],   // Espanha
        '1'   => [11],       // EUA/Canadá
        '33'  => [11, 12],   // França
    ];

    public function ehCanonico(string $telefone): bool
    {
        if (preg_match('/^55\d{2}9\d{8}$/', $telefone)) {
            return true;
        }

        foreach (self::PAISES_RECONHECIDOS as $codigo => $tamanhos) {
            if (str_starts_with($telefone, $codigo) && in_array(strlen($telefone), $tamanhos, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return string[] candidatos exatos de telefone corrigido, únicos.
     * Vazio quando nenhuma regra conhecida produz um candidato.
     */
    public function candidatos(string $telefone): array
    {
        $candidatos = [];

        if ($this->ehCanonico($telefone)) {
            $candidatos[] = $telefone;
        }

        // 12 dígitos, 55 + DD + 8 dígitos começando 6/7/8/9 (celular sem o 9)
        if (strlen($telefone) === 12 && preg_match('/^55\d{2}[6789]/', $telefone)) {
            $candidatos[] = substr($telefone, 0, 4) . '9' . substr($telefone, 4);
        }

        // 11 dígitos, DD + 9 dígitos começando 9 (celular sem o 55)
        if (strlen($telefone) === 11 && preg_match('/^\d{2}9/', $telefone)) {
            $candidatos[] = '55' . $telefone;
        }

        // 10 dígitos, DD + 8 dígitos começando 6/7/8/9 (sem 55 E sem o 9)
        if (strlen($telefone) === 10 && preg_match('/^\d{2}[6789]/', $telefone)) {
            $comNove = substr($telefone, 0, 2) . '9' . substr($telefone, 2);
            $candidatos[] = '55' . $comNove;
        }

        // Prefixo "0" espúrio — remove e tenta os padrões de novo no resultado
        if (str_starts_with($telefone, '0') && strlen($telefone) > 1) {
            foreach ($this->candidatos(substr($telefone, 1)) as $c) {
                $candidatos[] = $c;
            }
        }

        // "55" duplicado — remove um 55 da frente e tenta de novo, mas só
        // quando sobra algo plausível (pelo menos 10 dígitos, senão vira
        // ruído de regex em número curto demais)
        if (strlen($telefone) > 13 && str_starts_with($telefone, '55')) {
            $semDuplicata = substr($telefone, 2);
            if (strlen($semDuplicata) >= 10) {
                foreach ($this->candidatos($semDuplicata) as $c) {
                    $candidatos[] = $c;
                }
            }
        }

        return array_values(array_unique($candidatos));
    }
}
