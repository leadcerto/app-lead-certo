<?php

namespace App\Http\Controllers\Painel;

use App\Http\Controllers\Controller;
use App\Models\MetaCampanhaGatilho;
use App\Models\MetaContaInstagram;
use App\Models\MetaPagina;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class MetaCampanhasGatilhoController extends Controller
{
    public function index(Request $request): View
    {
        $tenantId = $request->user()->tenant_id;

        $gatilhos = MetaCampanhaGatilho::where('tenant_id', $tenantId)
            ->with(['contaInstagram', 'paginaFacebook'])
            ->latest()
            ->get();

        $paginas    = MetaPagina::where('tenant_id', $tenantId)->where('ativo', true)->get();
        $contasIg   = MetaContaInstagram::where('tenant_id', $tenantId)->where('ativo', true)->get();

        return view('meta.gatilhos', compact('gatilhos', 'paginas', 'contasIg'));
    }

    public function store(Request $request): RedirectResponse
    {
        $tenantId = $request->user()->tenant_id;

        $validated = $request->validate([
            'nome'                        => 'required|string|max:150',
            'canal_alvo'                  => 'required|in:instagram,facebook,ambos',
            'instagram_conta_id'          => 'nullable|exists:meta_contas_instagram,id',
            'facebook_pagina_id'          => 'nullable|exists:meta_paginas,id',
            'post_id_especifico'          => 'nullable|string|max:100',
            'modo_gatilho'                => 'required|in:qualquer_comentario,palavra_chave',
            'palavras_chave_texto'        => 'nullable|string',
            'resposta_publica_comentario' => 'nullable|string|max:500',
            'mensagem_direct'             => 'required|string|max:1000',
        ]);

        $palavrasArray = [];
        if (! empty($validated['palavras_chave_texto'])) {
            $palavrasArray = array_values(array_filter(array_map('trim', explode(',', $validated['palavras_chave_texto']))));
        }

        MetaCampanhaGatilho::create([
            'tenant_id'                   => $tenantId,
            'nome'                        => $validated['nome'],
            'canal_alvo'                  => $validated['canal_alvo'],
            'instagram_conta_id'          => $validated['instagram_conta_id'] ?: null,
            'facebook_pagina_id'          => $validated['facebook_pagina_id'] ?: null,
            'post_id_especifico'          => $validated['post_id_especifico'] ?: null,
            'modo_gatilho'                => $validated['modo_gatilho'],
            'palavras_chave'              => $palavrasArray,
            'resposta_publica_comentario' => $validated['resposta_publica_comentario'] ?: null,
            'mensagem_direct'             => $validated['mensagem_direct'],
            'ativo'                       => true,
        ]);

        return back()->with('sucesso', 'Regra Comment-to-DM criada com sucesso!');
    }

    public function update(Request $request, MetaCampanhaGatilho $gatilho): RedirectResponse
    {
        $tenantId = $request->user()->tenant_id;
        if ($gatilho->tenant_id !== $tenantId) {
            abort(403);
        }

        $validated = $request->validate([
            'nome'                        => 'required|string|max:150',
            'canal_alvo'                  => 'required|in:instagram,facebook,ambos',
            'instagram_conta_id'          => 'nullable|exists:meta_contas_instagram,id',
            'facebook_pagina_id'          => 'nullable|exists:meta_paginas,id',
            'post_id_especifico'          => 'nullable|string|max:100',
            'modo_gatilho'                => 'required|in:qualquer_comentario,palavra_chave',
            'palavras_chave_texto'        => 'nullable|string',
            'resposta_publica_comentario' => 'nullable|string|max:500',
            'mensagem_direct'             => 'required|string|max:1000',
            'ativo'                       => 'nullable|boolean',
        ]);

        $palavrasArray = [];
        if (! empty($validated['palavras_chave_texto'])) {
            $palavrasArray = array_values(array_filter(array_map('trim', explode(',', $validated['palavras_chave_texto']))));
        }

        $gatilho->update([
            'nome'                        => $validated['nome'],
            'canal_alvo'                  => $validated['canal_alvo'],
            'instagram_conta_id'          => $validated['instagram_conta_id'] ?: null,
            'facebook_pagina_id'          => $validated['facebook_pagina_id'] ?: null,
            'post_id_especifico'          => $validated['post_id_especifico'] ?: null,
            'modo_gatilho'                => $validated['modo_gatilho'],
            'palavras_chave'              => $palavrasArray,
            'resposta_publica_comentario' => $validated['resposta_publica_comentario'] ?: null,
            'mensagem_direct'             => $validated['mensagem_direct'],
            'ativo'                       => $request->has('ativo') ? (bool) $request->input('ativo') : $gatilho->ativo,
        ]);

        return back()->with('sucesso', 'Regra atualizada com sucesso!');
    }

    public function destroy(Request $request, MetaCampanhaGatilho $gatilho): RedirectResponse
    {
        if ($gatilho->tenant_id !== $request->user()->tenant_id) {
            abort(403);
        }

        $gatilho->delete();

        return back()->with('sucesso', 'Regra removida.');
    }
}
