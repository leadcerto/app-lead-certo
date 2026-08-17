<?php

namespace App\Services;

use App\Models\CategoriaTemplate;
use App\Models\TemplateAvaliacao;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Gera rascunhos de avaliação (TemplateAvaliacao) por IA, a partir da
 * categoria + palavras-chave que a empresa configurou.
 *
 * Regra de negócio central (ver Manual Técnico, seção 6): o texto gerado é
 * sempre um RASCUNHO oferecido ao cliente real por telefone — nunca um texto
 * publicado em nome de alguém. Por isso o prompt exige primeira pessoa (é
 * assim que se escreve uma avaliação de verdade) e proíbe estrelas dentro do
 * corpo (a nota já existe como campo separado no Google).
 *
 * Dois marcadores de texto ficam salvos literalmente no rascunho (nunca
 * resolvidos aqui) — continuam editáveis pelo admin como qualquer texto:
 * - `[nome da empresa]` — sempre presente; resolvido automaticamente pro
 *   nome real do tenant só na hora de exibir pro avaliador
 *   (TemplateAvaliacao::textoResolvido()). Como o dado já é conhecido, não
 *   faz sentido pedir pra IA acertar — e se o tenant mudar de nome, todo
 *   template já gerado atualiza sozinho, sem precisar editar um por um.
 * - `[nome de quem te atendeu]` — OPCIONAL: nem todo negócio tem um
 *   atendente identificável, então aparece só em parte dos rascunhos (nunca
 *   em todos) quando $incluirNomeAtendente é true, e nunca quando é false.
 *   Fica sempre como marcador literal (nunca resolvido pelo sistema),
 *   porque não sabemos quem atendeu cada cliente — é o cliente real quem
 *   preenche, na ligação.
 */
class TemplateAvaliacaoIaService
{
    private const MAX_QUANTIDADE = 10;

    public function __construct(private OpenRouterService $openRouter) {}

    /**
     * Gera até MAX_QUANTIDADE rascunhos novos pra uma categoria e já salva
     * como TemplateAvaliacao (ativo=true, pronto pra entrar na rotação do
     * SorteioTemplateService).
     *
     * @return int Quantidade de templates efetivamente criados.
     */
    public function gerar(
        CategoriaTemplate $categoria,
        int $quantidade = 5,
        ?string $contexto = null,
        bool $incluirNomeAtendente = true,
    ): int {
        $quantidade = max(1, min($quantidade, self::MAX_QUANTIDADE));

        $resposta = $this->openRouter->chat(
            $this->montarMensagens($categoria, $quantidade, $contexto, $incluirNomeAtendente),
            'complexo',
            2000,
            'template_avaliacao_gerar_ia',
            $categoria->tenant_id,
        );

        if (! $resposta) {
            Log::warning('TemplateAvaliacaoIaService: IA indisponível ao gerar templates', [
                'categoria_id' => $categoria->id,
            ]);
            return 0;
        }

        $limpo = trim(preg_replace('/^```(?:json)?\s*|\s*```$/m', '', $resposta));
        $json  = json_decode($limpo, true);
        $lista = $json['templates'] ?? null;

        if (! is_array($lista) || count($lista) === 0) {
            Log::warning('TemplateAvaliacaoIaService: resposta IA não é JSON válido', [
                'categoria_id' => $categoria->id,
                'resposta'     => mb_substr($resposta, 0, 500),
            ]);
            return 0;
        }

        $criados = 0;
        foreach ($lista as $item) {
            $texto = trim((string) ($item['texto'] ?? ''));
            if ($texto === '') {
                continue;
            }

            // Rede de segurança: se a IA ignorar as regras (estrela no corpo,
            // marcador de empresa ausente, ou usar o marcador de atendente
            // mesmo desativado), o template não entra na rotação — melhor
            // gerar menos do que deixar passar algo fora do combinado.
            if (! $this->respeitaRegras($texto, $incluirNomeAtendente)) {
                Log::warning('TemplateAvaliacaoIaService: rascunho descartado por não seguir as regras', [
                    'categoria_id' => $categoria->id,
                    'texto'        => $texto,
                ]);
                continue;
            }

            TemplateAvaliacao::create([
                'tenant_id'    => $categoria->tenant_id,
                'codigo'       => $this->codigoUnico($categoria->tenant_id),
                'texto'        => $texto,
                'categoria_id' => $categoria->id,
                'ativo'        => true,
            ]);
            $criados++;
        }

        return $criados;
    }

