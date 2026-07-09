<?php

namespace App\Services;

/**
 * Normaliza e valida números de telefone para o padrão internacional brasileiro.
 *
 * Formato alvo: 55 + DDD (2 dígitos) + número (8 ou 9 dígitos)
 * Exemplos válidos:
 *   5521999999999  (celular RJ — 13 dígitos)
 *   552133333333   (fixo RJ — 12 dígitos)
 *
 * Retorno de normalizar():
 *   string  — número normalizado (12 ou 13 dígitos) se conseguiu tratar
 *   null    — impossível normalizar (número inválido / irreparável)
 */
class TelefoneService
{
    // DDDs válidos do Brasil
    private const DDDS_VALIDOS = [
        11, 12, 13, 14, 15, 16, 17, 18, 19, // SP
        21, 22, 24,                           // RJ / ES Norte
        27, 28,                               // ES
        31, 32, 33, 34, 35, 37, 38,           // MG
        41, 42, 43, 44, 45, 46,               // PR
        47, 48, 49,                           // SC
        51, 53, 54, 55,                       // RS
        61,                                   // DF / GO Centro
        62, 64,                               // GO
        63,                                   // TO
        65, 66,                               // MT
        67,                                   // MS
        68,                                   // AC
        69,                                   // RO
        71, 73, 74, 75, 77,                   // BA
        79,                                   // SE
        81, 87,                               // PE
        82,                                   // AL
        83,                                   // PB
        84,                                   // RN
        85, 88,                               // CE
        86, 89,                               // PI
        91, 93, 94,                           // PA
        92, 97,                               // AM
        95,                                   // RR
        96,                                   // AP
        98, 99,                               // MA
    ];

    /**
     * Normaliza um número de telefone para o formato 55DDXXXXXXXX(X).
     * Retorna null se o número for inválido e não puder ser corrigido.
     */
    public function normalizar(string $raw): ?string
    {
        // 1. Remove tudo que não é dígito
        $digitos = preg_replace('/\D/', '', $raw);

        if (empty($digitos)) {
            return null;
        }

        // 2. Remove prefixo de discagem internacional duplicado (0055, 00055...)
        $digitos = preg_replace('/^00+55/', '55', $digitos);

        // 3. Remove o '+' que já foi tratado acima, mas normaliza 0055 → 55
        // (já feito acima)

        // 4. Comprimento base sem DDI
        $semDdi = $digitos;
        if (str_starts_with($digitos, '55') && strlen($digitos) >= 12) {
            $semDdi = substr($digitos, 2); // remove o 55
        }

        // 5. Remove zero de discagem nacional antigo (0 + DDD + número)
        if (str_starts_with($semDdi, '0') && strlen($semDdi) >= 11) {
            $semDdi = substr($semDdi, 1);
        }

        // 6. Validar comprimento: deve ser 10 (fixo sem 9) ou 11 (celular com 9)
        $len = strlen($semDdi);

        if ($len === 10 || $len === 11) {
            $ddd    = (int) substr($semDdi, 0, 2);
            $numero = substr($semDdi, 2);

            if (! $this->dddValido($ddd)) {
                return null;
            }

            // Celular de 9 dígitos que não começa com 9 → adiciona o 9
            if (strlen($numero) === 8 && in_array(substr($numero, 0, 1), ['6','7','8','9'])) {
                $numero = '9' . $numero;
            }

            return '55' . str_pad((string) $ddd, 2, '0', STR_PAD_LEFT) . $numero;
        }

        // 7. Comprimentos anômalos: tenta recuperar removendo dígitos duplicados de prefixo
        if ($len === 12) {
            // Possível: DDD duplicado (ex: 021 21 99999999 → 21 99999999)
            $ddd1 = (int) substr($semDdi, 0, 2);
            $ddd2 = (int) substr($semDdi, 2, 2);
            if ($ddd1 === $ddd2 && $this->dddValido($ddd1)) {
                $numero = substr($semDdi, 4); // 8 dígitos
                return '55' . str_pad((string) $ddd1, 2, '0', STR_PAD_LEFT) . '9' . $numero;
            }
        }

        // Não conseguiu normalizar
        return null;
    }

    /**
     * Valida se um número já normalizado está no formato correto.
     */
    public function valido(string $numero): bool
    {
        if (! preg_match('/^55\d{10,11}$/', $numero)) {
            return false;
        }
        $ddd = (int) substr($numero, 2, 2);
        return $this->dddValido($ddd);
    }

    /**
     * Retorna diagnóstico do número: 'valido', 'corrigido', 'invalido'.
     */
    public function diagnosticar(string $raw): array
    {
        $digitos     = preg_replace('/\D/', '', $raw);
        $normalizado = $this->normalizar($raw);

        if ($normalizado === null) {
            return [
                'status'      => 'invalido',
                'original'    => $raw,
                'normalizado' => null,
                'motivo'      => $this->motivoInvalido($digitos),
            ];
        }

        $foiCorrigido = $normalizado !== $digitos;

        return [
            'status'      => $foiCorrigido ? 'corrigido' : 'valido',
            'original'    => $raw,
            'normalizado' => $normalizado,
            'motivo'      => null,
        ];
    }

    private function dddValido(int $ddd): bool
    {
        return in_array($ddd, self::DDDS_VALIDOS);
    }

    private function motivoInvalido(string $digitos): string
    {
        $len = strlen($digitos);
        if ($len < 8)  return "Muito curto ({$len} dígitos)";
        if ($len > 14) return "Muito longo ({$len} dígitos)";

        $semDdi = str_starts_with($digitos, '55') ? substr($digitos, 2) : $digitos;
        $ddd    = (int) substr($semDdi, 0, 2);
        if (! $this->dddValido($ddd)) {
            return "DDD inválido: {$ddd}";
        }

        return "Formato não reconhecido ({$len} dígitos)";
    }
}
