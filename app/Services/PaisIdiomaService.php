<?php

namespace App\Services;

/**
 * Camada 1 de detecção de idioma (ver
 * docs/superpowers/specs/2026-08-21-idioma-multilingue-atendimento-design.md):
 * sugere o idioma provável a partir do DDI do telefone, sem custo de IA. É só
 * uma SUGESTÃO inicial — nunca confirmação (um número espanhol não garante
 * que a pessoa fala espanhol). Lista fixa, cobre só os idiomas que este
 * projeto suporta hoje — não é uma lib de países do mundo todo.
 */
class PaisIdiomaService
{
    private const DDI_PARA_IDIOMA = [
        '55'  => 'pt-BR',
        '351' => 'pt-PT',
        '34'  => 'es-ES',
        '1'   => 'en-US',
    ];

    public function sugerirIdioma(string $telefoneCanonico): ?string
    {
        $digitos = preg_replace('/\D/', '', $telefoneCanonico);

        // Checa os DDIs de 3 dígitos antes dos de 2 — '351' não pode ser lido
        // como '35' (que nem existe na lista, mas o princípio vale em geral:
        // prefixo mais longo primeiro evita colisão). Também checa 1-dígito por último.
        foreach (self::DDI_PARA_IDIOMA as $ddi => $idioma) {
            if (strlen($ddi) === 3 && str_starts_with($digitos, $ddi)) {
                return $idioma;
            }
        }
        foreach (self::DDI_PARA_IDIOMA as $ddi => $idioma) {
            if (strlen($ddi) === 2 && str_starts_with($digitos, $ddi)) {
                return $idioma;
            }
        }
        foreach (self::DDI_PARA_IDIOMA as $ddi => $idioma) {
            if (strlen($ddi) === 1 && str_starts_with($digitos, $ddi)) {
                return $idioma;
            }
        }

        return null;
    }
}
