<?php

namespace App\Services;

class PaisTelefoneService
{
    /**
     * Lista completa dos principais países com bandeira, DDI e ISO.
     */
    public const PAISES = [
        ['iso' => 'BR', 'nome' => 'Brasil', 'ddi' => '55', 'bandeira' => '🇧🇷', 'mascara' => '(DD) 9XXXX-XXXX'],
        ['iso' => 'US', 'nome' => 'Estados Unidos / Canadá', 'ddi' => '1', 'bandeira' => '🇺🇸', 'mascara' => '(XXX) XXX-XXXX'],
        ['iso' => 'PT', 'nome' => 'Portugal', 'ddi' => '351', 'bandeira' => '🇵🇹', 'mascara' => '9XX XXX XXX'],
        ['iso' => 'MX', 'nome' => 'México', 'ddi' => '52', 'bandeira' => '🇲🇽', 'mascara' => 'XX XXXX XXXX'],
        ['iso' => 'AR', 'nome' => 'Argentina', 'ddi' => '54', 'bandeira' => '🇦🇷', 'mascara' => '9 XX XXXX-XXXX'],
        ['iso' => 'ES', 'nome' => 'Espanha', 'ddi' => '34', 'bandeira' => '🇪🇸', 'mascara' => '6XX XXX XXX'],
        ['iso' => 'GB', 'nome' => 'Reino Unido', 'ddi' => '44', 'bandeira' => '🇬🇧', 'mascara' => '7XXX XXXXXX'],
        ['iso' => 'IT', 'nome' => 'Itália', 'ddi' => '39', 'bandeira' => '🇮🇹', 'mascara' => '3XX XXXXXXX'],
        ['iso' => 'FR', 'nome' => 'França', 'ddi' => '33', 'bandeira' => '🇫🇷', 'mascara' => '6 XX XX XX XX'],
        ['iso' => 'DE', 'nome' => 'Alemanha', 'ddi' => '49', 'bandeira' => '🇩🇪', 'mascara' => '1XX XXXXXXXX'],
        ['iso' => 'CL', 'nome' => 'Chile', 'ddi' => '56', 'bandeira' => '🇨🇱', 'mascara' => '9 XXXX XXXX'],
        ['iso' => 'CO', 'nome' => 'Colômbia', 'ddi' => '57', 'bandeira' => '🇨🇴', 'mascara' => '3XX XXX XXXX'],
        ['iso' => 'PY', 'nome' => 'Paraguai', 'ddi' => '595', 'bandeira' => '🇵🇾', 'mascara' => '9XX XXXXXX'],
        ['iso' => 'UY', 'nome' => 'Uruguai', 'ddi' => '598', 'bandeira' => '🇺🇾', 'mascara' => '9XX XXX XXX'],
        ['iso' => 'PE', 'nome' => 'Peru', 'ddi' => '51', 'bandeira' => '🇵🇪', 'mascara' => '9XX XXX XXX'],
        ['iso' => 'VE', 'nome' => 'Venezuela', 'ddi' => '58', 'bandeira' => '🇻🇪', 'mascara' => '4XX XXX XXXX'],
        ['iso' => 'EC', 'nome' => 'Equador', 'ddi' => '593', 'bandeira' => '🇪🇨', 'mascara' => '9X XXX XXXX'],
        ['iso' => 'BO', 'nome' => 'Bolívia', 'ddi' => '591', 'bandeira' => '🇧🇴', 'mascara' => '7XX XXXXX'],
        ['iso' => 'AO', 'nome' => 'Angola', 'ddi' => '244', 'bandeira' => '🇦🇴', 'mascara' => '9XX XXX XXX'],
        ['iso' => 'MZ', 'nome' => 'Moçambique', 'ddi' => '258', 'bandeira' => '🇲🇿', 'mascara' => '8X XXX XXXX'],
        ['iso' => 'JP', 'nome' => 'Japão', 'ddi' => '81', 'bandeira' => '🇯🇵', 'mascara' => 'XX XXXX XXXX'],
        ['iso' => 'CN', 'nome' => 'China', 'ddi' => '86', 'bandeira' => '🇨🇳', 'mascara' => '1XX XXXX XXXX'],
        ['iso' => 'IN', 'nome' => 'Índia', 'ddi' => '91', 'bandeira' => '🇮🇳', 'mascara' => 'XXXXX XXXXX'],
        ['iso' => 'AU', 'nome' => 'Austrália', 'ddi' => '61', 'bandeira' => '🇦🇺', 'mascara' => '4XX XXX XXX'],
        ['iso' => 'ZA', 'nome' => 'África do Sul', 'ddi' => '27', 'bandeira' => '🇿🇦', 'mascara' => 'XX XXX XXXX'],
        ['iso' => 'IE', 'nome' => 'Irlanda', 'ddi' => '353', 'bandeira' => '🇮🇪', 'mascara' => '8X XXX XXXX'],
        ['iso' => 'CH', 'nome' => 'Suíça', 'ddi' => '41', 'bandeira' => '🇨🇭', 'mascara' => '7X XXX XX XX'],
        ['iso' => 'BE', 'nome' => 'Bélgica', 'ddi' => '32', 'bandeira' => '🇧🇪', 'mascara' => '4XX XX XX XX'],
        ['iso' => 'NL', 'nome' => 'Holanda', 'ddi' => '31', 'bandeira' => '🇳🇱', 'mascara' => '6 XXXXXXXX'],
        ['iso' => 'SE', 'nome' => 'Suécia', 'ddi' => '46', 'bandeira' => '🇸🇪', 'mascara' => '7X XXX XX XX'],
        ['iso' => 'NO', 'nome' => 'Noruega', 'ddi' => '47', 'bandeira' => '🇳🇴', 'mascara' => 'XXX XX XXX'],
        ['iso' => 'DK', 'nome' => 'Dinamarca', 'ddi' => '45', 'bandeira' => '🇩🇰', 'mascara' => 'XX XX XX XX'],
        ['iso' => 'IL', 'nome' => 'Israel', 'ddi' => '972', 'bandeira' => '🇮🇱', 'mascara' => '5X XXX XXXX'],
        ['iso' => 'AE', 'nome' => 'Emirados Árabes', 'ddi' => '971', 'bandeira' => '🇦🇪', 'mascara' => '5X XXX XXXX'],
        ['iso' => 'SA', 'nome' => 'Arábia Saudita', 'ddi' => '966', 'bandeira' => '🇸🇦', 'mascara' => '5X XXX XXXX'],
        ['iso' => 'RU', 'nome' => 'Rússia', 'ddi' => '7', 'bandeira' => '🇷🇺', 'mascara' => 'XXX XXX-XX-XX'],
    ];

