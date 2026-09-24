<?php

namespace App\Services;

use App\Services\Canais\EnvioBrutoWhatsappInterface;
use Illuminate\Support\Facades\Log;

/**
 * Simula comportamento humano ao enviar mensagens pelo WhatsApp:
 * - Divide a resposta em balões curtos e naturais
 * - Envia indicador "digitando..." antes de cada balão
 * - Aplica delay proporcional ao tamanho do texto
 *
 * Fase 1 do planejamento do canal WhatsApp Messenger próprio (23/09): este
 * serviço é o "Músculo" do motor de humanização (ver leadcerto/integracoes/
 * whatsapp-uazapi/regra-geral-de-envio-de-mensagens-no-whatsapp.md, seção 1)
 * — universal por design, não específico de provedor. Antes era hard-wired
 * em UazapiService; agora recebe o serviço de envio bruto como parâmetro
 * (EnvioBrutoWhatsappInterface), pra qualquer canal não-oficial reaproveitar.
 */
class HumanizacaoService
{
    private const MAX_CHARS_BALAO = 280;
    private const DELAY_MIN_MS    = 1500;  // 1.5s mínimo
    private const DELAY_MAX_MS    = 5000;  // 5s máximo
    private const CHARS_POR_SEG   = 150;   // velocidade de digitação simulada
    private const PAUSA_ENTRE_MS  = 600;   // pausa entre balões

    /**
     * Processa e envia uma resposta completa com humanização.
     *
     * @param EnvioBrutoWhatsappInterface $servico  Canal não-oficial que executa o envio de verdade
     * @param string $instanceToken  Token da instância do canal
     * @param string $numero         Telefone do destinatário (55119...)
     * @param string $texto          Resposta completa do LLM
     * @return bool true se todos os balões foram enviados com sucesso
     */
    public function processar(EnvioBrutoWhatsappInterface $servico, string $instanceToken, string $numero, string $texto): bool
    {
        $baloes  = $this->dividirEmBaloes($texto);
        $jid     = $numero . '@s.whatsapp.net';
        $sucesso = true;

        foreach ($baloes as $i => $balao) {
            // Simula digitando
            $servico->setPresenca($instanceToken, 'composing', $jid);

            // Delay proporcional ao tamanho do balão
            $ms = $this->calcularDelayMs($balao);
            usleep($ms * 1_000);

            // Envia o balão
            $ok = $servico->enviarTexto($instanceToken, $numero, $balao);

            if (! $ok) {
                $sucesso = false;
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
        $servico->setPresenca($instanceToken, 'available');

        return $sucesso;
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
