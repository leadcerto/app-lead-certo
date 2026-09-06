<?php

namespace App\Http\Controllers;

use App\Models\MetaCampanhaGatilho;
use App\Models\MetaContaInstagram;
use App\Models\MetaPagina;
use App\Models\MetaPost;
use App\Services\MetaPostPublishService;
use App\Services\OpenRouterService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;

class MetaPostController extends Controller
{
    private function getTenantId(Request $request): int
    {
        $user = $request->user();
        if ($user->podeTrocarTenant()) {
            return (int) ($request->query('tenant_id') ?? session('tenant_id') ?? $user->tenant_id);
        }
        return (int) $user->tenant_id;
    }

    public function index(Request $request): View
    {
        $tenantId = $this->getTenantId($request);

        $semana = $request->filled('semana') ? Carbon::parse($request->semana) : now();
        $inicioSemana = $semana->copy()->startOfWeek(Carbon::MONDAY)->startOfDay();
        $fimSemana    = $semana->copy()->endOfWeek(Carbon::SUNDAY)->endOfDay();

        $postsSemana = MetaPost::with(['pagina', 'contaInstagram', 'autor'])
            ->where('tenant_id', $tenantId)
            ->whereBetween('data_agendada', [$inicioSemana, $fimSemana])
            ->orderBy('data_agendada')
            ->get();

        $postsPorDia = $postsSemana->groupBy(fn ($post) => $post->data_agendada->translatedFormat('l, d/m/Y'));

        $paginas = MetaPagina::where('tenant_id', $tenantId)->where('ativo', true)->get();
        $contasInstagram = MetaContaInstagram::where('tenant_id', $tenantId)->where('ativo', true)->get();

        $stats = [
            'total_semana' => $postsSemana->count(),
            'agendados'    => $postsSemana->where('status', 'agendado')->count(),
            'publicados'   => $postsSemana->where('status', 'publicado')->count(),
            'falhas'       => $postsSemana->where('status', 'falha')->count(),
        ];

        return view('meta-posts.index', compact('postsPorDia', 'paginas', 'contasInstagram', 'stats', 'semana', 'postsSemana'));
    }

    public function create(Request $request): View
    {
        $tenantId = $this->getTenantId($request);
        $paginas = MetaPagina::where('tenant_id', $tenantId)->where('ativo', true)->get();
        $contasInstagram = MetaContaInstagram::where('tenant_id', $tenantId)->where('ativo', true)->get();
        $imagensGaleria = \App\Models\GmbPostImagem::where('tenant_id', $tenantId)->orderByDesc('id')->get();
        $templatesTexto = \App\Models\GmbPostTemplate::where('tenant_id', $tenantId)->where('ativo', true)->get();

        return view('meta-posts.create', compact('paginas', 'contasInstagram', 'imagensGaleria', 'templatesTexto'));
    }

