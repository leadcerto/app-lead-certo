<?php

namespace App\Http\Controllers;

use App\Models\GmbPost;
use App\Models\GmbPostTemplate;
use App\Models\PerfilGmb;
use App\Services\GmbPostIaService;
use App\Services\GmbPostPublishService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class GmbPostController extends Controller
{
    public function index(Request $request): View
    {
        $tenantId = auth()->user()->tenant_id;

        // Controle da Semana (padrão: semana atual)
        $semana = $request->filled('semana')
            ? Carbon::parse($request->semana)
            : now();

        $inicioSemana = $semana->copy()->startOfWeek(Carbon::SUNDAY)->startOfDay();
        $fimSemana    = $semana->copy()->endOfWeek(Carbon::SATURDAY)->endOfDay();

        $query = GmbPost::with(['perfil', 'autor'])
            ->where('tenant_id', $tenantId)
            ->whereBetween('data_agendada', [$inicioSemana, $fimSemana])
            ->orderBy('data_agendada');

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('perfil_id')) {
            $query->where('perfil_gmb_id', $request->perfil_id);
        }

        $postsSemana = $query->get();

        // Agrupamento por dia da semana em português
        $postsPorDia = $postsSemana->groupBy(function ($post) {
            return $post->data_agendada->translatedFormat('l, d/m/Y');
        });

        $perfis = PerfilGmb::where('tenant_id', $tenantId)->where('ativo', true)->get();

        $stats = [
            'total_semana' => $postsSemana->count(),
            'agendados'    => $postsSemana->where('status', 'agendado')->count(),
            'publicados'   => $postsSemana->where('status', 'publicado')->count(),
            'falhas'       => $postsSemana->where('status', 'falha')->count(),
        ];

        return view('gmb-posts.index', compact('postsPorDia', 'perfis', 'stats', 'semana', 'postsSemana'));
    }

    public function create(): View
    {
        $tenantId = auth()->user()->tenant_id;
        $perfis = PerfilGmb::where('tenant_id', $tenantId)->where('ativo', true)->get();
        $templates = GmbPostTemplate::where('tenant_id', $tenantId)->where('ativo', true)->get();

        return view('gmb-posts.create', compact('perfis', 'templates'));
    }

    public function store(Request $request, GmbPostPublishService $publishService, \App\Services\GmbImageSeoService $seoService): RedirectResponse
    {
        $tenantId = auth()->user()->tenant_id;
        $tenant = auth()->user()->tenant;

        $validated = $request->validate([
            'perfil_gmb_id'     => 'required|array|min:1',
            'perfil_gmb_id.*'   => 'exists:perfis_gmb,id',
            'tipo'              => 'required|in:novidade,oferta,evento',
            'titulo'            => 'nullable|string|max:100',
            'texto'             => 'required|string|max:1500',
            'imagem'            => 'nullable|image|max:10240',
            'imagem_url'        => 'nullable|url',
            'cta_tipo'          => 'required|in:LEARN_MORE,CALL,ORDER,BOOK,SIGN_UP,SHOP,NENHUM',
            'cta_url'           => 'nullable|url',
            'codigo_cupom'      => 'nullable|string|max:50',
            'link_resgate'      => 'nullable|url',
            'data_agendada'     => 'nullable|date',
            'publicar_imediato' => 'nullable|boolean',
            'gerado_por_ia'     => 'nullable|boolean',
        ]);

        $perfis = PerfilGmb::where('tenant_id', $tenantId)
            ->whereIn('id', $validated['perfil_gmb_id'])
            ->get();

        $dataAgendada = !empty($validated['publicar_imediato'])
            ? now()
            : ($validated['data_agendada'] ? Carbon::parse($validated['data_agendada']) : now());

        $imagemUrlFixa = $validated['imagem_url'] ?? null;
        $arquivoImagem = $request->hasFile('imagem') ? $request->file('imagem') : null;
        $publicarImediato = !empty($validated['publicar_imediato']);

        $criados = 0;
        $publicados = 0;
        $falhas = [];

        foreach ($perfis as $perfil) {
            // Cada perfil recebe sua própria cópia da imagem, renomeada com o
            // bairro/keywords específicos dele (mesmo padrão do Gerador em Lote).
            $imagemUrl = $imagemUrlFixa;
            if ($arquivoImagem) {
                $imagemUrl = $seoService->salvarImagemSeo(
                    $arquivoImagem,
                    $tenant,
                    $perfil,
                    $dataAgendada,
                    $validated['titulo'] ?? null
                );
            }

            $post = GmbPost::create([
                'tenant_id'     => $tenantId,
                'perfil_gmb_id' => $perfil->id,
                'autor_user_id' => auth()->id(),
                'tipo'          => $validated['tipo'],
                'titulo'        => $validated['titulo'] ?? null,
                'texto'         => $validated['texto'],
                'imagem_url'    => $imagemUrl,
                'cta_tipo'      => $validated['cta_tipo'],
                'cta_url'       => $validated['cta_url'] ?? null,
                'codigo_cupom'  => $validated['codigo_cupom'] ?? null,
                'link_resgate'  => $validated['link_resgate'] ?? null,
                'data_agendada' => $dataAgendada,
                'status'        => $publicarImediato ? 'processando' : 'agendado',
                'gerado_por_ia' => !empty($validated['gerado_por_ia']),
            ]);
            $criados++;

            if ($publicarImediato) {
                $sucesso = $publishService->publicar($post);
                if ($sucesso) {
                    $publicados++;
                } else {
                    $falhas[] = $perfil->nome . ': ' . ($post->fresh()->log_erro ?: 'erro desconhecido');
                }
            }
        }

        if ($publicarImediato) {
            if ($publicados === $criados) {
                return redirect()->route('admin.gmb-posts.index', ['semana' => $dataAgendada->toDateString()])
                    ->with('sucesso', $criados === 1
                        ? 'Publicação enviada com sucesso para o Google Meu Negócio!'
                        : "Publicação enviada com sucesso para os {$publicados} perfis selecionados!");
            }

            if ($publicados > 0) {
                return redirect()->route('admin.gmb-posts.index', ['semana' => $dataAgendada->toDateString()])
                    ->with('aviso', "{$publicados} de {$criados} publicados. Falhas: " . implode(' | ', $falhas));
            }

            return redirect()->route('admin.gmb-posts.index', ['semana' => $dataAgendada->toDateString()])
                ->with('erro', 'Falha ao publicar em todos os perfis selecionados: ' . implode(' | ', $falhas));
        }

        return redirect()->route('admin.gmb-posts.index', ['semana' => $dataAgendada->toDateString()])
            ->with('sucesso', $criados === 1
                ? "Post agendado para {$dataAgendada->format('d/m/Y H:i')}!"
                : "{$criados} posts agendados para {$dataAgendada->format('d/m/Y H:i')} (1 por perfil selecionado)!");
    }

    // ── Gerador em Lote (Matriz Semanal) ──────────────────────────────────

    public function lote(Request $request): View
    {
        $tenantId = auth()->user()->tenant_id;

        $semana = $request->filled('semana')
            ? Carbon::parse($request->semana)
            : now();

        $perfis = PerfilGmb::where('tenant_id', $tenantId)
            ->where('ativo', true)
            ->orderBy('nome')
            ->get();

        $templates = GmbPostTemplate::where('tenant_id', $tenantId)
            ->where('ativo', true)
            ->get();

        // Se não houver templates, gera os templates padrão na hora
        if ($templates->isEmpty()) {
            (new \Database\Seeders\GmbPostTemplateSeeder())->run();
            $templates = GmbPostTemplate::where('tenant_id', $tenantId)->where('ativo', true)->get();
        }

        $imagensSalvas = \App\Models\GmbPostImagem::where('tenant_id', $tenantId)->latest()->get();

        return view('gmb-posts.lote', compact('perfis', 'templates', 'semana', 'imagensSalvas'));
    }

    public function storeLote(Request $request, GmbPostIaService $iaService, \App\Services\GmbImageSeoService $seoService): RedirectResponse
    {
        $tenantId = auth()->user()->tenant_id;
        $tenant = auth()->user()->tenant;

        $validated = $request->validate([
            'matriz'            => 'required|array',
            'semana_referencia' => 'required|date',
            'modo_conteudo'     => 'required|in:template_rotativo,template_especifico,ia',
            'template_id'       => 'nullable|exists:gmb_post_templates,id',
            'modo_imagem'       => 'nullable|in:galeria_rotativa,galeria_especifica,upload,nenhuma',
            'imagem_galeria_id' => 'nullable|exists:gmb_post_imagens,id',
            'imagem_padrao'     => 'nullable|image|max:10240',
            'imagem_url'        => 'nullable|url',
        ]);

        $semana = Carbon::parse($validated['semana_referencia']);
        $inicioSemana = $semana->copy()->startOfWeek(Carbon::SUNDAY);
        $diasMap = ['domingo' => 0, 'segunda' => 1, 'terca' => 2, 'quarta' => 3, 'quinta' => 4, 'sexta' => 5, 'sabado' => 6];

        $templates = GmbPostTemplate::where('tenant_id', $tenantId)->where('ativo', true)->get();
        $imagensSalvas = \App\Models\GmbPostImagem::where('tenant_id', $tenantId)->get();

        $modoImagem = $validated['modo_imagem'] ?? ($imagensSalvas->isNotEmpty() ? 'galeria_rotativa' : ($request->hasFile('imagem_padrao') ? 'upload' : 'nenhuma'));

        $templateIndex = 0;
        $imagemIndex = 0;
        $criados = 0;

        foreach ($validated['matriz'] as $perfilId => $dias) {
            $perfil = PerfilGmb::where('tenant_id', $tenantId)->find($perfilId);
            if (!$perfil) continue;

            foreach ($dias as $dia => $valor) {
                $quantidade = (int) $valor;
                if ($quantidade <= 0) continue;
                if (!isset($diasMap[$dia])) continue;

                $dataBase = $inicioSemana->copy()->addDays($diasMap[$dia])->startOfDay();

                // Não reagendar dias que já passaram inteiramente nesta semana
                if ($dataBase->copy()->endOfDay()->isPast()) {
                    continue;
                }

                foreach ($this->calcularHorariosEspalhados($quantidade) as $minutosDesdeMeiaNoite) {
                $dataPost = $dataBase->copy()->addMinutes($minutosDesdeMeiaNoite);

                $titulo = null;
                $texto = '';
                $ctaTipo = 'CALL';
                $geradoPorIa = false;

                if ($validated['modo_conteudo'] === 'ia') {
                    $resIa = $iaService->gerarCopy(
                        perfil: $perfil,
                        tipo: 'novidade',
                        objetivo: 'Atrair clientes e ligações locais em ' . $perfil->nome,
                        tema: "Destaque dos serviços e atendimento premium em {$perfil->nome} ({$perfil->city})"
                    );
                    $titulo = $resIa['titulo'] ?? null;
                    $texto = $resIa['texto'] ?? "Atendimento completo e qualificado em {$perfil->nome}. Entre em contato conosco!";
                    $ctaTipo = $resIa['cta_tipo'] ?? 'CALL';
                    $geradoPorIa = true;
                } else {
                    $template = null;
                    if ($validated['modo_conteudo'] === 'template_especifico' && !empty($validated['template_id'])) {
                        $template = $templates->firstWhere('id', $validated['template_id']);
                    }
                    if (!$template && $templates->isNotEmpty()) {
                        $template = $templates[$templateIndex % $templates->count()];
                        $templateIndex++;
                    }

                    if ($template) {
                        $empresaNome = $tenant->nome ?? 'Nossa Empresa';
                        $bairroNome = $perfil->nome ?? 'sua região';
                        $cidadeNome = $perfil->city ?? 'sua cidade';

                        $titulo = str_replace(
                            ['{empresa}', '{bairro}', '{cidade}'],
                            [$empresaNome, $bairroNome, $cidadeNome],
                            $template->titulo_template
                        );
                        $texto = str_replace(
                            ['{empresa}', '{bairro}', '{cidade}'],
                            [$empresaNome, $bairroNome, $cidadeNome],
                            $template->texto_template
                        );
                        $ctaTipo = $template->cta_tipo_padrao ?? 'CALL';
                    } else {
                        $texto = "Conheça as soluções da " . ($tenant->nome ?? 'Lead Certo') . " em {$perfil->nome}. Ligue agora!";
                    }
                }

                // Processa imagem com SEO exclusivo para esta postagem
                $imagemUrl = null;
                if ($modoImagem === 'galeria_rotativa' && $imagensSalvas->isNotEmpty()) {
                    $imgEscolhida = $imagensSalvas[$imagemIndex % $imagensSalvas->count()];
                    $imagemIndex++;
                    $imagemUrl = $imgEscolhida->imagem_url;
                } elseif ($modoImagem === 'galeria_especifica' && !empty($validated['imagem_galeria_id'])) {
                    $imgEscolhida = $imagensSalvas->firstWhere('id', $validated['imagem_galeria_id']);
                    $imagemUrl = $imgEscolhida?->imagem_url;
                } elseif ($modoImagem === 'upload' && $request->hasFile('imagem_padrao')) {
                    $imagemUrl = $seoService->salvarImagemSeo(
                        $request->file('imagem_padrao'),
                        $tenant,
                        $perfil,
                        $dataPost,
                        $titulo
                    );
                } elseif (!empty($validated['imagem_url'])) {
                    $imagemUrl = $validated['imagem_url'];
                }

                $post = GmbPost::create([
                    'tenant_id'     => $tenantId,
                    'perfil_gmb_id' => $perfil->id,
                    'autor_user_id' => auth()->id(),
                    'tipo'          => 'novidade',
                    'titulo'        => $titulo,
                    'texto'         => $texto,
                    'imagem_url'    => $imagemUrl,
                    'cta_tipo'      => $ctaTipo,
                    'data_agendada' => $dataPost,
                    'status'        => 'agendado',
                    'gerado_por_ia' => $geradoPorIa,
                ]);

                // Aplica renomeação SEO individualizada para a postagem se a imagem for da galeria
                if ($imagemUrl) {
                    $seoService->prepararImagemParaPost($post);
                }

                $criados++;
                }
            }
        }

        return redirect()->route('admin.gmb-posts.index', ['semana' => $inicioSemana->toDateString()])
            ->with('sucesso', "{$criados} postagens agendadas com sucesso para a semana!");
    }

    /**
     * Calcula os horários (em minutos desde 00:00) para distribuir N posts do
     * mesmo perfil no mesmo dia dentro da janela 08:00–18:00, respeitando um
     * intervalo mínimo de 10 minutos entre publicações.
     *
     * @return int[] minutos desde a meia-noite, um por post
     */
    private function calcularHorariosEspalhados(int $quantidade): array
    {
        $inicioMin = 8 * 60;  // 08:00
        $fimMin    = 18 * 60; // 18:00
        $janela    = $fimMin - $inicioMin;
        $intervaloMinimo = 10;

        if ($quantidade <= 1) {
            return [$inicioMin + intdiv($janela, 2)]; // ~13:00
        }

        $intervalo = max($intervaloMinimo, intdiv($janela, $quantidade - 1));

        $horarios = [];
        for ($i = 0; $i < $quantidade; $i++) {
            $horarios[] = min($inicioMin + ($i * $intervalo), $fimMin);
        }

        return $horarios;
    }

    // ── Gestão de Templates de Postagens ──────────────────────────────────

    public function templates(Request $request): View
    {
        $tenantId = auth()->user()->tenant_id;

        $templates = GmbPostTemplate::where('tenant_id', $tenantId)
            ->orderBy('categoria')
            ->orderBy('titulo_template')
            ->get();

        if ($templates->isEmpty()) {
            (new \Database\Seeders\GmbPostTemplateSeeder())->run();
            $templates = GmbPostTemplate::where('tenant_id', $tenantId)->get();
        }

        $templatesPorCategoria = $templates->groupBy('categoria');

        return view('gmb-posts.templates', compact('templates', 'templatesPorCategoria'));
    }

    public function storeTemplate(Request $request): RedirectResponse
    {
        $tenantId = auth()->user()->tenant_id;

        $validated = $request->validate([
            'categoria'       => 'required|string|max:50',
            'titulo_template' => 'required|string|max:150',
            'texto_template'  => 'required|string|max:1500',
            'cta_tipo_padrao' => 'required|in:CALL,LEARN_MORE,ORDER,BOOK,SIGN_UP,SHOP,NENHUM',
        ]);

        GmbPostTemplate::create([
            'tenant_id'       => $tenantId,
            'categoria'       => $validated['categoria'],
            'titulo_template' => $validated['titulo_template'],
            'texto_template'  => $validated['texto_template'],
            'cta_tipo_padrao' => $validated['cta_tipo_padrao'] ?? 'CALL',
            'ativo'           => true,
        ]);

        return back()->with('sucesso', 'Template de postagem criado com sucesso!');
    }

    public function updateTemplate(Request $request, GmbPostTemplate $template): RedirectResponse
    {
        $validated = $request->validate([
            'categoria'       => 'required|string|max:50',
            'titulo_template' => 'required|string|max:150',
            'texto_template'  => 'required|string|max:1500',
            'cta_tipo_padrao' => 'required|in:CALL,LEARN_MORE,ORDER,BOOK,SIGN_UP,SHOP,NENHUM',
        ]);

        $template->update([
            'categoria'       => $validated['categoria'],
            'titulo_template' => $validated['titulo_template'],
            'texto_template'  => $validated['texto_template'],
            'cta_tipo_padrao' => $validated['cta_tipo_padrao'] ?? 'CALL',
        ]);

        return back()->with('sucesso', "Template '{$template->titulo_template}' atualizado com sucesso!");
    }

    public function destroyTemplate(GmbPostTemplate $template): RedirectResponse
    {
        $template->delete();

        return back()->with('sucesso', 'Template de postagem removido.');
    }

    public function gerarTemplatesIa(Request $request, GmbPostIaService $iaService): RedirectResponse
    {
        $tenant = auth()->user()->tenant;
        $categoria = $request->input('categoria', 'promocoes');
        $quantidade = (int) $request->input('quantidade', 3);
        $nicho = $request->input('nicho', $tenant->nicho ?? 'Fretes e Mudanças');

        $gerados = $iaService->gerarTemplatesLote($tenant, $categoria, $quantidade, $nicho);
        $salvos = 0;

        foreach ($gerados as $item) {
            if (!empty($item['titulo_template']) && !empty($item['texto_template'])) {
                GmbPostTemplate::create([
                    'tenant_id'       => $tenant->id,
                    'categoria'       => $item['categoria'] ?? $categoria,
                    'titulo_template' => $item['titulo_template'],
                    'texto_template'  => $item['texto_template'],
                    'cta_tipo_padrao' => $item['cta_tipo_padrao'] ?? 'CALL',
                    'ativo'           => true,
                ]);
                $salvos++;
            }
        }

        return back()->with('sucesso', "{$salvos} novos templates focados em WhatsApp/Ligações foram gerados automaticamente com IA e adicionados!");
    }

    // ── Gestão de Categorias de Postagens ─────────────────────────────────

    public function categorias(Request $request): View
    {
        $tenantId = auth()->user()->tenant_id;

        $categorias = \App\Models\GmbPostCategoria::where(function ($q) use ($tenantId) {
                $q->where('tenant_id', $tenantId)->orWhereNull('tenant_id');
            })
            ->withCount('templates')
            ->orderBy('nome')
            ->get();

        if ($categorias->isEmpty()) {
            $padroes = [
                ['nome' => '🔥 Promoções & Ofertas', 'slug' => 'promocoes', 'palavras_chave' => ['desconto', 'promoção', 'economia', 'oferta da semana']],
                ['nome' => '💼 Serviços & Soluções', 'slug' => 'servicos', 'palavras_chave' => ['mudanças', 'fretes', 'transporte', 'residencial', 'comercial']],
                ['nome' => '💡 Dicas & Utilidade Pública', 'slug' => 'dicas', 'palavras_chave' => ['planejamento', 'embalagem', 'dicas de economia']],
                ['nome' => '⭐ Depoimentos & Prova Social', 'slug' => 'depoimentos', 'palavras_chave' => ['avaliação 5 estrelas', 'confiança', 'pontualidade', 'satisfação']],
                ['nome' => '🏢 Institucional & Autoridade', 'slug' => 'institucional', 'palavras_chave' => ['tradição', 'equipe especializada', 'segurança']],
            ];
            foreach ($padroes as $p) {
                \App\Models\GmbPostCategoria::create([
                    'tenant_id'      => $tenantId,
                    'nome'           => $p['nome'],
                    'slug'           => $p['slug'],
                    'palavras_chave' => $p['palavras_chave'],
                    'ativo'          => true,
                ]);
            }
            $categorias = \App\Models\GmbPostCategoria::where('tenant_id', $tenantId)->withCount('templates')->get();
        }

        return view('gmb-posts.categorias', compact('categorias'));
    }

    public function storeCategoria(Request $request): RedirectResponse
    {
        $tenantId = auth()->user()->tenant_id;

        $validated = $request->validate([
            'nome'           => 'required|string|max:100',
            'palavras_chave' => 'nullable|string|max:500',
        ]);

        $palavras = !empty($validated['palavras_chave'])
            ? array_map('trim', explode(',', $validated['palavras_chave']))
            : [];

        \App\Models\GmbPostCategoria::create([
            'tenant_id'      => $tenantId,
            'nome'           => $validated['nome'],
            'slug'           => \Illuminate\Support\Str::slug($validated['nome']),
            'palavras_chave' => $palavras,
            'ativo'          => true,
        ]);

        return back()->with('sucesso', 'Categoria de postagem criada com sucesso!');
    }

    public function updateCategoria(Request $request, \App\Models\GmbPostCategoria $categoria): RedirectResponse
    {
        $validated = $request->validate([
            'nome'           => 'required|string|max:100',
            'palavras_chave' => 'nullable|string|max:500',
        ]);

        $palavras = !empty($validated['palavras_chave'])
            ? array_map('trim', explode(',', $validated['palavras_chave']))
            : [];

        $categoria->update([
            'nome'           => $validated['nome'],
            'palavras_chave' => $palavras,
        ]);

        return back()->with('sucesso', "Categoria '{$categoria->nome}' atualizada com sucesso!");
    }

    public function destroyCategoria(\App\Models\GmbPostCategoria $categoria): RedirectResponse
    {
        abort_unless($categoria->tenant_id === auth()->user()->tenant_id, 404);

        $categoria->delete();

        return back()->with('sucesso', 'Categoria de postagem removida.');
    }

    // ── Banco / Galeria de Imagens do Tenant ──────────────────────────────

    public function imagens(Request $request): View
    {
        $tenantId = auth()->user()->tenant_id;

        $imagens = \App\Models\GmbPostImagem::where('tenant_id', $tenantId)
            ->latest()
            ->paginate(18);

        $mascaras = \App\Models\ImagemMascara::where('tenant_id', $tenantId)
            ->where('ativo', true)
            ->latest()
            ->get();

        $imagensFundo = \App\Models\GmbPostImagem::where('tenant_id', $tenantId)
            ->where('tipo', 'fundo')
            ->latest()
            ->limit(60)
            ->get();

        return view('gmb-posts.imagens', compact('imagens', 'mascaras', 'imagensFundo'));
    }

    /**
     * Sobe uma máscara nova (PNG com janela transparente) — a posição da
     * janela é detectada automaticamente varrendo o canal alfa
     * (ImagemMascara::detectarJanelaTransparente()), nada é digitado à mão.
     */
    public function storeMascara(Request $request): RedirectResponse
    {
        $tenant = auth()->user()->tenant;

        $request->validate([
            'mascara' => 'required|image|mimes:png|max:10240',
            'nome'    => 'required|string|max:150',
        ]);

        $arquivo = $request->file('mascara');
        $janela  = \App\Models\ImagemMascara::detectarJanelaTransparente($arquivo->getRealPath());

        $pasta    = 'imagem-mascaras/' . $tenant->id;
        $caminho  = $arquivo->storeAs($pasta, uniqid('mascara_') . '.png', 'public');
        $url      = \Illuminate\Support\Facades\Storage::disk('public')->url($caminho);

        \App\Models\ImagemMascara::create([
            'tenant_id'      => $tenant->id,
            'nome'           => $request->input('nome'),
            'arquivo_url'    => $url,
            'janela_x'       => $janela['x'],
            'janela_y'       => $janela['y'],
            'janela_largura' => $janela['largura'],
            'janela_altura'  => $janela['altura'],
            'largura_total'  => $janela['largura_total'],
            'altura_total'   => $janela['altura_total'],
        ]);

        return back()->with('sucesso', 'Máscara salva! Janela transparente detectada automaticamente.');
    }

    public function destroyMascara(\App\Models\ImagemMascara $mascara): RedirectResponse
    {
        abort_unless($mascara->tenant_id === auth()->user()->tenant_id, 404);

        $mascara->update(['ativo' => false]);

        return back()->with('sucesso', 'Máscara removida.');
    }

    /**
     * Aplica uma máscara a N imagens de fundo selecionadas na galeria,
     * gerando uma imagem nova (tipo=pronta) pra cada uma — nunca sobrescreve
     * a foto de fundo original, que continua disponível na galeria.
     */
    public function aplicarMascara(Request $request, \App\Services\ImagemComposicaoService $composicao, \App\Services\GmbImageSeoService $seoService): RedirectResponse
    {
        $tenant = auth()->user()->tenant;

        $request->validate([
            'imagem_mascara_id'     => 'required|exists:imagem_mascaras,id',
            'imagens_selecionadas'  => 'required|array|min:1',
            'imagens_selecionadas.*' => 'exists:gmb_post_imagens,id',
        ]);

        $mascara = \App\Models\ImagemMascara::where('tenant_id', $tenant->id)
            ->findOrFail($request->input('imagem_mascara_id'));

        $fotosFundo = \App\Models\GmbPostImagem::where('tenant_id', $tenant->id)
            ->whereIn('id', $request->input('imagens_selecionadas'))
            ->get();

        $urlStorage = \Illuminate\Support\Facades\Storage::disk('public')->url('');
        $total = 0;

        foreach ($fotosFundo as $foto) {
            $caminhoRelativo = str_starts_with($foto->imagem_url, $urlStorage)
                ? str_replace($urlStorage, '', $foto->imagem_url)
                : $foto->imagem_url;
            $caminhoAbsoluto = \Illuminate\Support\Facades\Storage::disk('public')->path($caminhoRelativo);

            $bytesFinais = $composicao->compor($caminhoAbsoluto, $mascara);
            $url = $seoService->salvarImagemBytes($bytesFinais, $tenant, null, 'png', null, $foto->titulo, 'gmb-posts/prontas/' . $tenant->id);

            \App\Models\GmbPostImagem::create([
                'tenant_id'         => $tenant->id,
                'tipo'              => 'pronta',
                'imagem_mascara_id' => $mascara->id,
                'titulo'            => $foto->titulo,
                'palavras_chave'    => $foto->palavras_chave,
                'imagem_url'        => $url,
                'nome_arquivo_original' => $foto->nome_arquivo_original,
                'nome_arquivo_seo'  => basename($url),
                'tamanho_bytes'     => strlen($bytesFinais),
            ]);

            $total++;
        }

        return back()->with('sucesso', "{$total} imagem(ns) gerada(s) com a máscara aplicada!");
    }

    /**
     * Gera fotos de fundo NOVAS por IA, opcionalmente inspiradas em fotos já
     * existentes na galeria (usadas como referência de estilo). O resultado
     * entra no banco como tipo=fundo — passa pelo mesmo fluxo de
     * aplicarMascara() depois, igual a uma foto enviada manualmente.
     */
    public function gerarFotosIa(Request $request, \App\Services\OpenRouterImageService $imagemIa, \App\Services\GmbImageSeoService $seoService): RedirectResponse
    {
        $tenant = auth()->user()->tenant;

        $request->validate([
            'prompt'                => 'required|string|max:500',
            'quantidade'            => 'nullable|integer|min:1|max:6',
            'aspect_ratio'          => 'nullable|string|in:4:3,1:1,9:16,16:9',
            'imagens_referencia'    => 'nullable|array',
            'imagens_referencia.*'  => 'exists:gmb_post_imagens,id',
        ]);

        $urlsReferencia = [];
        if ($request->filled('imagens_referencia')) {
            $urlsReferencia = \App\Models\GmbPostImagem::where('tenant_id', $tenant->id)
                ->whereIn('id', $request->input('imagens_referencia'))
                ->pluck('imagem_url')
                ->all();
        }

        $imagensGeradas = $imagemIa->gerar(
            $request->input('prompt'),
            $urlsReferencia,
            $request->input('aspect_ratio', '4:3'),
            (int) $request->input('quantidade', 1)
        );

        if (empty($imagensGeradas)) {
            return back()->with('erro', 'Não foi possível gerar imagens agora. Verifique o crédito da OpenRouter ou tente novamente em instantes.');
        }

        $total = 0;
        foreach ($imagensGeradas as $bytes) {
            $url = $seoService->salvarImagemBytes($bytes, $tenant, null, 'png', null, $request->input('prompt'), 'gmb-posts/ia/' . $tenant->id);

            \App\Models\GmbPostImagem::create([
                'tenant_id'      => $tenant->id,
                'tipo'           => 'fundo',
                'titulo'         => 'Gerado por IA: ' . str($request->input('prompt'))->limit(60),
                'imagem_url'     => $url,
                'nome_arquivo_seo' => basename($url),
                'tamanho_bytes'  => strlen($bytes),
            ]);

            $total++;
        }

        return back()->with('sucesso', "{$total} foto(s) nova(s) gerada(s) por IA! Já estão na galeria como fundo, prontas pra aplicar uma máscara.");
    }

    public function storeImagem(Request $request, \App\Services\GmbImageSeoService $seoService): RedirectResponse
    {
        $tenant = auth()->user()->tenant;

        $request->validate([
            'imagens'   => 'required|array',
            'imagens.*' => 'image|max:10240',
            'titulo'    => 'nullable|string|max:150',
            'palavras'  => 'nullable|string|max:255',
        ]);

        $total = 0;
        foreach ($request->file('imagens') as $arquivo) {
            $extensao = strtolower($arquivo->getClientOriginalExtension()) ?: 'jpg';
            $dataHora = now();
            $nomeSeo = $seoService->gerarNomeSeo($tenant, null, $dataHora, $extensao, $request->input('palavras') ?: $request->input('titulo'));
            
            $pasta = 'gmb-posts/galeria/' . $tenant->id;
            $caminho = $arquivo->storeAs($pasta, $nomeSeo, 'public');
            $url = \Illuminate\Support\Facades\Storage::disk('public')->url($caminho);

            \App\Models\GmbPostImagem::create([
                'tenant_id'              => $tenant->id,
                'titulo'                 => $request->input('titulo') ?: pathinfo($arquivo->getClientOriginalName(), PATHINFO_FILENAME),
                'palavras_chave'         => $request->input('palavras'),
                'imagem_url'             => $url,
                'nome_arquivo_original'  => $arquivo->getClientOriginalName(),
                'nome_arquivo_seo'       => $nomeSeo,
                'tamanho_bytes'          => $arquivo->getSize(),
            ]);
            $total++;
        }

        return back()->with('sucesso', "{$total} imagem(ns) enviada(s) e renomeada(s) com SEO com sucesso!");
    }

    public function destroyImagem(\App\Models\GmbPostImagem $imagem): RedirectResponse
    {
        abort_unless($imagem->tenant_id === auth()->user()->tenant_id, 404);

        $imagem->delete();

        return back()->with('sucesso', 'Imagem removida da galeria.');
    }

    // ── Publicação Imediata e Ações de Post ──────────────────────────────

    public function publicarAgora(GmbPost $post, GmbPostPublishService $publishService): RedirectResponse
    {
        $sucesso = $publishService->publicar($post);

        if ($sucesso) {
            return back()->with('sucesso', 'Post publicado no Google com sucesso!');
        }

        $erro = $post->fresh()->log_erro ?: 'Erro desconhecido ao comunicar com o Google.';
        return back()->with('erro', 'Falha ao publicar: ' . $erro);
    }

    public function destroy(GmbPost $post): RedirectResponse
    {
        $post->delete();

        return back()->with('sucesso', 'Postagem removida.');
    }

    public function gerarIa(Request $request, GmbPostIaService $iaService): JsonResponse
    {
        $request->validate([
            'perfil_id' => 'required|exists:perfis_gmb,id',
            'tipo'      => 'required|in:novidade,oferta,evento',
            'objetivo'  => 'nullable|string|max:200',
            'tema'      => 'nullable|string|max:200',
        ]);

        $perfil = PerfilGmb::where('tenant_id', auth()->user()->tenant_id)->findOrFail($request->perfil_id);

        $resultado = $iaService->gerarCopy(
            perfil: $perfil,
            tipo: $request->tipo,
            objetivo: $request->objetivo,
            tema: $request->tema
        );

        return response()->json($resultado);
    }
}
