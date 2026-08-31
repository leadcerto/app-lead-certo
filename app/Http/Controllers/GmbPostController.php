<?php

namespace App\Http\Controllers;

use App\Models\GmbPost;
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

        $inicioSemana = $semana->copy()->startOfWeek(Carbon::MONDAY)->startOfDay();
        $fimSemana    = $semana->copy()->endOfWeek(Carbon::SUNDAY)->endOfDay();

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

        return view('gmb-posts.create', compact('perfis'));
    }

    public function store(Request $request, GmbPostPublishService $publishService): RedirectResponse
    {
        $tenantId = auth()->user()->tenant_id;

        $validated = $request->validate([
            'perfil_gmb_id'     => 'required|exists:perfis_gmb,id',
            'tipo'              => 'required|in:novidade,oferta,evento',
            'titulo'            => 'nullable|string|max:100',
            'texto'             => 'required|string|max:1500',
            'imagem_url'        => 'nullable|url',
            'cta_tipo'          => 'required|in:LEARN_MORE,CALL,ORDER,BOOK,SIGN_UP,SHOP,NENHUM',
            'cta_url'           => 'nullable|url',
            'codigo_cupom'      => 'nullable|string|max:50',
            'link_resgate'      => 'nullable|url',
            'data_agendada'     => 'nullable|date',
            'publicar_imediato' => 'nullable|boolean',
            'gerado_por_ia'     => 'nullable|boolean',
        ]);

        $dataAgendada = !empty($validated['publicar_imediato'])
            ? now()
            : ($validated['data_agendada'] ? Carbon::parse($validated['data_agendada']) : now());

        $post = GmbPost::create([
            'tenant_id'     => $tenantId,
            'perfil_gmb_id' => $validated['perfil_gmb_id'],
            'autor_user_id' => auth()->id(),
            'tipo'          => $validated['tipo'],
            'titulo'        => $validated['titulo'] ?? null,
            'texto'         => $validated['texto'],
            'imagem_url'    => $validated['imagem_url'] ?? null,
            'cta_tipo'      => $validated['cta_tipo'],
            'cta_url'       => $validated['cta_url'] ?? null,
            'codigo_cupom'  => $validated['codigo_cupom'] ?? null,
            'link_resgate'  => $validated['link_resgate'] ?? null,
            'data_agendada' => $dataAgendada,
            'status'        => !empty($validated['publicar_imediato']) ? 'processando' : 'agendado',
            'gerado_por_ia' => !empty($validated['gerado_por_ia']),
        ]);

        if (!empty($validated['publicar_imediato'])) {
            $sucesso = $publishService->publicar($post);

            if ($sucesso) {
                return redirect()->route('admin.gmb-posts.index', ['semana' => $dataAgendada->toDateString()])
                    ->with('sucesso', 'Publicação enviada com sucesso para o Google Meu Negócio!');
            }

            return redirect()->route('admin.gmb-posts.index', ['semana' => $dataAgendada->toDateString()])
                ->with('aviso', 'Post criado, mas a publicação imediata falhou. Verifique os logs.');
        }

        return redirect()->route('admin.gmb-posts.index', ['semana' => $dataAgendada->toDateString()])
            ->with('sucesso', "Post agendado para {$post->data_agendada->format('d/m/Y H:i')}!");
    }

    public function publicarAgora(GmbPost $post, GmbPostPublishService $publishService): RedirectResponse
    {
        $sucesso = $publishService->publicar($post);

        if ($sucesso) {
            return back()->with('sucesso', 'Post publicado com sucesso no Google Meu Negócio!');
        }

        return back()->with('aviso', 'Não foi possível publicar agora: ' . ($post->log_erro ?? 'Erro desconhecido.'));
    }

    public function gerarIa(Request $request, GmbPostIaService $iaService): JsonResponse
    {
        $request->validate([
            'perfil_gmb_id' => 'required|exists:perfis_gmb,id',
            'tipo'          => 'nullable|string',
            'objetivo'      => 'nullable|string',
            'tema'          => 'nullable|string',
        ]);

        $perfil = PerfilGmb::findOrFail($request->perfil_gmb_id);

        $resultado = $iaService->gerarCopy(
            perfil: $perfil,
            tipo: $request->input('tipo', 'novidade'),
            objetivo: $request->input('objetivo', 'Atrair clientes e ligações locais'),
            tema: $request->input('tema')
        );

        return response()->json($resultado);
    }

    public function destroy(GmbPost $post): RedirectResponse
    {
        $post->delete();

        return back()->with('sucesso', 'Postagem removida da agenda.');
    }
}