    public function store(Request $request, MetaPostPublishService $publishService): RedirectResponse
    {
        $tenantId = $this->getTenantId($request);

        if (in_array($request->canal_alvo, ['instagram', 'ambos']) && ! $request->filled('meta_conta_instagram_id')) {
            $unicaConta = MetaContaInstagram::where('tenant_id', $tenantId)->where('ativo', true)->first();
            if ($unicaConta) {
                $request->merge(['meta_conta_instagram_id' => $unicaConta->id]);
            }
        }
        if (in_array($request->canal_alvo, ['facebook', 'ambos']) && ! $request->filled('meta_pagina_id')) {
            $unicaPagina = MetaPagina::where('tenant_id', $tenantId)->where('ativo', true)->first();
            if ($unicaPagina) {
                $request->merge(['meta_pagina_id' => $unicaPagina->id]);
            }
        }

        $validated = $request->validate([
            'canal_alvo'                  => 'required|in:facebook,instagram,ambos',
            'meta_pagina_id'              => 'required_if:canal_alvo,facebook,ambos|nullable|exists:meta_paginas,id',
            'meta_conta_instagram_id'     => 'required_if:canal_alvo,instagram,ambos|nullable|exists:meta_contas_instagram,id',
            'texto'                       => 'required|string|max:2200',
            'imagem'                      => 'nullable|image|max:10240',
            'imagem_url'                  => 'nullable|url',
            'cta_tipo'                    => 'nullable|in:NENHUM,BOOK,ORDER,SHOP,LEARN_MORE,SIGN_UP,CALL',
            'cta_url'                     => 'nullable|url',
            'modo_gatilho'                => 'required|in:nenhum,qualquer_comentario,palavra_chave',
            'palavras_chave_texto'        => 'nullable|string',
            'resposta_publica_comentario' => 'nullable|string|max:500',
            'mensagem_direct'             => 'nullable|string|max:1000',
            'data_agendada'               => 'nullable|date',
            'publicar_imediato'           => 'nullable|boolean',
        ], [
            'meta_pagina_id.required_if'          => 'Selecione a Página do Facebook para publicar.',
            'meta_conta_instagram_id.required_if' => 'Selecione a Conta do Instagram para publicar.',
            'texto.required'                      => 'O texto / legenda da publicação é obrigatório.',
            'cta_url.url'                         => 'O link do botão WhatsApp / CTA precisa ser uma URL válida (ex: https://...).',
        ]);

        $imagemUrl = $validated['imagem_url'] ?? null;
        if ($request->hasFile('imagem')) {
            $caminho = $request->file('imagem')->store('meta-posts', 'public');
            $imagemUrl = Storage::disk('public')->url($caminho);
        }

        $palavrasArray = [];
        if (! empty($validated['palavras_chave_texto'])) {
            $palavrasArray = array_values(array_filter(array_map('trim', explode(',', $validated['palavras_chave_texto']))));
        }

        $dataAgendada = ! empty($validated['publicar_imediato'])
            ? now()
            : ($validated['data_agendada'] ? Carbon::parse($validated['data_agendada']) : now());

        $post = MetaPost::create([
            'tenant_id'                   => $tenantId,
            'user_id'                     => $request->user()->id,
            'canal_alvo'                  => $validated['canal_alvo'],
            'meta_pagina_id'              => $validated['meta_pagina_id'] ?? null,
            'meta_conta_instagram_id'     => $validated['meta_conta_instagram_id'] ?? null,
            'texto'                       => $validated['texto'],
            'imagem_url'                  => $imagemUrl,
            'cta_tipo'                    => $validated['canal_alvo'] !== 'instagram' ? ($validated['cta_tipo'] ?? 'NENHUM') : 'NENHUM',
            'cta_url'                     => $validated['canal_alvo'] !== 'instagram' ? ($validated['cta_url'] ?? null) : null,
            'modo_gatilho'                => $validated['modo_gatilho'],
            'palavras_chave'              => $palavrasArray,
            'resposta_publica_comentario' => $validated['resposta_publica_comentario'] ?? null,
            'mensagem_direct'             => $validated['mensagem_direct'] ?? null,
            'data_agendada'               => $dataAgendada,
            'status'                      => 'agendado',
        ]);

        if (! empty($validated['publicar_imediato'])) {
            $sucesso = $publishService->publicar($post);
            if ($sucesso) {
                return redirect()->route('meta-posts.index', ['semana' => $dataAgendada->toDateString()])
                    ->with('sucesso', 'Post publicado no Facebook/Instagram com sucesso!');
            }

            return redirect()->route('meta-posts.index', ['semana' => $dataAgendada->toDateString()])
                ->with('erro', 'Falha ao publicar: ' . ($post->fresh()->log_erro ?? 'Erro desconhecido.'));
        }

        return redirect()->route('meta-posts.index', ['semana' => $dataAgendada->toDateString()])
            ->with('sucesso', 'Postagem agendada com sucesso!');
    }

    public function publicarAgora(MetaPost $post, MetaPostPublishService $publishService): RedirectResponse
    {
        $sucesso = $publishService->publicar($post);

        if ($sucesso) {
            return back()->with('sucesso', 'Post publicado no Facebook/Instagram com sucesso!');
        }

        $erro = $post->fresh()->log_erro ?: 'Erro desconhecido ao comunicar com a Meta.';
        return back()->with('erro', 'Falha ao publicar: ' . $erro);
    }

    public function destroy(MetaPost $post): RedirectResponse
    {
        $post->update(['status' => 'cancelado']);

        MetaCampanhaGatilho::where('meta_post_id', $post->id)->update(['ativo' => false]);

        return back()->with('sucesso', 'Postagem cancelada.');
    }

