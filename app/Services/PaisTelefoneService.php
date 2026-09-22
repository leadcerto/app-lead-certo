<?php

namespace App\Services;

class PaisTelefoneService
{
    /**
     * Lista completa de países com bandeira, DDI e ISO — cobertura equivalente
     * ao dropdown do Google Contatos (~195 países, ITU-T E.164). Achado real
     * 2026-09-21 (pedido do Leonardo): antes cobria só 37 países.
     *
     * 'mascara' é só decorativo (nenhum código em identificarPais() lê esse
     * campo — a formatação real sempre passa por formatarGenerico()), então
     * os países menos comuns têm uma máscara aproximada, não o formato
     * nacional exato — isso não afeta o comportamento do sistema.
     *
     * Limitação conhecida e intencional: países do NANP (América do Norte/
     * Caribe — Canadá, Jamaica, Bahamas, Trinidad e Tobago, Porto Rico etc.)
     * todos usam DDI +1 e só são distinguíveis pelo código de área (3 dígitos
     * seguintes), não pelo DDI isolado. Mantido só 'US' como entrada
     * genérica pra +1 (mesmo comportamento de antes) — adicionar os outros
     * separadamente criaria matches ambíguos em identificarPais(). Mesma
     * lógica pra +7 (Rússia/Cazaquistão): mantido só Rússia.
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
        ['iso' => 'TN', 'nome' => 'Tunísia', 'ddi' => '216', 'bandeira' => '🇹🇳', 'mascara' => 'XX XXX XXX'],
        ['iso' => 'GM', 'nome' => 'Gâmbia', 'ddi' => '220', 'bandeira' => '🇬🇲', 'mascara' => 'XXX XXXX'],

        // ── Europa (restante) ────────────────────────────────────────────────
        ['iso' => 'AL', 'nome' => 'Albânia', 'ddi' => '355', 'bandeira' => '🇦🇱', 'mascara' => 'XX XXX XXX'],
        ['iso' => 'AD', 'nome' => 'Andorra', 'ddi' => '376', 'bandeira' => '🇦🇩', 'mascara' => 'XXX XXX'],
        ['iso' => 'AT', 'nome' => 'Áustria', 'ddi' => '43', 'bandeira' => '🇦🇹', 'mascara' => 'XXX XXXXXXX'],
        ['iso' => 'BY', 'nome' => 'Bielorrússia', 'ddi' => '375', 'bandeira' => '🇧🇾', 'mascara' => 'XX XXX XX XX'],
        ['iso' => 'BA', 'nome' => 'Bósnia e Herzegovina', 'ddi' => '387', 'bandeira' => '🇧🇦', 'mascara' => 'XX XXX XXX'],
        ['iso' => 'BG', 'nome' => 'Bulgária', 'ddi' => '359', 'bandeira' => '🇧🇬', 'mascara' => 'XX XXX XXXX'],
        ['iso' => 'HR', 'nome' => 'Croácia', 'ddi' => '385', 'bandeira' => '🇭🇷', 'mascara' => 'XX XXX XXXX'],
        ['iso' => 'CY', 'nome' => 'Chipre', 'ddi' => '357', 'bandeira' => '🇨🇾', 'mascara' => 'XX XXXXXX'],
        ['iso' => 'CZ', 'nome' => 'República Tcheca', 'ddi' => '420', 'bandeira' => '🇨🇿', 'mascara' => 'XXX XXX XXX'],
        ['iso' => 'EE', 'nome' => 'Estônia', 'ddi' => '372', 'bandeira' => '🇪🇪', 'mascara' => 'XXXX XXXX'],
        ['iso' => 'FI', 'nome' => 'Finlândia', 'ddi' => '358', 'bandeira' => '🇫🇮', 'mascara' => 'XX XXX XXXX'],
        ['iso' => 'GR', 'nome' => 'Grécia', 'ddi' => '30', 'bandeira' => '🇬🇷', 'mascara' => 'XXX XXX XXXX'],
        ['iso' => 'HU', 'nome' => 'Hungria', 'ddi' => '36', 'bandeira' => '🇭🇺', 'mascara' => 'XX XXX XXXX'],
        ['iso' => 'IS', 'nome' => 'Islândia', 'ddi' => '354', 'bandeira' => '🇮🇸', 'mascara' => 'XXX XXXX'],
        ['iso' => 'XK', 'nome' => 'Kosovo', 'ddi' => '383', 'bandeira' => '🇽🇰', 'mascara' => 'XX XXX XXX'],
        ['iso' => 'LV', 'nome' => 'Letônia', 'ddi' => '371', 'bandeira' => '🇱🇻', 'mascara' => 'XX XXX XXX'],
        ['iso' => 'LI', 'nome' => 'Liechtenstein', 'ddi' => '423', 'bandeira' => '🇱🇮', 'mascara' => 'XXX XXXX'],
        ['iso' => 'LT', 'nome' => 'Lituânia', 'ddi' => '370', 'bandeira' => '🇱🇹', 'mascara' => 'XXX XXXXX'],
        ['iso' => 'LU', 'nome' => 'Luxemburgo', 'ddi' => '352', 'bandeira' => '🇱🇺', 'mascara' => 'XXX XXX XXX'],
        ['iso' => 'MT', 'nome' => 'Malta', 'ddi' => '356', 'bandeira' => '🇲🇹', 'mascara' => 'XXXX XXXX'],
        ['iso' => 'MD', 'nome' => 'Moldávia', 'ddi' => '373', 'bandeira' => '🇲🇩', 'mascara' => 'XXXX XXXX'],
        ['iso' => 'MC', 'nome' => 'Mônaco', 'ddi' => '377', 'bandeira' => '🇲🇨', 'mascara' => 'XX XX XX XX'],
        ['iso' => 'ME', 'nome' => 'Montenegro', 'ddi' => '382', 'bandeira' => '🇲🇪', 'mascara' => 'XX XXX XXX'],
        ['iso' => 'MK', 'nome' => 'Macedônia do Norte', 'ddi' => '389', 'bandeira' => '🇲🇰', 'mascara' => 'XX XXX XXX'],
        ['iso' => 'PL', 'nome' => 'Polônia', 'ddi' => '48', 'bandeira' => '🇵🇱', 'mascara' => 'XXX XXX XXX'],
        ['iso' => 'RO', 'nome' => 'Romênia', 'ddi' => '40', 'bandeira' => '🇷🇴', 'mascara' => 'XXX XXX XXX'],
        ['iso' => 'RU', 'nome' => 'Rússia', 'ddi' => '7', 'bandeira' => '🇷🇺', 'mascara' => 'XXX XXX XX XX'],
        ['iso' => 'SM', 'nome' => 'San Marino', 'ddi' => '378', 'bandeira' => '🇸🇲', 'mascara' => 'XXXX XXXXXX'],
        ['iso' => 'RS', 'nome' => 'Sérvia', 'ddi' => '381', 'bandeira' => '🇷🇸', 'mascara' => 'XX XXX XXXX'],
        ['iso' => 'SK', 'nome' => 'Eslováquia', 'ddi' => '421', 'bandeira' => '🇸🇰', 'mascara' => 'XXX XXX XXX'],
        ['iso' => 'SI', 'nome' => 'Eslovênia', 'ddi' => '386', 'bandeira' => '🇸🇮', 'mascara' => 'XX XXX XXX'],
        ['iso' => 'UA', 'nome' => 'Ucrânia', 'ddi' => '380', 'bandeira' => '🇺🇦', 'mascara' => 'XX XXX XX XX'],
        ['iso' => 'VA', 'nome' => 'Vaticano', 'ddi' => '379', 'bandeira' => '🇻🇦', 'mascara' => 'XX XXXX'],
        ['iso' => 'GI', 'nome' => 'Gibraltar', 'ddi' => '350', 'bandeira' => '🇬🇮', 'mascara' => 'XXX XXXXX'],
        ['iso' => 'FO', 'nome' => 'Ilhas Faroe', 'ddi' => '298', 'bandeira' => '🇫🇴', 'mascara' => 'XXX XXX'],
        ['iso' => 'GL', 'nome' => 'Groenlândia', 'ddi' => '299', 'bandeira' => '🇬🇱', 'mascara' => 'XX XX XX'],

        // ── Américas (restante) ──────────────────────────────────────────────
        ['iso' => 'CR', 'nome' => 'Costa Rica', 'ddi' => '506', 'bandeira' => '🇨🇷', 'mascara' => 'XXXX XXXX'],
        ['iso' => 'PA', 'nome' => 'Panamá', 'ddi' => '507', 'bandeira' => '🇵🇦', 'mascara' => 'XXXX XXXX'],
        ['iso' => 'GT', 'nome' => 'Guatemala', 'ddi' => '502', 'bandeira' => '🇬🇹', 'mascara' => 'XXXX XXXX'],
        ['iso' => 'HN', 'nome' => 'Honduras', 'ddi' => '504', 'bandeira' => '🇭🇳', 'mascara' => 'XXXX XXXX'],
        ['iso' => 'SV', 'nome' => 'El Salvador', 'ddi' => '503', 'bandeira' => '🇸🇻', 'mascara' => 'XXXX XXXX'],
        ['iso' => 'NI', 'nome' => 'Nicarágua', 'ddi' => '505', 'bandeira' => '🇳🇮', 'mascara' => 'XXXX XXXX'],
        ['iso' => 'BZ', 'nome' => 'Belize', 'ddi' => '501', 'bandeira' => '🇧🇿', 'mascara' => 'XXX XXXX'],
        ['iso' => 'CU', 'nome' => 'Cuba', 'ddi' => '53', 'bandeira' => '🇨🇺', 'mascara' => 'X XXXXXXX'],
        ['iso' => 'HT', 'nome' => 'Haiti', 'ddi' => '509', 'bandeira' => '🇭🇹', 'mascara' => 'XXXX XXXX'],
        ['iso' => 'GY', 'nome' => 'Guiana', 'ddi' => '592', 'bandeira' => '🇬🇾', 'mascara' => 'XXX XXXX'],
        ['iso' => 'SR', 'nome' => 'Suriname', 'ddi' => '597', 'bandeira' => '🇸🇷', 'mascara' => 'XXX XXXX'],
        ['iso' => 'GF', 'nome' => 'Guiana Francesa', 'ddi' => '594', 'bandeira' => '🇬🇫', 'mascara' => 'XXX XX XX XX'],

        // ── África (restante) ────────────────────────────────────────────────
        ['iso' => 'DZ', 'nome' => 'Argélia', 'ddi' => '213', 'bandeira' => '🇩🇿', 'mascara' => 'XXX XX XX XX'],
        ['iso' => 'BJ', 'nome' => 'Benin', 'ddi' => '229', 'bandeira' => '🇧🇯', 'mascara' => 'XX XX XXXX'],
        ['iso' => 'BW', 'nome' => 'Botsuana', 'ddi' => '267', 'bandeira' => '🇧🇼', 'mascara' => 'XX XXX XXX'],
        ['iso' => 'BF', 'nome' => 'Burkina Faso', 'ddi' => '226', 'bandeira' => '🇧🇫', 'mascara' => 'XX XX XXXX'],
        ['iso' => 'BI', 'nome' => 'Burundi', 'ddi' => '257', 'bandeira' => '🇧🇮', 'mascara' => 'XX XX XXXX'],
        ['iso' => 'CV', 'nome' => 'Cabo Verde', 'ddi' => '238', 'bandeira' => '🇨🇻', 'mascara' => 'XXX XXXX'],
        ['iso' => 'CM', 'nome' => 'Camarões', 'ddi' => '237', 'bandeira' => '🇨🇲', 'mascara' => 'X XX XX XX XX'],
        ['iso' => 'CF', 'nome' => 'República Centro-Africana', 'ddi' => '236', 'bandeira' => '🇨🇫', 'mascara' => 'XX XX XX XX'],
        ['iso' => 'TD', 'nome' => 'Chade', 'ddi' => '235', 'bandeira' => '🇹🇩', 'mascara' => 'XX XX XX XX'],
        ['iso' => 'KM', 'nome' => 'Comores', 'ddi' => '269', 'bandeira' => '🇰🇲', 'mascara' => 'XXX XXXX'],
        ['iso' => 'CG', 'nome' => 'Congo', 'ddi' => '242', 'bandeira' => '🇨🇬', 'mascara' => 'XX XXX XXXX'],
        ['iso' => 'CD', 'nome' => 'Congo (RDC)', 'ddi' => '243', 'bandeira' => '🇨🇩', 'mascara' => 'XXX XXX XXX'],
        ['iso' => 'CI', 'nome' => 'Costa do Marfim', 'ddi' => '225', 'bandeira' => '🇨🇮', 'mascara' => 'XX XX XX XX XX'],
        ['iso' => 'DJ', 'nome' => 'Djibuti', 'ddi' => '253', 'bandeira' => '🇩🇯', 'mascara' => 'XX XX XX XX'],
        ['iso' => 'EG', 'nome' => 'Egito', 'ddi' => '20', 'bandeira' => '🇪🇬', 'mascara' => 'XXX XXX XXXX'],
        ['iso' => 'GQ', 'nome' => 'Guiné Equatorial', 'ddi' => '240', 'bandeira' => '🇬🇶', 'mascara' => 'XXX XXX XXX'],
        ['iso' => 'ER', 'nome' => 'Eritreia', 'ddi' => '291', 'bandeira' => '🇪🇷', 'mascara' => 'X XXX XXX'],
        ['iso' => 'SZ', 'nome' => 'Essuatíni', 'ddi' => '268', 'bandeira' => '🇸🇿', 'mascara' => 'XXXX XXXX'],
        ['iso' => 'ET', 'nome' => 'Etiópia', 'ddi' => '251', 'bandeira' => '🇪🇹', 'mascara' => 'XX XXX XXXX'],
        ['iso' => 'GA', 'nome' => 'Gabão', 'ddi' => '241', 'bandeira' => '🇬🇦', 'mascara' => 'XX XX XX XX'],
        ['iso' => 'GH', 'nome' => 'Gana', 'ddi' => '233', 'bandeira' => '🇬🇭', 'mascara' => 'XX XXX XXXX'],
        ['iso' => 'GN', 'nome' => 'Guiné', 'ddi' => '224', 'bandeira' => '🇬🇳', 'mascara' => 'XXX XX XX XX'],
        ['iso' => 'GW', 'nome' => 'Guiné-Bissau', 'ddi' => '245', 'bandeira' => '🇬🇼', 'mascara' => 'X XXXXXX'],
        ['iso' => 'KE', 'nome' => 'Quênia', 'ddi' => '254', 'bandeira' => '🇰🇪', 'mascara' => 'XXX XXXXXX'],
        ['iso' => 'LS', 'nome' => 'Lesoto', 'ddi' => '266', 'bandeira' => '🇱🇸', 'mascara' => 'XX XXX XXX'],
        ['iso' => 'LR', 'nome' => 'Libéria', 'ddi' => '231', 'bandeira' => '🇱🇷', 'mascara' => 'XX XXX XXXX'],
        ['iso' => 'LY', 'nome' => 'Líbia', 'ddi' => '218', 'bandeira' => '🇱🇾', 'mascara' => 'XX XXX XXXX'],
        ['iso' => 'MG', 'nome' => 'Madagascar', 'ddi' => '261', 'bandeira' => '🇲🇬', 'mascara' => 'XX XX XXX XX'],
        ['iso' => 'MW', 'nome' => 'Malawi', 'ddi' => '265', 'bandeira' => '🇲🇼', 'mascara' => 'X XXXX XXXX'],
        ['iso' => 'ML', 'nome' => 'Mali', 'ddi' => '223', 'bandeira' => '🇲🇱', 'mascara' => 'XX XX XX XX'],
        ['iso' => 'MR', 'nome' => 'Mauritânia', 'ddi' => '222', 'bandeira' => '🇲🇷', 'mascara' => 'XX XX XX XX'],
        ['iso' => 'MU', 'nome' => 'Maurício', 'ddi' => '230', 'bandeira' => '🇲🇺', 'mascara' => 'XXXX XXXX'],
        ['iso' => 'MA', 'nome' => 'Marrocos', 'ddi' => '212', 'bandeira' => '🇲🇦', 'mascara' => 'XXX XXXXXX'],
        ['iso' => 'NA', 'nome' => 'Namíbia', 'ddi' => '264', 'bandeira' => '🇳🇦', 'mascara' => 'XX XXX XXXX'],
        ['iso' => 'NE', 'nome' => 'Níger', 'ddi' => '227', 'bandeira' => '🇳🇪', 'mascara' => 'XX XX XX XX'],
        ['iso' => 'NG', 'nome' => 'Nigéria', 'ddi' => '234', 'bandeira' => '🇳🇬', 'mascara' => 'XXX XXX XXXX'],
        ['iso' => 'RW', 'nome' => 'Ruanda', 'ddi' => '250', 'bandeira' => '🇷🇼', 'mascara' => 'XXX XXX XXX'],
        ['iso' => 'ST', 'nome' => 'São Tomé e Príncipe', 'ddi' => '239', 'bandeira' => '🇸🇹', 'mascara' => 'XXX XXXX'],
        ['iso' => 'SN', 'nome' => 'Senegal', 'ddi' => '221', 'bandeira' => '🇸🇳', 'mascara' => 'XX XXX XX XX'],
        ['iso' => 'SC', 'nome' => 'Seicheles', 'ddi' => '248', 'bandeira' => '🇸🇨', 'mascara' => 'X XX XX XX'],
        ['iso' => 'SL', 'nome' => 'Serra Leoa', 'ddi' => '232', 'bandeira' => '🇸🇱', 'mascara' => 'XX XXXXXX'],
        ['iso' => 'SO', 'nome' => 'Somália', 'ddi' => '252', 'bandeira' => '🇸🇴', 'mascara' => 'XX XXX XXX'],
        ['iso' => 'SS', 'nome' => 'Sudão do Sul', 'ddi' => '211', 'bandeira' => '🇸🇸', 'mascara' => 'XX XXX XXXX'],
        ['iso' => 'SD', 'nome' => 'Sudão', 'ddi' => '249', 'bandeira' => '🇸🇩', 'mascara' => 'XX XXX XXXX'],
        ['iso' => 'TZ', 'nome' => 'Tanzânia', 'ddi' => '255', 'bandeira' => '🇹🇿', 'mascara' => 'XXX XXX XXX'],
        ['iso' => 'TG', 'nome' => 'Togo', 'ddi' => '228', 'bandeira' => '🇹🇬', 'mascara' => 'XX XX XX XX'],
        ['iso' => 'UG', 'nome' => 'Uganda', 'ddi' => '256', 'bandeira' => '🇺🇬', 'mascara' => 'XXX XXXXXX'],
        ['iso' => 'ZM', 'nome' => 'Zâmbia', 'ddi' => '260', 'bandeira' => '🇿🇲', 'mascara' => 'XX XXXXXXX'],
        ['iso' => 'ZW', 'nome' => 'Zimbábue', 'ddi' => '263', 'bandeira' => '🇿🇼', 'mascara' => 'XX XXX XXXX'],

        // ── Oriente Médio e Ásia ────────────────────────────────────────────
        ['iso' => 'AF', 'nome' => 'Afeganistão', 'ddi' => '93', 'bandeira' => '🇦🇫', 'mascara' => 'XX XXX XXXX'],
        ['iso' => 'AM', 'nome' => 'Armênia', 'ddi' => '374', 'bandeira' => '🇦🇲', 'mascara' => 'XX XXXXXX'],
        ['iso' => 'AZ', 'nome' => 'Azerbaijão', 'ddi' => '994', 'bandeira' => '🇦🇿', 'mascara' => 'XX XXX XX XX'],
        ['iso' => 'BH', 'nome' => 'Bahrein', 'ddi' => '973', 'bandeira' => '🇧🇭', 'mascara' => 'XXXX XXXX'],
        ['iso' => 'BD', 'nome' => 'Bangladesh', 'ddi' => '880', 'bandeira' => '🇧🇩', 'mascara' => 'XXXX XXXXXX'],
        ['iso' => 'BT', 'nome' => 'Butão', 'ddi' => '975', 'bandeira' => '🇧🇹', 'mascara' => 'X XXXXXX'],
        ['iso' => 'BN', 'nome' => 'Brunei', 'ddi' => '673', 'bandeira' => '🇧🇳', 'mascara' => 'XXX XXXX'],
        ['iso' => 'KH', 'nome' => 'Camboja', 'ddi' => '855', 'bandeira' => '🇰🇭', 'mascara' => 'XX XXX XXX'],
        ['iso' => 'GE', 'nome' => 'Geórgia', 'ddi' => '995', 'bandeira' => '🇬🇪', 'mascara' => 'XXX XX XX XX'],
        ['iso' => 'HK', 'nome' => 'Hong Kong', 'ddi' => '852', 'bandeira' => '🇭🇰', 'mascara' => 'XXXX XXXX'],
        ['iso' => 'ID', 'nome' => 'Indonésia', 'ddi' => '62', 'bandeira' => '🇮🇩', 'mascara' => 'XXX-XXXX-XXXX'],
        ['iso' => 'IR', 'nome' => 'Irã', 'ddi' => '98', 'bandeira' => '🇮🇷', 'mascara' => 'XXX XXX XXXX'],
        ['iso' => 'IQ', 'nome' => 'Iraque', 'ddi' => '964', 'bandeira' => '🇮🇶', 'mascara' => 'XXX XXX XXXX'],
        ['iso' => 'JO', 'nome' => 'Jordânia', 'ddi' => '962', 'bandeira' => '🇯🇴', 'mascara' => 'X XXXX XXXX'],
        ['iso' => 'KW', 'nome' => 'Kuwait', 'ddi' => '965', 'bandeira' => '🇰🇼', 'mascara' => 'XXXX XXXX'],
        ['iso' => 'KG', 'nome' => 'Quirguistão', 'ddi' => '996', 'bandeira' => '🇰🇬', 'mascara' => 'XXX XXX XXX'],
        ['iso' => 'LA', 'nome' => 'Laos', 'ddi' => '856', 'bandeira' => '🇱🇦', 'mascara' => 'XX XX XXX XXX'],
        ['iso' => 'LB', 'nome' => 'Líbano', 'ddi' => '961', 'bandeira' => '🇱🇧', 'mascara' => 'XX XXX XXX'],
        ['iso' => 'MO', 'nome' => 'Macau', 'ddi' => '853', 'bandeira' => '🇲🇴', 'mascara' => 'XXXX XXXX'],
        ['iso' => 'MY', 'nome' => 'Malásia', 'ddi' => '60', 'bandeira' => '🇲🇾', 'mascara' => 'XX-XXX XXXX'],
        ['iso' => 'MV', 'nome' => 'Maldivas', 'ddi' => '960', 'bandeira' => '🇲🇻', 'mascara' => 'XXX-XXXX'],
        ['iso' => 'MN', 'nome' => 'Mongólia', 'ddi' => '976', 'bandeira' => '🇲🇳', 'mascara' => 'XXXX XXXX'],
        ['iso' => 'MM', 'nome' => 'Mianmar', 'ddi' => '95', 'bandeira' => '🇲🇲', 'mascara' => 'XX XXX XXXX'],
        ['iso' => 'NP', 'nome' => 'Nepal', 'ddi' => '977', 'bandeira' => '🇳🇵', 'mascara' => 'XXX-XXXXXXX'],
        ['iso' => 'KP', 'nome' => 'Coreia do Norte', 'ddi' => '850', 'bandeira' => '🇰🇵', 'mascara' => 'XXXX XXXXXXX'],
        ['iso' => 'OM', 'nome' => 'Omã', 'ddi' => '968', 'bandeira' => '🇴🇲', 'mascara' => 'XXXX XXXX'],
        ['iso' => 'PK', 'nome' => 'Paquistão', 'ddi' => '92', 'bandeira' => '🇵🇰', 'mascara' => 'XXX XXXXXXX'],
        ['iso' => 'PS', 'nome' => 'Palestina', 'ddi' => '970', 'bandeira' => '🇵🇸', 'mascara' => 'XXX XXX XXX'],
        ['iso' => 'PH', 'nome' => 'Filipinas', 'ddi' => '63', 'bandeira' => '🇵🇭', 'mascara' => 'XXX XXX XXXX'],
        ['iso' => 'QA', 'nome' => 'Catar', 'ddi' => '974', 'bandeira' => '🇶🇦', 'mascara' => 'XXXX XXXX'],
        ['iso' => 'SG', 'nome' => 'Singapura', 'ddi' => '65', 'bandeira' => '🇸🇬', 'mascara' => 'XXXX XXXX'],
        ['iso' => 'KR', 'nome' => 'Coreia do Sul', 'ddi' => '82', 'bandeira' => '🇰🇷', 'mascara' => 'XX-XXXX-XXXX'],
        ['iso' => 'LK', 'nome' => 'Sri Lanka', 'ddi' => '94', 'bandeira' => '🇱🇰', 'mascara' => 'XX XXX XXXX'],
        ['iso' => 'SY', 'nome' => 'Síria', 'ddi' => '963', 'bandeira' => '🇸🇾', 'mascara' => 'XXX XXX XXX'],
        ['iso' => 'TW', 'nome' => 'Taiwan', 'ddi' => '886', 'bandeira' => '🇹🇼', 'mascara' => 'XXX XXX XXX'],
        ['iso' => 'TJ', 'nome' => 'Tadjiquistão', 'ddi' => '992', 'bandeira' => '🇹🇯', 'mascara' => 'XX XXX XXXX'],
        ['iso' => 'TH', 'nome' => 'Tailândia', 'ddi' => '66', 'bandeira' => '🇹🇭', 'mascara' => 'XX XXX XXXX'],
        ['iso' => 'TL', 'nome' => 'Timor-Leste', 'ddi' => '670', 'bandeira' => '🇹🇱', 'mascara' => 'XXXX XXXX'],
        ['iso' => 'TR', 'nome' => 'Turquia', 'ddi' => '90', 'bandeira' => '🇹🇷', 'mascara' => 'XXX XXX XXXX'],
        ['iso' => 'TM', 'nome' => 'Turcomenistão', 'ddi' => '993', 'bandeira' => '🇹🇲', 'mascara' => 'XX XXXXXX'],
        ['iso' => 'UZ', 'nome' => 'Uzbequistão', 'ddi' => '998', 'bandeira' => '🇺🇿', 'mascara' => 'XX XXX XX XX'],
        ['iso' => 'VN', 'nome' => 'Vietnã', 'ddi' => '84', 'bandeira' => '🇻🇳', 'mascara' => 'XXX XXX XX XX'],
        ['iso' => 'YE', 'nome' => 'Iêmen', 'ddi' => '967', 'bandeira' => '🇾🇪', 'mascara' => 'XXX XXX XXX'],

        // ── Oceania ──────────────────────────────────────────────────────────
        ['iso' => 'FJ', 'nome' => 'Fiji', 'ddi' => '679', 'bandeira' => '🇫🇯', 'mascara' => 'XXX XXXX'],
        ['iso' => 'KI', 'nome' => 'Kiribati', 'ddi' => '686', 'bandeira' => '🇰🇮', 'mascara' => 'XXXXX'],
        ['iso' => 'MH', 'nome' => 'Ilhas Marshall', 'ddi' => '692', 'bandeira' => '🇲🇭', 'mascara' => 'XXX-XXXX'],
        ['iso' => 'FM', 'nome' => 'Micronésia', 'ddi' => '691', 'bandeira' => '🇫🇲', 'mascara' => 'XXX XXXX'],
        ['iso' => 'NR', 'nome' => 'Nauru', 'ddi' => '674', 'bandeira' => '🇳🇷', 'mascara' => 'XXX XXXX'],
        ['iso' => 'NZ', 'nome' => 'Nova Zelândia', 'ddi' => '64', 'bandeira' => '🇳🇿', 'mascara' => 'XX XXX XXXX'],
        ['iso' => 'PW', 'nome' => 'Palau', 'ddi' => '680', 'bandeira' => '🇵🇼', 'mascara' => 'XXX XXXX'],
        ['iso' => 'PG', 'nome' => 'Papua-Nova Guiné', 'ddi' => '675', 'bandeira' => '🇵🇬', 'mascara' => 'XXX XXXXX'],
        ['iso' => 'WS', 'nome' => 'Samoa', 'ddi' => '685', 'bandeira' => '🇼🇸', 'mascara' => 'XXXXX'],
        ['iso' => 'SB', 'nome' => 'Ilhas Salomão', 'ddi' => '677', 'bandeira' => '🇸🇧', 'mascara' => 'XXXXX'],
        ['iso' => 'TO', 'nome' => 'Tonga', 'ddi' => '676', 'bandeira' => '🇹🇴', 'mascara' => 'XXXXX'],
        ['iso' => 'TV', 'nome' => 'Tuvalu', 'ddi' => '688', 'bandeira' => '🇹🇻', 'mascara' => 'XXXXX'],
        ['iso' => 'VU', 'nome' => 'Vanuatu', 'ddi' => '678', 'bandeira' => '🇻🇺', 'mascara' => 'XXXXX'],
        ['iso' => 'NC', 'nome' => 'Nova Caledônia', 'ddi' => '687', 'bandeira' => '🇳🇨', 'mascara' => 'XX.XX.XX'],
        ['iso' => 'PF', 'nome' => 'Polinésia Francesa', 'ddi' => '689', 'bandeira' => '🇵🇫', 'mascara' => 'XX XX XX XX'],
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
                // Se sobrou uma quantidade razoável de dígitos (4 a 16 dígitos)
                if (strlen($local) >= 4 && strlen($local) <= 16) {
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
