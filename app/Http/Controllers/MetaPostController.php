<?php

namespace App\Http\Controllers;

use App\Models\MetaCampanhaGatilho;
use App\Models\MetaContaInstagram;
use App\Models\MetaPagina;
use App\Models\MetaPost;
use App\Services\MetaPostPublishService;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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
}