    private function montarMensagens(CategoriaTemplate $categoria, int $quantidade, ?string $contexto, bool $incluirNomeAtendente): array
    {
        $palavrasChave = collect($categoria->palavras_chave ?? [])->filter()->implode(', ');
        $palavrasChave = $palavrasChave !== '' ? $palavrasChave : 'nenhuma — use o nome da categoria como direção';

        $regraAtendente = $incluirNomeAtendente
            ? 'Em ALGUNS dos rascunhos (não em todos — varie), inclua também o marcador exato `[nome de quem te atendeu]` em algum ponto do texto. Nunca invente um nome próprio de funcionário.'
            : 'NÃO use o marcador `[nome de quem te atendeu]` nem cite nenhum nome de funcionário em nenhum rascunho — este negócio não tem atendente individual identificável.';

        return [
            [
                'role'    => 'system',
                'content' => 'Você ajuda a redigir RASCUNHOS de avaliação do Google Meu Negócio para uma '
                    . 'ferramenta de solicitação de avaliação legítima. Cada rascunho é oferecido, por telefone, '
                    . 'a um cliente real que já usou o serviço — o cliente decide se usa, edita ou ignora, e '
                    . 'sempre publica como avaliação dele mesmo, na conta dele. Você nunca escreve uma avaliação '
                    . 'final nem finge ser o cliente. Responde SEMPRE com JSON válido.',
            ],
            [
                'role'    => 'user',
                'content' => <<<PROMPT
CATEGORIA: {$categoria->nome}
PALAVRAS-CHAVE (direção de conteúdo — pode usar como inspiração, não precisa usar todas nem literalmente): {$palavrasChave}
CONTEXTO ADICIONAL DA EMPRESA: {$this->contextoOuPadrao($contexto)}

TAREFA: gere exatamente {$quantidade} rascunhos de avaliação, em primeira pessoa, como se fossem escritos por um cliente satisfeito relatando a própria experiência.

REGRAS OBRIGATÓRIAS:
1. Primeira pessoa, tom natural de avaliação real — não use linguagem de propaganda ou slogan.
2. Todo rascunho precisa conter, em algum ponto do texto, exatamente o marcador `[nome da empresa]` (nunca escreva o nome real da empresa, sempre esse marcador).
3. {$regraAtendente}
4. NÃO inclua estrelas (⭐★✩) nem nota numérica no texto — a nota é um campo separado no Google, não faz parte do texto.
5. Não invente fatos específicos (datas, valores, promessas) que a empresa não confirmou no contexto.
6. Varie estrutura, abertura e tamanho entre os rascunhos — nunca repita a mesma frase de abertura.
7. Máximo 400 caracteres por rascunho.
8. No máximo 1 emoji por rascunho (pode ser zero).

FORMATO DE SAÍDA — retorne SOMENTE este JSON, sem markdown, sem explicação adicional:
{"templates": [{"texto": "..."}, {"texto": "..."}]}
PROMPT,
            ],
        ];
    }

    private function contextoOuPadrao(?string $contexto): string
    {
        $contexto = trim((string) $contexto);
        return $contexto !== '' ? $contexto : 'nenhum';
    }

    /**
     * Confirma que o rascunho respeita as regras que importam de verdade
     * pro modelo de negócio: sem estrela embutida, com o marcador de
     * empresa presente, e o marcador de atendente só quando permitido.
     */
    private function respeitaRegras(string $texto, bool $incluirNomeAtendente): bool
    {
        if (str_contains($texto, '⭐') || str_contains($texto, '★') || str_contains($texto, '✩')) {
            return false;
        }

        if (! str_contains($texto, '[nome da empresa]')) {
            return false;
        }

        if (! $incluirNomeAtendente && str_contains($texto, '[nome de quem te atendeu]')) {
            return false;
        }

        return true;
    }

    private function codigoUnico(int $tenantId): string
    {
        for ($tentativa = 0; $tentativa < 20; $tentativa++) {
            $codigo = 'IA-' . strtoupper(Str::random(5));
            $existe = TemplateAvaliacao::where('tenant_id', $tenantId)->where('codigo', $codigo)->exists();
            if (! $existe) {
                return $codigo;
            }
        }

        // Fallback improvável (20 colisões seguidas): usa timestamp pra garantir unicidade.
        return 'IA-' . now()->format('YmdHisv');
    }
}
