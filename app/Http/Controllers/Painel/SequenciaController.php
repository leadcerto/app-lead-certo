<?php

namespace App\Http\Controllers\Painel;

use App\Http\Controllers\Controller;
use App\Models\Sequencia;
use App\Models\SequenciaMensagem;
use App\Services\OpenRouterService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class SequenciaController extends Controller
{
    // ── Sequências (pai) ─────────────────────────────────────────────────────

    public function index(Request $request): JsonResponse
    {
        $sequencias = Sequencia::where('tenant_id', $request->user()->tenant_id)
            ->withCount('mensagens')
            ->orderBy('coluna_kanban')
            ->orderBy('nome')
            ->get();

        return response()->json($sequencias);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'nome'          => 'required|string|max:100',
            'descricao'     => 'nullable|string|max:300',
            'coluna_kanban' => 'nullable|string|max:50',
            'ativo'         => 'sometimes|boolean',
        ]);

        $sequencia = Sequencia::create(array_merge($validated, [
            'tenant_id' => $request->user()->tenant_id,
        ]));

        return response()->json($sequencia->loadCount('mensagens'), 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $sequencia = Sequencia::where('id', $id)
            ->where('tenant_id', $request->user()->tenant_id)
            ->firstOrFail();

        $validated = $request->validate([
            'nome'          => 'sometimes|string|max:100',
            'descricao'     => 'sometimes|nullable|string|max:300',
            'coluna_kanban' => 'sometimes|nullable|string|max:50',
            'ativo'         => 'sometimes|boolean',
        ]);

        $sequencia->update($validated);

        return response()->json($sequencia->loadCount('mensagens'));
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        Sequencia::where('id', $id)
            ->where('tenant_id', $request->user()->tenant_id)
            ->firstOrFail()
            ->delete();

        return response()->json(['ok' => true]);
    }

    // ── Mensagens (filhas) ────────────────────────────────────────────────────

    public function mensagens(Request $request, int $sequenciaId): JsonResponse
    {
        $sequencia = Sequencia::where('id', $sequenciaId)
            ->where('tenant_id', $request->user()->tenant_id)
            ->firstOrFail();

        return response()->json(
            $sequencia->mensagens()->get(['id', 'ordem', 'conteudo', 'imagem_url', 'delay_segundos', 'ativo'])
        );
    }

    public function storeMensagem(Request $request, int $sequenciaId): JsonResponse
    {
        $sequencia = Sequencia::where('id', $sequenciaId)
            ->where('tenant_id', $request->user()->tenant_id)
            ->firstOrFail();

        $validated = $request->validate([
            'conteudo'       => 'nullable|string|max:1000',
            'delay_segundos' => 'required|integer|min:0|max:604800',
            'imagem'         => 'nullable|file|image|max:5120',
        ]);

        if (empty($validated['conteudo']) && ! $request->hasFile('imagem')) {
            return response()->json(['message' => 'Informe um texto ou imagem.'], 422);
        }

        $imagemUrl = null;
        if ($request->hasFile('imagem')) {
            $path      = $request->file('imagem')->store('sequencia-imgs', 'public');
            $imagemUrl = config('app.url') . '/storage/' . $path;
        }

        $ordem = $sequencia->mensagens()->max('ordem') + 1;

        $msg = SequenciaMensagem::create([
            'sequencia_id'   => $sequenciaId,
            'tenant_id'      => $sequencia->tenant_id,
            'ordem'          => $ordem,
            'conteudo'       => $validated['conteudo'] ?? '',
            'imagem_url'     => $imagemUrl,
            'delay_segundos' => $validated['delay_segundos'],
            'ativo'          => true,
        ]);

        return response()->json($msg, 201);
    }

    public function updateMensagem(Request $request, int $sequenciaId, int $id): JsonResponse
    {
        $sequencia = Sequencia::where('id', $sequenciaId)
            ->where('tenant_id', $request->user()->tenant_id)
            ->firstOrFail();

        $msg = SequenciaMensagem::where('id', $id)
            ->where('sequencia_id', $sequenciaId)
            ->firstOrFail();

        $validated = $request->validate([
            'conteudo'       => 'sometimes|nullable|string|max:1000',
            'delay_segundos' => 'sometimes|integer|min:0|max:604800',
            'ativo'          => 'sometimes|boolean',
            'ordem'          => 'sometimes|integer|min:1',
            'imagem'         => 'sometimes|nullable|file|image|max:5120',
            'remover_imagem' => 'sometimes|boolean',
        ]);

        if ($request->hasFile('imagem')) {
            if ($msg->imagem_url) {
                Storage::disk('public')->delete(str_replace(config('app.url') . '/storage/', '', $msg->imagem_url));
            }
            $path = $request->file('imagem')->store('sequencia-imgs', 'public');
            $validated['imagem_url'] = config('app.url') . '/storage/' . $path;
        }

        if ($request->boolean('remover_imagem')) {
            if ($msg->imagem_url) {
                Storage::disk('public')->delete(str_replace(config('app.url') . '/storage/', '', $msg->imagem_url));
            }
            $validated['imagem_url'] = null;
        }

        unset($validated['imagem'], $validated['remover_imagem']);
        $msg->update($validated);

        return response()->json($msg);
    }

    public function destroyMensagem(Request $request, int $sequenciaId, int $id): JsonResponse
    {
        $sequencia = Sequencia::where('id', $sequenciaId)
            ->where('tenant_id', $request->user()->tenant_id)
            ->firstOrFail();

        SequenciaMensagem::where('id', $id)
            ->where('sequencia_id', $sequenciaId)
            ->firstOrFail()
            ->delete();

        // Reordena
        $sequencia->mensagens()->orderBy('ordem')->get()
            ->each(fn ($m, $i) => $m->update(['ordem' => $i + 1]));

        return response()->json(['ok' => true]);
    }

    /** Analisa todas as mensagens de uma sequência via IA e sugere inserção de variáveis. */
    public function sugerirVariaveis(Request $request, int $id, OpenRouterService $openRouter): JsonResponse
    {
        $sequencia = Sequencia::where('id', $id)
            ->where('tenant_id', $request->user()->tenant_id)
            ->firstOrFail();

        $mensagens = $sequencia->mensagens()
            ->where('ativo', true)
            ->where(fn ($q) => $q->whereNotNull('conteudo')->where('conteudo', '!=', ''))
            ->get(['id', 'ordem', 'conteudo']);

        if ($mensagens->isEmpty()) {
            return response()->json(['sugestoes' => []]);
        }

        $labelColuna = match ($sequencia->coluna_kanban) {
            'lead_novo'            => 'Novo Lead — PRIMEIRO contato, lead nunca interagiu antes',
            'em_atendimento'       => 'Em Atendimento — lead está em conversa ativa',
            'aguardando_orcamento' => 'Aguardando Orçamento — lead qualificado aguardando proposta',
            'aguardando_lead'      => 'Aguardando Lead — follow-up após envio do orçamento (lead sumiu)',
            'pagamento'            => 'Pagamento — orçamento aprovado, aguardando sinal do lead',
            'servico_agendado'     => 'Serviço Agendado — confirmação e orientações pré-serviço',
            'encerrado'            => 'Encerrado — agradecimento e encerramento do atendimento',
            default                => $sequencia->coluna_kanban,
        };

        $listaTexto = $mensagens->map(fn ($m) =>
            "[MSG:{$m->id}]\n{$m->conteudo}"
        )->implode("\n\n---\n\n");

        $messages = [
            [
                'role'    => 'system',
                'content' => 'Você é especialista em copywriting para WhatsApp de vendas no Brasil. Melhora mensagens de sequência inserindo variáveis de personalização onde ficam 100% naturais e fluidas. Responde SEMPRE com JSON válido.',
            ],
            [
                'role'    => 'user',
                'content' => <<<PROMPT
SEQUÊNCIA: "{$sequencia->nome}"
CONTEXTO: {$labelColuna}

VARIÁVEIS DISPONÍVEIS:
• {saudacao_tempo}    → "Bom dia" / "Boa tarde" / "Boa noite" — detectado automaticamente pelo horário
• {nome}              → primeiro nome do lead
• {empresa}           → nome da empresa prestadora do serviço
• {tempo_passado}     → "ontem" / "há 2 dias" / "na semana passada" — USE SÓ em follow-ups
• {abertura_casual}   → ex: "Passando rapidinho aqui", "Vim dar uma olhadinha", "Só passando pra checar"
• {despedida_casual}  → ex: "Qualquer dúvida, é só chamar!", "Estou aqui se precisar!", "Fico à disposição!"
• {motivo_contato}    → ex: "vim verificar se ficou alguma dúvida", "passei para checar se você viu" — USE SÓ em follow-ups
• {gatilho_urgencia}  → ex: "nossa agenda está se esgotando rápido", "preciso fechar a rota amanhã" — USE SÓ em follow-ups
• {reforco_valor}     → ex: "nossa equipe embala tudo com cuidado", "nosso serviço inclui seguro total" — USE SÓ em follow-ups
• {cta_fechamento}    → ex: "podemos fechar hoje?", "o que falta para fecharmos negócio?" — USE SÓ em follow-ups
• {abertura_empatica} → ex: "sei que organizar uma mudança é muito estressante" — USE SÓ em follow-ups
• {termo_servico}     → ex: "mudança", "frete", "transporte dos seus móveis"

REGRAS ABSOLUTAS:
1. Para PRIMEIRO CONTATO (lead_novo): use APENAS {saudacao_tempo} e {nome} — NADA mais
2. Para FOLLOW-UP (aguardando_lead, aguardando_orcamento): use livremente todas as variáveis
3. Se a mensagem já tem saudação fixa ("Olá!", "Oi!"), substitua por {saudacao_tempo}, {nome}
4. Se a mensagem cita o nome do lead fixo, substitua por {nome}
5. NUNCA force variável onde o resultado soará artificial ou repetitivo
6. Mantenha TODOS os emojis, formatação *negrito*, pontuação e quebras de linha IDÊNTICOS
7. Se a mensagem não se beneficia de variáveis, retorne o texto EXATAMENTE igual

FORMATO DE RESPOSTA — retorne SOMENTE este JSON, sem markdown, sem explicação adicional:
[{"id": 123, "texto": "texto modificado aqui"}, {"id": 456, "texto": "outro texto"}, ...]

MENSAGENS PARA PROCESSAR:
{$listaTexto}
PROMPT,
            ],
        ];

        $resposta = $openRouter->chat($messages, 'complexo', 3000);

        if (! $resposta) {
            return response()->json(['message' => 'IA temporariamente indisponível. Tente novamente em instantes.'], 503);
        }

        // Remove markdown fences se presentes
        $limpo = trim(preg_replace('/^```(?:json)?\s*|\s*```$/m', '', $resposta));
        $json  = json_decode($limpo, true);

        if (! is_array($json)) {
            Log::warning('sugerirVariaveis: resposta IA não é JSON válido', [
                'sequencia_id' => $id,
                'resposta'     => mb_substr($resposta, 0, 500),
            ]);
            return response()->json(['message' => 'A IA retornou um formato inesperado. Tente novamente.'], 422);
        }

        $porId = collect($json)->keyBy('id');

        $sugestoes = $mensagens->map(function ($msg) use ($porId) {
            $sug      = $porId->get($msg->id);
            $sugerido = $sug ? (string) ($sug['texto'] ?? $msg->conteudo) : $msg->conteudo;
            return [
                'id'       => $msg->id,
                'ordem'    => $msg->ordem,
                'original' => $msg->conteudo,
                'sugerido' => $sugerido,
                'alterado' => $sugerido !== $msg->conteudo,
            ];
        });

        return response()->json(['sugestoes' => $sugestoes]);
    }
}
