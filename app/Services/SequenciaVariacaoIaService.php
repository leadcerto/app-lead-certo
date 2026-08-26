<?php

namespace App\Services;

use App\Models\SequenciaMensagem;
use App\Models\SequenciaMensagemVariacao;
use Illuminate\Support\Facades\Log;

class SequenciaVariacaoIaService
{
    public function __construct(private OpenRouterService $openRouter) {}

    /**
     * Cria as 6 variações iniciais de uma mensagem, via IA — mas nascem todas
     * INATIVAS no sorteio. Histórico: a versão original (até 2026-08-20)
     * chamava IA e já saía tudo ATIVO sem revisão ("estranho e complicado" —
     * Leonardo, 2026-08-21); o redesenho seguinte trocou a IA por 6 cópias
     * verbatim do texto original pra resolver isso, mas foi longe demais —
     * Leonardo apontou (2026-08-16... na prática mesma virada de sessão)
     * que as 6 abas ficavam idênticas, sem nenhuma variedade de verdade.
     * Este é o meio-termo: continua chamando a IA pra ter texto realmente
     * diferente entre as abas (mesmo prompt/regras de `chamarIaEGerar()`,
     * reusado por `regenerar()` também), só que sempre `ativa=false` — o
     * humano revisa e ativa cada uma que aprovar, a Original protegida
     * continua sendo a única que envia até isso acontecer.
     * Não faz nada se a mensagem não tem texto, se já existe alguma variação
     * não-protegida (evita duplicar a criação), ou se a IA estiver
     * indisponível no momento (a mensagem principal continua funcionando
     * normalmente via a variação Original, protegida).
     */
    public function gerarVariacoesIniciais(SequenciaMensagem $mensagem): int
    {
        if (trim((string) $mensagem->conteudo) === '') {
            return 0;
        }

        $jaTemVariacao = $mensagem->variacoes()->where('protegida', false)->exists();
        if ($jaTemVariacao) {
            return 0;
        }

        return $this->chamarIaEGerar($mensagem, 'sequencia_variacoes_iniciais');
    }

    /**
     * Pede à IA uma nova versão de UMA variação específica — usa a mensagem
     * original (protegida) como referência, sem mexer nas outras variações
     * nem no estado `ativa` atual dela. Retorna false (sem alterar o
     * conteúdo) se a variação for protegida ou se a IA falhar.
     */
    public function regenerarUma(SequenciaMensagemVariacao $variacao): bool
    {
        if ($variacao->protegida) {
            return false;
        }

        $mensagem = $variacao->mensagem;
        if (! $mensagem || trim((string) $mensagem->conteudo) === '') {
            return false;
        }

        $messages = [
            [
                'role'    => 'system',
                'content' => 'Você é especialista em copywriting conversacional para WhatsApp. Sua única função é gerar UMA variação de uma mensagem original, preservando objetivo e variáveis. Responde SEMPRE com JSON válido.',
            ],
            [
                'role'    => 'user',
                'content' => <<<PROMPT
MENSAGEM ORIGINAL (IMUTÁVEL):
"{$mensagem->conteudo}"

VERSÃO ATUAL DESTA VARIAÇÃO (pode usar de referência, mas gere algo diferente dela):
"{$variacao->conteudo}"

TAREFA: gere UMA nova variação desta mensagem.

REGRAS OBRIGATÓRIAS:
1. Preserve todas as {variaveis} exatamente como estão escritas — nunca substitua, remova ou renomeie uma variável.
2. Mantenha o mesmo objetivo comunicacional da mensagem original.
3. Varie apenas: estrutura da frase, abertura emocional, nível de formalidade (até 1 grau acima ou abaixo), comprimento (até 20% maior ou menor).
4. Não invente informações, promessas ou dados que não existam na mensagem original.
5. Não use mais emojis que o original.
6. A variação deve funcionar de forma autônoma.
7. Não numere nem explique — retorne apenas o texto da variação.

FORMATO DE SAÍDA — retorne SOMENTE este JSON, sem markdown, sem explicação adicional:
{"conteudo": "..."}
PROMPT,
            ],
        ];

        $resposta = $this->openRouter->chat($messages, 'complexo', 500, 'sequencia_variacao_individual', $mensagem->tenant_id);

        if (! $resposta) {
            Log::warning('SequenciaVariacaoIaService: IA indisponível ao regenerar variação', ['variacao_id' => $variacao->id]);
            return false;
        }

        $limpo = trim(preg_replace('/^```(?:json)?\s*|\s*```$/m', '', $resposta));
        $json  = json_decode($limpo, true);
        $texto = trim((string) ($json['conteudo'] ?? ''));

        if ($texto === '') {
            Log::warning('SequenciaVariacaoIaService: resposta IA não é JSON válido ao regenerar variação', [
                'variacao_id' => $variacao->id,
                'resposta'    => mb_substr($resposta, 0, 500),
            ]);
            return false;
        }

        $variacao->update(['conteudo' => $texto, 'origem' => 'ia']);

        return true;
    }

    /**
     * Regeneração manual (botão "Gerar variações com IA" em lote): desativa
     * (soft) as variações IA atuais e gera 6 novas — inativas, mesma regra
     * de `gerarVariacoesIniciais()`, pra manter as duas entradas consistentes
     * (o humano sempre revisa e ativa antes de qualquer variação nova entrar
     * no sorteio de envio).
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
                'ativa'                 => false,
            ]);
            $criadas++;
        }

        return $criadas;
    }
}
