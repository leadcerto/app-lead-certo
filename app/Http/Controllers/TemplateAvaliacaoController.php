<?php

namespace App\Http\Controllers;

use App\Models\CategoriaTemplate;
use App\Models\TemplateAvaliacao;
use Illuminate\Http\Request;

class TemplateAvaliacaoController extends Controller
{
    /**
     * Lista todos os templates do tenant.
     */
    public function index(Request $request)
    {
        $templates = TemplateAvaliacao::where('tenant_id', $request->user()->tenant_id)
            ->with('categoria')
            ->orderBy('codigo')
            ->paginate(20);

        return view('admin.templates-avaliacao.index', compact('templates'));
    }

    /**
     * Formulário de criação de template.
     */
    public function create(Request $request)
    {
        $categorias = CategoriaTemplate::where('tenant_id', $request->user()->tenant_id)
            ->orderBy('nome')
            ->get();

        return view('admin.templates-avaliacao.create', compact('categorias'));
    }

    /**
     * Salva novo template.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'codigo'       => 'required|string|max:30',
            'texto'        => 'required|string',
            'categoria_id' => 'required|exists:categorias_template,id',
            'ativo'        => 'boolean',
        ]);

        $validated['tenant_id'] = $request->user()->tenant_id;
        $validated['ativo'] = $validated['ativo'] ?? true;

        // Verificar unicidade do código no tenant
        $existe = TemplateAvaliacao::where('tenant_id', $validated['tenant_id'])
            ->where('codigo', $validated['codigo'])
            ->exists();

        if ($existe) {
            return back()->withErrors(['codigo' => 'Este código já está em uso.'])->withInput();
        }

        TemplateAvaliacao::create($validated);

        return redirect()->route('admin.templates-avaliacao.index')
            ->with('sucesso', 'Template criado com sucesso!');
    }

    /**
     * Formulário de edição.
     *
     * Nome do parâmetro precisa casar com o nome gerado pela rota resource
     * ('templates-avaliacao' → snake_case 'templates_avaliacao') pra o
     * binding implícito do Laravel funcionar — com outro nome (ex.: $template)
     * o binding falha silenciosamente e injeta um model vazio em vez do
     * registro real.
     */
    public function edit(Request $request, TemplateAvaliacao $templatesAvaliacao)
    {
        abort_if($templatesAvaliacao->tenant_id !== $request->user()->tenant_id, 403);

        $categorias = CategoriaTemplate::where('tenant_id', $request->user()->tenant_id)
            ->orderBy('nome')
            ->get();

        return view('admin.templates-avaliacao.edit', ['template' => $templatesAvaliacao, 'categorias' => $categorias]);
    }

    /**
     * Atualiza template existente.
     */
    public function update(Request $request, TemplateAvaliacao $templatesAvaliacao)
    {
        abort_if($templatesAvaliacao->tenant_id !== $request->user()->tenant_id, 403);

        $validated = $request->validate([
            'codigo'       => 'required|string|max:30',
            'texto'        => 'required|string',
            'categoria_id' => 'required|exists:categorias_template,id',
            'ativo'        => 'boolean',
        ]);

        $validated['ativo'] = $request->has('ativo');

        // Verificar unicidade do código (excluindo o próprio)
        $existe = TemplateAvaliacao::where('tenant_id', $templatesAvaliacao->tenant_id)
            ->where('codigo', $validated['codigo'])
            ->where('id', '!=', $templatesAvaliacao->id)
            ->exists();

        if ($existe) {
            return back()->withErrors(['codigo' => 'Este código já está em uso.'])->withInput();
        }

        $templatesAvaliacao->update($validated);

        return redirect()->route('admin.templates-avaliacao.index')
            ->with('sucesso', 'Template atualizado com sucesso!');
    }

    /**
     * Desativa template.
     */
    public function destroy(Request $request, TemplateAvaliacao $templatesAvaliacao)
    {
        abort_if($templatesAvaliacao->tenant_id !== $request->user()->tenant_id, 403);

        $templatesAvaliacao->update(['ativo' => false]);

        return redirect()->route('admin.templates-avaliacao.index')
            ->with('sucesso', 'Template desativado.');
    }

    // ── Categorias (CRUD inline) ──────────────────────────────────────────────

    /**
     * Lista categorias.
     */
    public function categorias(Request $request)
    {
        $categorias = CategoriaTemplate::where('tenant_id', $request->user()->tenant_id)
            ->withCount('templates')
            ->orderBy('nome')
            ->get();

        return view('admin.templates-avaliacao.categorias', compact('categorias'));
    }

    /**
     * Cria nova categoria.
     */
    public function storeCategoria(Request $request)
    {
        $validated = $request->validate([
            'nome' => 'required|string|max:100',
        ]);

        $validated['tenant_id'] = $request->user()->tenant_id;

        CategoriaTemplate::create($validated);

        return redirect()->route('admin.templates-avaliacao.categorias')
            ->with('sucesso', 'Categoria criada!');
    }

    /**
     * Remove categoria (apenas se não tiver templates vinculados).
     */
    public function destroyCategoria(Request $request, CategoriaTemplate $categoria)
    {
        abort_if($categoria->tenant_id !== $request->user()->tenant_id, 403);

        if ($categoria->templates()->exists()) {
            return back()->withErrors(['categoria' => 'Categoria possui templates vinculados.']);
        }

        $categoria->delete();

        return redirect()->route('admin.templates-avaliacao.categorias')
            ->with('sucesso', 'Categoria removida.');
    }
}