    public function gerarIa(Request $request, OpenRouterService $openRouter): JsonResponse
    {
        $request->validate([
            'canal_alvo' => 'nullable|string|in:facebook,instagram,ambos',
            'objetivo'   => 'nullable|string|max:300',
            'tema'       => 'nullable|string|max:300',
            'palavra'    => 'nullable|string|max:50',
        ]);

        $tenantId = $this->getTenantId($request);
        $tenant = \App\Models\Tenant::find($tenantId);
        $nomeEmpresa = $tenant?->nome ?? 'Frete Rio';

        $canal = $request->canal_alvo ?: 'ambos';
        $objetivo = $request->objetivo ?: 'Atrair clientes para fretes, carretos e mudanças residenciais ou comerciais com resposta rápida no WhatsApp';
        $tema = $request->tema ?: 'Fretes rápidos e mudanças seguras no Rio de Janeiro e Grande Rio';
        $palavraSugerida = strtoupper(trim($request->palavra ?: 'QUERO'));

        $linkWhatsApp = 'https://api.whatsapp.com/send/?phone=5521981813106&text=Ol%C3%A1%2C+gostaria+de+um+or%C3%A7amento+de+frete+no+Rio+de+Janeiro%21+%28Vi+no+' . ($canal === 'instagram' ? 'Instagram' : 'Facebook') . '%29&type=phone_number&app_absent=0';

        $promptSistema = "Você é um Copywriter Sênior Especialista em Meta Ads e Conteúdo Orgânico para Facebook e Instagram no Brasil, focado no nicho de Fretes, Carretos, Mudanças e Logística Local.\n"
            . "Sua missão é criar um post persuasivo de altíssima conversão que gere engajamento instantâneo nos comentários e direcione o cliente para o WhatsApp.\n\n"
            . "REGRAS MANDATÓRIAS:\n"
            . "1. O post deve ter uma primeira linha (gancho) irresistível que prenda a atenção de quem está rolando o feed.\n"
            . "2. O corpo do texto deve ressaltar confiança, cuidado com os móveis/cargas, pontualidade, agilidade e preço justo.\n"
            . "3. Uso estratégico de emojis brasileiros (🚚, 📦, ⚡, 🔒, 📍, etc.).\n"
            . "4. CTA OBRIGATÓRIO PARA COMENTÁRIO (Comment-to-DM): Instruir expressamente o seguidor a comentar a palavra '{$palavraSugerida}' para receber o orçamento promocional ou tabela de preços no Direct.\n"
            . "5. Resposta pública do comentário: uma resposta cordial, alegre e direta informando que o direct foi enviado.\n"
            . "6. Mensagem privada do Direct: uma mensagem acolhedora, objetiva e que já entregue o link do WhatsApp para fechar negócio imediatamente.\n"
            . "7. Retorne EXCLUSIVAMENTE um JSON válido com a estrutura especificada, sem blocos de código adicionais além do JSON.\n";

        $promptUsuario = "Gere uma publicação para as redes da empresa {$nomeEmpresa}.\n"
            . "- Canal: {$canal}\n"
            . "- Objetivo: {$objetivo}\n"
            . "- Tema/Detalhes: {$tema}\n"
            . "- Palavra-chave do gatilho: {$palavraSugerida}\n"
            . "- Link oficial do WhatsApp: {$linkWhatsApp}\n\n"
            . "Formato JSON de retorno:\n"
            . "{\n"
            . '  "texto": "Texto completo da legenda para Facebook/Instagram com emojis, chamada para comentar ' . $palavraSugerida . ' e hashtags no final",' . "\n"
            . '  "palavra_chave": "' . $palavraSugerida . '",' . "\n"
            . '  "resposta_publica_comentario": "Resposta amigável que a página dará publicamente ao seguidor (Ex: Acabei de te enviar no direct os detalhes! 🚚✨)",' . "\n"
            . '  "mensagem_direct": "Mensagem privada completa que cairá no Direct com a saudação e o link do WhatsApp ' . $linkWhatsApp . '",' . "\n"
            . '  "dica_engajamento": "Dica rápida de por que este post gerará comentários e leads no Direct"' . "\n"
            . "}";

        try {
            $resposta = $openRouter->chat(
                messages: [
                    ['role' => 'system', 'content' => $promptSistema],
                    ['role' => 'user', 'content' => $promptUsuario],
                ],
                tier: 'complexo',
                maxTokens: 1400,
                origem: 'meta_post_gerar_ia',
                tenantId: $tenantId,
            );

            if ($resposta) {
                $jsonLimpo = trim($resposta);
                if (str_starts_with($jsonLimpo, '```')) {
                    $jsonLimpo = preg_replace('/^```(?:json)?\s*/i', '', $jsonLimpo);
                    $jsonLimpo = preg_replace('/\s*```$/', '', $jsonLimpo);
                }

                $dados = json_decode($jsonLimpo, true);
                if (json_last_error() === JSON_ERROR_NONE && isset($dados['texto'])) {
                    return response()->json([
                        'success' => true,
                        'data' => $dados,
                    ]);
                }
            }
        } catch (\Exception $e) {
            Log::error('MetaPostController::gerarIa erro', ['erro' => $e->getMessage()]);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'texto' => "Precisa de Frete ou Mudança no Rio de Janeiro sem dor de cabeça e com preço justo? 🚚📦\n\nNa {$nomeEmpresa} cuidamos de tudo com pontualidade, proteção máxima para seus pertences e agilidade de ponta a ponta!\n\n👉 Comente \"{$palavraSugerida}\" aqui embaixo que te envio o orçamento com desconto exclusivo no Direct agora mesmo! ⚡\n\n#FreteRJ #MudançaRio #TransporteRJ #FreteZonaSul #FreteBarra",
                'palavra_chave' => $palavraSugerida,
                'resposta_publica_comentario' => "Acabei de te enviar todos os detalhes e valores com desconto no seu Direct! 🚚✨ Dá uma olhadinha lá!",
                'mensagem_direct' => "Olá! Tudo bem? Vi que você comentou na nossa postagem sobre fretes e mudanças! 📦🚚\n\nPara agilizar o seu atendimento com prioridade, fale diretamente com nossa equipe no WhatsApp pelo link:\n{$linkWhatsApp}\n\nEstamos prontos para te atender!",
                'dica_engajamento' => 'A chamada clara para comentar "QUERO" estimula os algoritmos do Instagram e Facebook a entregarem o post para mais pessoas na sua região.',
            ],
        ]);
    }
}
