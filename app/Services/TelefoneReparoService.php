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
    ];

    public function __construct(private TelefoneService $telefoneService) {}

    public function ehCanonico(string $telefone): bool
    {
        if (preg_match('/^55\d{2}9\d{8}$/', $telefone) && $this->dddValidoNoCandidato($telefone)) {
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
     * Extrai o DDD (posições 2-3) de um candidato no formato canônico
     * 55DDXXXXXXXXX e valida contra a lista real de DDDs brasileiros
     * (TelefoneService::DDDS_VALIDOS) — sem isso, um número com DDD
     * inexistente (ex: '20') passava como candidato válido só por bater
     * no formato regex.
     */
    private function dddValidoNoCandidato(string $candidato13): bool
    {
        $ddd = (int) substr($candidato13, 2, 2);

        return $this->telefoneService->dddValido($ddd);
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
            $candidato = substr($telefone, 0, 4) . '9' . substr($telefone, 4);
            if ($this->dddValidoNoCandidato($candidato)) {
                $candidatos[] = $candidato;
            }
        }

        // 11 dígitos, DD + 9 dígitos começando 9 (celular sem o 55)
        if (strlen($telefone) === 11 && preg_match('/^\d{2}9/', $telefone)) {
            $candidato = '55' . $telefone;
            if ($this->dddValidoNoCandidato($candidato)) {
                $candidatos[] = $candidato;
            }
        }

        // 10 dígitos, DD + 8 dígitos começando 6/7/8/9 (sem 55 E sem o 9)
        if (strlen($telefone) === 10 && preg_match('/^\d{2}[6789]/', $telefone)) {
            $comNove = substr($telefone, 0, 2) . '9' . substr($telefone, 2);
            $candidato = '55' . $comNove;
            if ($this->dddValidoNoCandidato($candidato)) {
                $candidatos[] = $candidato;
            }
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
        if (strlen($telefone) >= 13 && str_starts_with($telefone, '55')) {
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
