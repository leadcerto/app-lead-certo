<?php

namespace App\Services;

/**
 * Item 11 do roteiro de 2026-08-20 — módulo de idioma por card com tradução
 * bidirecional real: o atendente/IA escreve em português e o lead recebe no
 * idioma dele; o lead escreve no idioma dele e o atendente lê em português.
 * Não é um selo estático, é tradução de verdade nas duas pontas.
 *
 * Falha de tradução nunca deve travar o envio — sempre que a IA/OpenRouter
 * não responder, os métodos retornam null e quem chama decide o fallback
 * (normalmente: manda o texto original mesmo, sem traduzir, em vez de não
 * mandar nada).
 */
class TraducaoService
{
    public function __construct(private OpenRouterService $openRouter) {}

    /**
     * Detecta o idioma de um texto — retorna código ISO 639-1 de 2 letras
     * (ex.: 'pt', 'en', 'es') ou null se não conseguir determinar (falha da
     * IA, texto vazio/curto demais tipo "ok" ou só emoji).
     */
    public function detectarIdioma(string $texto): ?string
    {
        $texto = trim($texto);
        if (mb_strlen($texto) < 3) {
            return null;
        }

        $resposta = $this->openRouter->chat([
            ['role' => 'system', 'content' =>
                'Identifique o idioma predominante do texto do usuário. Responda SOMENTE com o código '
                . 'ISO 639-1 de 2 letras em minúsculo (ex.: pt, en, es, fr). Se não for possível identificar '
                . '(texto muito curto, só emoji, número, saudação genérica que existe em vários idiomas), '
                . 'responda exatamente NENHUM.'],
            ['role' => 'user', 'content' => $texto],
        ], 'simples', 10, 'detectar_idioma');

        if (! $resposta) {
            return null;
        }

        $codigo = mb_strtolower(trim($resposta));

        if (! preg_match('/^[a-z]{2}$/', $codigo)) {
            return null;
        }

        return $codigo;
    }

    /**
     * Traduz um texto pro idioma alvo. Retorna null se a tradução falhar —
     * quem chama deve decidir o fallback (normalmente: usar o texto
     * original em vez de bloquear o envio).
     */
    public function traduzir(string $texto, string $idiomaAlvo, string $idiomaOrigem = 'pt'): ?string
    {
        if ($idiomaAlvo === $idiomaOrigem) {
            return $texto;
        }

        $resposta = $this->openRouter->chat([
            ['role' => 'system', 'content' =>
                "Traduza o texto do usuário de {$idiomaOrigem} para o idioma de código ISO 639-1 '{$idiomaAlvo}'. "
                . 'Responda SOMENTE com o texto traduzido, mantendo o tom natural de uma conversa de WhatsApp '
                . '(informal, sem formalizar demais), preservando emojis e formatação (* para negrito) como '
                . 'estiverem. Nunca adicione explicação, nota ou texto extra.'],
            ['role' => 'user', 'content' => $texto],
        ], 'simples', 800, 'traduzir_mensagem');

        return $resposta ? trim($resposta) : null;
    }
}
