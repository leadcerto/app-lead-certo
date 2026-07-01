<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

/**
 * Simula comportamento humano ao enviar mensagens pelo WhatsApp:
 * - Divide a resposta em balões curtos e naturais
 * - Envia indicador "digitando..." antes de cada balão
 * - Aplica delay proporcional ao tamanho do texto
 */
class HumanizacaoService
{
    private const MAX_CHARS_BALAO = 280;
    private const DELAY_MIN_MS    = 1500;  // 1.5s mínimo
    private const DELAY_MAX_MS    = 5000;  // 5s máximo
    private const CHARS_POR_SEG   = 150;   // velocidade de digitação simulada
    private const PAUSA_ENTRE_MS  = 600;   // pausa entre balões

    public function __construct(private UazapiService $uazapi) {}

    /**
     * Processa e envia uma resposta completa com humanização.
     *
     * @param string $instanceToken  Token da instância Uazapi do tenant
     * @param string $numero         Telefone do destinatário (55119...)
     * @param string $texto          Resposta completa do LLM
     */
    public function processar(string $instanceToken, string $numero, string $texto): void
    {
        $baloes = $this->dividirEmBaloes($texto);
        $jid    = $numero . '@s.whatsapp.net';

        foreach ($baloes as $i => $balao) {
            // Simula digitando
            $this->uazapi->setPresenca($instanceToken, 'composing', $jid);

            // Delay proporcional ao tamanho do balão
            $ms = $this->calcularDelayMs($balao);
            usleep($ms * 1_000);

            // Envia o balão
            $ok = $this->uazapi->enviarTexto($instanceToken, $numero, $balao);

            if (! $ok) {
                Log::warning('HumanizacaoService: falha ao enviar balão', [
                    'numero' => $numero,
                    'balao'  => $i + 1,
                    'total'  => count($baloes),
                ]);
            }

            // Pausa natural entre balões (exceto o último)
            if ($i < count($baloes) - 1) {
                usleep(self::PAUSA_ENTRE_MS * 1_000);
            }
        }

        // Volta ao estado disponível após enviar tudo
        $this->uazapi->setPresenca($instanceToken, 'available');
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function dividirEmBaloes(string $texto): array
    {
        // Primeiro divide por parágrafo duplo (quebra intencional do LLM)
        $partes = preg_split('/\n{2,}/', trim($texto));
        $baloes = [];

        foreach ($partes as $parte) {
            $parte = trim($parte);
            if ($parte === '') continue;

            if (mb_strlen($parte) <= self::MAX_CHARS_BALAO) {
                $baloes[] = $parte;
                continue;
            }

            // Parte longa: divide por sentença
            foreach ($this->dividirPorSentenca($parte) as $fragmento) {
                $baloes[] = $fragmento;
            }
        }

        return $baloes ?: [trim($texto)];
    }

    private function dividirPorSentenca(string $texto): array
    {
        // Divide em frases por ". ", "! ", "? " mantendo a pontuação
        $frases = preg_split('/(?<=[.!?])\s+/', $texto, -1, PREG_SPLIT_NO_EMPTY);
        $baloes = [];
        $atual  = '';

        foreach ($frases as $frase) {
            $candidato = $atual ? "{$atual} {$frase}" : $frase;

            if (mb_strlen($candidato) > self::MAX_CHARS_BALAO) {
                if ($atual !== '') {
                    $baloes[] = $atual;
                }
                $atual = $frase;
            } else {
                $atual = $candidato;
            }
        }

        if ($atual !== '') {
            $baloes[] = $atual;
        }

        return $baloes ?: [$texto];
    }

    private function calcularDelayMs(string $texto): int
    {
        $chars = mb_strlen($texto);
        $ms    = (int) (($chars / self::CHARS_POR_SEG) * 1_000);

        return min(self::DELAY_MAX_MS, max(self::DELAY_MIN_MS, $ms));
    }
}