    /**
     * Identifica o país de um número de telefone e retorna os dados formatados com bandeira.
     */
    public static function identificarPais(?string $telefone): array
    {
        if (empty($telefone)) {
            return [
                'iso'          => 'BR',
                'nome'         => 'Brasil',
                'bandeira'     => '🇧🇷',
                'ddi'          => '55',
                'numero_limpo' => '',
                'numero_local' => '',
                'formatado'    => '—',
                'exibicao'     => '🇧🇷 (Sem telefone)',
            ];
        }

        $digitos = preg_replace('/\D/', '', $telefone);
        if (empty($digitos)) {
            return [
                'iso'          => 'BR',
                'nome'         => 'Brasil',
                'bandeira'     => '🇧🇷',
                'ddi'          => '55',
                'numero_limpo' => '',
                'numero_local' => '',
                'formatado'    => $telefone,
                'exibicao'     => $telefone,
            ];
        }

        // Remove prefixo 00 internacional
        $semZeros = preg_replace('/^00+/', '', $digitos);

        // 1. Se for Brasil (Começa com 55 e tem 12 ou 13 dígitos, ou 10/11 dígitos locais brasileiros)
        if ((str_starts_with($semZeros, '55') && (strlen($semZeros) === 12 || strlen($semZeros) === 13)) ||
            (strlen($semZeros) === 10 || strlen($semZeros) === 11)) {
            
            $local = str_starts_with($semZeros, '55') && strlen($semZeros) >= 12 ? substr($semZeros, 2) : $semZeros;
            $ddd = substr($local, 0, 2);
            $resto = substr($local, 2);

            $formatado = (strlen($resto) === 9)
                ? "+55 ({$ddd}) " . substr($resto, 0, 5) . '-' . substr($resto, 5)
                : "+55 ({$ddd}) " . substr($resto, 0, 4) . '-' . substr($resto, 4);

            return [
                'iso'          => 'BR',
                'nome'         => 'Brasil',
                'bandeira'     => '🇧🇷',
                'ddi'          => '55',
                'numero_limpo' => '55' . $local,
                'numero_local' => $local,
                'formatado'    => $formatado,
                'exibicao'     => "🇧🇷 {$formatado}",
            ];
        }

        // 2. Busca entre os países cadastrados ordenando por DDI mais longo primeiro
        $paisesOrdenados = self::PAISES;
        usort($paisesOrdenados, fn($a, $b) => strlen($b['ddi']) <=> strlen($a['ddi']));

        foreach ($paisesOrdenados as $p) {
            if ($p['ddi'] === '55') continue; // Brasil já tratado acima

            if (str_starts_with($semZeros, $p['ddi'])) {
                $local = substr($semZeros, strlen($p['ddi']));
                // Se sobrou uma quantidade razoável de dígitos (4 a 12 dígitos)
                if (strlen($local) >= 4 && strlen($local) <= 12) {
                    $formatado = "+{$p['ddi']} " . self::formatarGenerico($local);
                    return [
                        'iso'          => $p['iso'],
                        'nome'         => $p['nome'],
                        'bandeira'     => $p['bandeira'],
                        'ddi'          => $p['ddi'],
                        'numero_limpo' => $p['ddi'] . $local,
                        'numero_local' => $local,
                        'formatado'    => $formatado,
                        'exibicao'     => "{$p['bandeira']} {$formatado}",
                    ];
                }
            }
        }

        // 3. Fallback: Se não encontrou país específico, exibe como internacional genérico
        $formatado = '+' . $semZeros;
        return [
            'iso'          => 'OUTRO',
            'nome'         => 'Internacional',
            'bandeira'     => '🌐',
            'ddi'          => '',
            'numero_limpo' => $semZeros,
            'numero_local' => $semZeros,
            'formatado'    => $formatado,
            'exibicao'     => "🌐 {$formatado}",
        ];
    }

    /**
     * Formata um número genérico em blocos de 3 a 4 dígitos para fácil leitura.
     */
    private static function formatarGenerico(string $num): string
    {
        $len = strlen($num);
        if ($len <= 4) return $num;
        if ($len <= 7) return substr($num, 0, 3) . ' ' . substr($num, 3);
        if ($len <= 9) return substr($num, 0, 3) . ' ' . substr($num, 3, 3) . ' ' . substr($num, 6);
        return substr($num, 0, 3) . ' ' . substr($num, 3, 4) . ' ' . substr($num, 7);
    }
}
