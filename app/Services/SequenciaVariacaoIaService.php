<?php

namespace App\Services;

use App\Models\SequenciaMensagem;
use App\Models\SequenciaMensagemVariacao;
use Illuminate\Support\Facades\Log;

class SequenciaVariacaoIaService
{
    public function __construct(private OpenRouterService $openRouter) {}

    /**
     * Gera as até 6 variações iniciais de uma mensagem, com base no conteúdo
     * escrito pelo humano. Não faz nada se a mensagem não tem texto ou se já
     * existe alguma variação de origem IA (evita duplicar geração).
     */
    public function gerarVariacoesIniciais(SequenciaMensagem $mensagem): int
    {
        if (trim((string) $mensagem->conteudo) === '') {
            return 0;
        }

        $jaTemIa = $mensagem->variacoes()->where('origem', 'ia')->exists();
        if ($jaTemIa) {
            return 0;
        }

        return $this->chamarIaEGerar($mensagem, 'sequencia_variacoes_iniciais');
    }

    /**
     * Regeneração manual: desativa (soft) as variações IA atuais e gera 6 novas.
     * Usada pelo endpoint de "regenerar variações" quando o humano edita a
     * mensagem original e quer variações atualizadas.
     */
    public function regenerar(SequenciaMensagem $mensagem): int
    {
        if (trim((string) $mensagem->conteudo) === '') {
            return 0;
        }

        $mensagem->variacoes()
            ->where('origem', 'ia')
            ->where('ativa', true)
            ->update(['ativa' => false, 'substituida_em' => now()]);

        return $this->chamarIaEGerar($mensagem, 'sequencia_variacoes_regeneracao');
    }

    private function chamarIaEGerar(SequenciaMensagem $mensagem, string $origem): int
    {
        $messages = [
            [
                'role'    => 'system',
                'content' => 'Você é especialista em copywriting conversacional para WhatsApp. Sua única função é gerar variações de uma mensagem original, preservando objetivo e variáveis. Responde SEMPRE com JSON válido.',
            ],
            [
                'role'    => 'user',
                'content' => <<<PROMPT
MENSAGEM ORIGINAL (IMUTÁVEL):
"{$mensagem->conteudo}"

TAREFA: gere exatamente 6 variações desta mensagem.

REGRAS OBRIGATÓRIAS:
1. Preserve todas as {variaveis} exatamente como estão escritas — nunca substitua, remova ou renomeie uma variável.
2. Mantenha o mesmo objetivo comunicacional da mensagem original.
3. Varie apenas: estrutura da frase, abertura emocional, nível de formalidade (até 1 grau acima ou abaixo), comprimento (até 20% maior ou menor).
4. Não invente informações, promessas ou dados que não existam na mensagem original.
5. Não use mais emojis que o original.
6. Cada variação deve funcionar de forma autônoma.
7. Não numere nem explique — retorne apenas o texto de cada variação.

FORMATO DE SAÍDA — retorne SOMENTE este JSON, sem markdown, sem explicação adicional:
{"variacoes": [{"ordem": 1, "conteudo": "..."}, {"ordem": 2, "conteudo": "..."}, {"ordem": 3, "conteudo": "..."}, {"ordem": 4, "conteudo": "..."}, {"ordem": 5, "conteudo": "..."}, {"ordem": 6, "conteudo": "..."}]}
PROMPT,
            ],
        ];

        $resposta = $this->openRouter->chat($messages, 'complexo', 2000, $origem, $mensagem->tenant_id);

        if (! $resposta) {
            Log::warning('SequenciaVariacaoIaService: IA indisponível ao gerar variações', ['mensagem_id' => $mensagem->id, 'origem' => $origem]);
            return 0;
        }

        $limpo = trim(preg_replace('/^```(?:json)?\s*|\s*```$/m', '', $resposta));
        $json  = json_decode($limpo, true);
        $lista = $json['variacoes'] ?? null;

        if (! is_array($lista) || count($lista) === 0) {
            Log::warning('SequenciaVariacaoIaService: resposta IA não é JSON válido', [
                'mensagem_id' => $mensagem->id,
                'origem'      => $origem,
                'resposta'    => mb_substr($resposta, 0, 500),
            ]);
            return 0;
        }

        $criadas = 0;
        foreach ($lista as $item) {
            $texto = trim((string) ($item['conteudo'] ?? ''));
            if ($texto === '') {
                continue;
            }

            SequenciaMensagemVariacao::create([
                'tenant_id'             => $mensagem->tenant_id,
                'sequencia_mensagem_id' => $mensagem->id,
                'conteudo'              => $texto,
                'origem'                => 'ia',
                'protegida'             => false,
                'ativa'                 => true,
            ]);
            $criadas++;
        }

        return $criadas;
    }
}
