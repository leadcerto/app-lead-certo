<?php

namespace App\Http\Controllers;

use App\Models\MetaPostConteudo;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class MetaPostConteudoController extends Controller
{
    private function getTenantId(Request $request): int
    {
        $user = $request->user();
        if ($user->podeTrocarTenant()) {
            return (int) ($request->query('tenant_id') ?? session('tenant_id') ?? $user->tenant_id);
        }
        return (int) $user->tenant_id;
    }

    /**
     * Achado real 2026-09-17: o route-model-binding implícito de
     * {conteudo} roda no middleware SubstituteBindings, que executa ANTES
     * de EnsureTenant (que só ali seta session('tenant_id')/request
     * attribute que o TenantScope depende) — nesse momento o scope não
     * filtra nada e um id de outro tenant resolveria normalmente.
     * Checagem explícita aqui, não dependente da ordem dos middlewares.
     */
    private function garantirDoProprioTenant(Request $request, MetaPostConteudo $conteudo): void
    {
        if ($conteudo->tenant_id !== $this->getTenantId($request)) {
            throw new NotFoundHttpException();
        }
    }

    public function index(Request $request): View
    {
        $tenantId = $this->getTenantId($request);

        $conteudos = MetaPostConteudo::where('tenant_id', $tenantId)
            ->orderBy('categoria')
            ->orderBy('titulo')
            ->get();

        $conteudosPorCategoria = $conteudos->groupBy('categoria');

        return view('meta-posts.conteudos.index', compact('conteudos', 'conteudosPorCategoria'));
    }

    private function validar(Request $request): array
    {
        return $request->validate([
            'titulo'                      => 'required|string|max:150',
            'categoria'                   => 'nullable|string|max:100',
            'texto'                       => 'required|string|max:2200',
            'imagem_url'                  => 'nullable|url',
            'cta_tipo'                    => 'nullable|in:NENHUM,BOOK,ORDER,SHOP,LEARN_MORE,SIGN_UP,CALL',
            'cta_url'                     => 'nullable|url',
            'modo_gatilho'                => 'required|in:nenhum,qualquer_comentario,palavra_chave',
            'palavras_chave_texto'        => 'nullable|string',
            'resposta_publica_comentario' => 'nullable|string|max:500',
            'mensagem_direct'             => 'nullable|string|max:1000',
        ]);
    }

    private function palavrasChaveArray(?string $texto = null): array
    {
        if (empty($texto)) {
            return [];
        }
        return array_values(array_filter(array_map('trim', explode(',', $texto))));
    }

    public function store(Request $request): RedirectResponse
    {
        $tenantId  = $this->getTenantId($request);
        $validated = $this->validar($request);

        MetaPostConteudo::create([
            'tenant_id'                   => $tenantId,
            'titulo'                      => $validated['titulo'],
            'categoria'                   => $validated['categoria'] ?? 'geral',
            'texto'                       => $validated['texto'],
            'imagem_url'                  => $validated['imagem_url'] ?? null,
            'cta_tipo'                    => $validated['cta_tipo'] ?? 'NENHUM',
            'cta_url'                     => $validated['cta_url'] ?? null,
            'modo_gatilho'                => $validated['modo_gatilho'],
            'palavras_chave'              => $this->palavrasChaveArray($validated['palavras_chave_texto'] ?? null),
            'resposta_publica_comentario' => $validated['resposta_publica_comentario'] ?? null,
            'mensagem_direct'             => $validated['mensagem_direct'] ?? null,
            'ativo'                       => true,
        ]);

        return back()->with('sucesso', 'Conteúdo salvo no banco de conteúdos!');
    }

    public function update(Request $request, MetaPostConteudo $conteudo): RedirectResponse
    {
        $this->garantirDoProprioTenant($request, $conteudo);
        $validated = $this->validar($request);

        $conteudo->update([
            'titulo'                      => $validated['titulo'],
            'categoria'                   => $validated['categoria'] ?? 'geral',
            'texto'                       => $validated['texto'],
            'imagem_url'                  => $validated['imagem_url'] ?? null,
            'cta_tipo'                    => $validated['cta_tipo'] ?? 'NENHUM',
            'cta_url'                     => $validated['cta_url'] ?? null,
            'modo_gatilho'                => $validated['modo_gatilho'],
            'palavras_chave'              => $this->palavrasChaveArray($validated['palavras_chave_texto'] ?? null),
            'resposta_publica_comentario' => $validated['resposta_publica_comentario'] ?? null,
            'mensagem_direct'             => $validated['mensagem_direct'] ?? null,
        ]);

        return back()->with('sucesso', "Conteúdo '{$conteudo->titulo}' atualizado!");
    }

    public function alternarStatus(Request $request, MetaPostConteudo $conteudo): RedirectResponse
    {
        $this->garantirDoProprioTenant($request, $conteudo);
        $conteudo->update(['ativo' => ! $conteudo->ativo]);

        return back()->with('sucesso', $conteudo->ativo ? 'Conteúdo ativado.' : 'Conteúdo desativado.');
    }

    public function destroy(Request $request, MetaPostConteudo $conteudo): RedirectResponse
    {
        $this->garantirDoProprioTenant($request, $conteudo);
        $conteudo->delete();

        return back()->with('sucesso', 'Conteúdo removido do banco.');
    }
}
