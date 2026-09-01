@extends('layouts.app')

@section('title', 'Templates de Postagens — Lead Certo')

@section('content')
<div class="max-w-6xl mx-auto" x-data="{ 
    modalCriar: false, 
    modalIa: false, 
    modalEditar: false,
    editId: null,
    editTitulo: '',
    editCategoria: 'promocoes',
    editTexto: '',
    editCta: 'CALL',
    abrirEdicao(tpl) {
        this.editId = tpl.id;
        this.editTitulo = tpl.titulo_template;
        this.editCategoria = tpl.categoria;
        this.editTexto = tpl.texto_template;
        this.editCta = tpl.cta_tipo_padrao || 'CALL';
        this.modalEditar = true;
    }
}">

    {{-- Header idêntico aos Templates de Avaliação --}}
    <div class="flex items-center justify-between mb-6 flex-wrap gap-4">
        <div>
            <h1 class="text-2xl font-bold text-gray-800">📝 Templates de Postagens</h1>
            <p class="text-sm text-gray-500 mt-1">
                Modelos prontos para o Google Meu Negócio focados em gerar ligações e WhatsApp — as tags <code class="text-green-600 font-mono font-bold">{bairro}</code>, <code class="text-green-600 font-mono font-bold">{cidade}</code> e <code class="text-green-600 font-mono font-bold">{empresa}</code> são preenchidas automaticamente.
            </p>
        </div>
        <div class="flex gap-2 flex-wrap">
            <button @click="modalIa = true"
                    class="px-4 py-2 bg-purple-600 text-white rounded-lg hover:bg-purple-700 text-sm font-semibold transition flex items-center gap-1.5 shadow-sm">
                🤖 Gerar com IA
            </button>
            <button @click="modalCriar = true"
                    class="px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 text-sm font-semibold transition flex items-center gap-1.5 shadow-sm">
                + Novo Template
            </button>
            <a href="{{ route('admin.gmb-posts.lote') }}"
               class="px-4 py-2 bg-gray-200 text-gray-700 rounded-lg hover:bg-gray-300 text-sm font-medium transition flex items-center gap-1.5">
                📊 Gerador em Lote
            </a>
        </div>
    </div>

    @if(session('sucesso'))
        <div class="mb-4 p-3 bg-green-100 text-green-800 rounded-lg text-sm flex items-center gap-2">
            <span>✅</span>
            <span>{{ session('sucesso') }}</span>
        </div>
    @endif

    {{-- Lista de Templates no exato modelo visual das Avaliações --}}
    <div class="space-y-6">
        @php $categoriaAtual = null; @endphp
        @forelse($templates as $index => $template)
            @if($template->categoria !== $categoriaAtual)
                @php $categoriaAtual = $template->categoria; @endphp
                <h2 class="text-sm font-semibold text-gray-600 uppercase tracking-wide {{ !$loop->first ? 'pt-4' : '' }}">
                    {{ match($template->categoria) {
                        'promocoes' => 'Promoções & Ofertas',
                        'servicos' => 'Serviços & Soluções',
                        'dicas' => 'Dicas & Utilidade Pública',
                        'depoimentos' => 'Depoimentos & Prova Social',
                        'institucional' => 'Institucional & Autoridade',
                        default => ucfirst($template->categoria),
                    } }}
                </h2>
            @endif

            <div class="bg-white rounded-xl shadow p-4 hover:shadow-md transition">
                <div class="flex items-start justify-between gap-4">
                    <div class="flex items-center gap-2 flex-wrap">
                        <span class="font-mono text-xs text-gray-500 bg-gray-100 px-2 py-0.5 rounded">
                            POST-{{ strtoupper(substr($template->categoria, 0, 3)) }}-{{ str_pad($template->id, 2, '0', STR_PAD_LEFT) }}
                        </span>
                        @if($template->ativo)
                            <span class="px-2 py-0.5 bg-green-100 text-green-700 rounded-full text-xs font-semibold">Ativo</span>
                        @else
                            <span class="px-2 py-0.5 bg-red-100 text-red-700 rounded-full text-xs font-semibold">Inativo</span>
                        @endif
                        <span class="px-2 py-0.5 bg-green-50 text-green-800 border border-green-300 rounded text-xs font-bold flex items-center gap-1">
                            📞 Botão Padrão: {{ match($template->cta_tipo_padrao) {
                                'CALL' => 'Ligar Agora (WhatsApp / Tel)',
                                'LEARN_MORE' => 'Saiba Mais (LEARN_MORE)',
                                'ORDER' => 'Fazer Pedido (ORDER)',
                                'BOOK' => 'Agendar (BOOK)',
                                'SIGN_UP' => 'Cadastre-se (SIGN_UP)',
                                default => $template->cta_tipo_padrao,
                            } }}
                        </span>
                    </div>
                    <div class="flex gap-3 flex-shrink-0">
                        <button type="button" 
                                @click='abrirEdicao(@json($template))' 
                                class="text-blue-600 hover:underline text-xs font-semibold">
                            Editar
                        </button>
                        <form action="{{ route('admin.gmb-posts.templates.destroy', $template) }}" method="POST" class="inline">
                            @csrf @method('DELETE')
                            <button type="submit" class="text-red-500 hover:underline text-xs"
                                    onclick="return confirm('Deseja realmente remover este template?')">
                                Excluir
                            </button>
                        </form>
                    </div>
                </div>

                <div class="mt-2">
                    <h3 class="font-bold text-gray-900 text-sm">{{ $template->titulo_template }}</h3>
                    <p class="text-sm text-gray-700 mt-1 whitespace-pre-line leading-relaxed font-sans">{!! preg_replace('/(\{empresa\}|\{bairro\}|\{cidade\})/', '<span class="font-bold text-green-600">$1</span>', e($template->texto_template)) !!}</p>
                </div>
            </div>
        @empty
            <div class="bg-white rounded-xl shadow p-8 text-center text-gray-400">
                Nenhum template cadastrado.
            </div>
        @endforelse
    </div>

    {{-- Modal 1: Criar Template Manual --}}
    <div x-show="modalCriar" 
         x-transition:enter="transition ease-out duration-100"
         x-transition:enter-start="opacity-0 scale-95"
         x-transition:enter-end="opacity-100 scale-100"
         class="fixed inset-0 z-50 bg-black/60 backdrop-blur-sm flex items-center justify-center p-4" 
         style="display: none;">
        
        <div class="bg-white rounded-2xl max-w-lg w-full p-6 space-y-5 shadow-2xl border border-gray-100" @click.outside="modalCriar = false">
            <div class="flex items-center justify-between border-b border-gray-100 pb-3">
                <h2 class="text-base font-bold text-gray-900 flex items-center gap-2">
                    <span>✨</span>
                    <span>Novo Template de Postagem</span>
                </h2>
                <button @click="modalCriar = false" class="text-gray-400 hover:text-gray-600 text-lg font-bold">✕</button>
            </div>

            <form action="{{ route('admin.gmb-posts.templates.store') }}" method="POST" class="space-y-4">
                @csrf

                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="block text-xs font-bold text-gray-700 mb-1">Categoria</label>
                        <select name="categoria" required class="w-full bg-gray-50 border border-gray-200 rounded-xl px-3 py-2 text-xs focus:ring-2 focus:ring-green-500">
                            <option value="promocoes">Promoções & Ofertas</option>
                            <option value="servicos">Serviços & Soluções</option>
                            <option value="dicas">Dicas & Utilidade</option>
                            <option value="depoimentos">Depoimentos / Prova Social</option>
                            <option value="institucional">Institucional & Autoridade</option>
                        </select>
                    </div>

                    <div>
                        <label class="block text-xs font-bold text-gray-700 mb-1">Botão CTA (Padrão: Ligar)</label>
                        <select name="cta_tipo_padrao" required class="w-full bg-gray-50 border border-gray-200 rounded-xl px-3 py-2 text-xs focus:ring-2 focus:ring-green-500 font-semibold text-green-800">
                            <option value="CALL" selected>📞 Ligar Agora (WhatsApp / Chamada)</option>
                            <option value="LEARN_MORE">Saiba Mais (LEARN_MORE)</option>
                            <option value="ORDER">Fazer Pedido (ORDER)</option>
                            <option value="BOOK">Agendar (BOOK)</option>
                            <option value="SIGN_UP">Cadastre-se (SIGN_UP)</option>
                        </select>
                    </div>
                </div>

                <div>
                    <label class="block text-xs font-bold text-gray-700 mb-1">Título do Template</label>
                    <input type="text" name="titulo_template" required placeholder="Ex: Precisa de Frete ou Mudança em {bairro}?" class="w-full bg-gray-50 border border-gray-200 rounded-xl px-3 py-2 text-xs focus:ring-2 focus:ring-green-500">
                </div>

                <div>
                    <label class="block text-xs font-bold text-gray-700 mb-1">Texto / Mensagem do Post</label>
                    <textarea name="texto_template" rows="5" required placeholder="Digite a copy. Use {empresa}, {bairro} e {cidade} para personalização automática." class="w-full bg-gray-50 border border-gray-200 rounded-xl px-3 py-2 text-xs focus:ring-2 focus:ring-green-500"></textarea>
                </div>

                <div class="p-3 bg-blue-50 border border-blue-200 rounded-xl text-[11px] text-blue-800 space-y-0.5">
                    <p class="font-bold">Tags Dinâmicas Disponíveis:</p>
                    <p>• <code class="font-mono">{bairro}</code> → Nome do Bairro / Local (ex: Copacabana, Barra)</p>
                    <p>• <code class="font-mono">{cidade}</code> → Cidade do Perfil (ex: Rio de Janeiro)</p>
                    <p>• <code class="font-mono">{empresa}</code> → Nome da sua Empresa (ex: Frete Rio)</p>
                </div>

                <div class="flex justify-end gap-2 pt-2">
                    <button type="button" @click="modalCriar = false" class="px-4 py-2 bg-gray-100 text-gray-700 rounded-xl text-xs font-semibold">Cancelar</button>
                    <button type="submit" class="px-5 py-2 bg-green-600 hover:bg-green-700 text-white font-bold rounded-xl text-xs shadow transition">Salvar Template</button>
                </div>
            </form>
        </div>
    </div>

    {{-- Modal 2: Editar Template --}}
    <div x-show="modalEditar" 
         x-transition:enter="transition ease-out duration-100"
         x-transition:enter-start="opacity-0 scale-95"
         x-transition:enter-end="opacity-100 scale-100"
         class="fixed inset-0 z-50 bg-black/60 backdrop-blur-sm flex items-center justify-center p-4" 
         style="display: none;">
        
        <div class="bg-white rounded-2xl max-w-lg w-full p-6 space-y-5 shadow-2xl border border-gray-100" @click.outside="modalEditar = false">
            <div class="flex items-center justify-between border-b border-gray-100 pb-3">
                <h2 class="text-base font-bold text-gray-900 flex items-center gap-2">
                    <span>✏️</span>
                    <span>Editar Template de Postagem</span>
                </h2>
                <button @click="modalEditar = false" class="text-gray-400 hover:text-gray-600 text-lg font-bold">✕</button>
            </div>

            <form :action="'{{ url('admin/gmb/posts/templates') }}/' + editId" method="POST" class="space-y-4">
                @csrf
                @method('PUT')

                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="block text-xs font-bold text-gray-700 mb-1">Categoria</label>
                        <select name="categoria" x-model="editCategoria" required class="w-full bg-gray-50 border border-gray-200 rounded-xl px-3 py-2 text-xs focus:ring-2 focus:ring-green-500">
                            <option value="promocoes">Promoções & Ofertas</option>
                            <option value="servicos">Serviços & Soluções</option>
                            <option value="dicas">Dicas & Utilidade</option>
                            <option value="depoimentos">Depoimentos / Prova Social</option>
                            <option value="institucional">Institucional & Autoridade</option>
                        </select>
                    </div>

                    <div>
                        <label class="block text-xs font-bold text-gray-700 mb-1">Botão CTA Padrão</label>
                        <select name="cta_tipo_padrao" x-model="editCta" required class="w-full bg-gray-50 border border-gray-200 rounded-xl px-3 py-2 text-xs focus:ring-2 focus:ring-green-500 font-semibold text-green-800">
                            <option value="CALL">📞 Ligar Agora (WhatsApp / Tel)</option>
                            <option value="LEARN_MORE">Saiba Mais (LEARN_MORE)</option>
                            <option value="ORDER">Fazer Pedido (ORDER)</option>
                            <option value="BOOK">Agendar (BOOK)</option>
                            <option value="SIGN_UP">Cadastre-se (SIGN_UP)</option>
                        </select>
                    </div>
                </div>

                <div>
                    <label class="block text-xs font-bold text-gray-700 mb-1">Título do Template</label>
                    <input type="text" name="titulo_template" x-model="editTitulo" required class="w-full bg-gray-50 border border-gray-200 rounded-xl px-3 py-2 text-xs focus:ring-2 focus:ring-green-500">
                </div>

                <div>
                    <label class="block text-xs font-bold text-gray-700 mb-1">Texto / Mensagem do Post</label>
                    <textarea name="texto_template" rows="5" x-model="editTexto" required class="w-full bg-gray-50 border border-gray-200 rounded-xl px-3 py-2 text-xs focus:ring-2 focus:ring-green-500"></textarea>
                </div>

                <div class="flex justify-end gap-2 pt-2">
                    <button type="button" @click="modalEditar = false" class="px-4 py-2 bg-gray-100 text-gray-700 rounded-xl text-xs font-semibold">Cancelar</button>
                    <button type="submit" class="px-5 py-2 bg-blue-600 hover:bg-blue-700 text-white font-bold rounded-xl text-xs shadow transition">Atualizar Template</button>
                </div>
            </form>
        </div>
    </div>

    {{-- Modal 3: Gerar Templates Automaticamente com IA --}}
    <div x-show="modalIa" 
         x-transition:enter="transition ease-out duration-100"
         x-transition:enter-start="opacity-0 scale-95"
         x-transition:enter-end="opacity-100 scale-100"
         class="fixed inset-0 z-50 bg-black/60 backdrop-blur-sm flex items-center justify-center p-4" 
         style="display: none;">
        
        <div class="bg-white rounded-2xl max-w-md w-full p-6 space-y-5 shadow-2xl border border-gray-100" @click.outside="modalIa = false">
            <div class="flex items-center justify-between border-b border-gray-100 pb-3">
                <h2 class="text-base font-bold text-gray-900 flex items-center gap-2">
                    <span>🤖</span>
                    <span>Criar Novos Templates com IA</span>
                </h2>
                <button @click="modalIa = false" class="text-gray-400 hover:text-gray-600 text-lg font-bold">✕</button>
            </div>

            <form action="{{ route('admin.gmb-posts.templates.gerar-ia') }}" method="POST" class="space-y-4">
                @csrf

                <div>
                    <label class="block text-xs font-bold text-gray-700 mb-1">Categoria Desejada</label>
                    <select name="categoria" required class="w-full bg-gray-50 border border-gray-200 rounded-xl px-3 py-2 text-xs focus:ring-2 focus:ring-purple-500">
                        <option value="promocoes">🔥 Promoções & Ofertas Irresistíveis</option>
                        <option value="servicos">💼 Serviços & Soluções Rápidas</option>
                        <option value="dicas">💡 Dicas & Economia para o Cliente</option>
                        <option value="depoimentos">⭐ Prova Social & Depoimentos 5 Estrelas</option>
                        <option value="institucional">🏢 Institucional & Equipe de Confiança</option>
                    </select>
                </div>

                <div>
                    <label class="block text-xs font-bold text-gray-700 mb-1">Quantidade a Gerar</label>
                    <select name="quantidade" class="w-full bg-gray-50 border border-gray-200 rounded-xl px-3 py-2 text-xs focus:ring-2 focus:ring-purple-500">
                        <option value="3" selected>3 Novos Modelos</option>
                        <option value="5">5 Novos Modelos</option>
                    </select>
                </div>

                <div class="p-3 bg-purple-50 border border-purple-200 rounded-xl text-xs text-purple-900 space-y-1">
                    <p class="font-bold flex items-center gap-1"><span>⚡</span> Foco em WhatsApp & Ligações:</p>
                    <p class="text-[11px] text-purple-700">A IA criará copies persuasivas com botão "Ligar Agora" e tags automáticas <code class="font-mono">{bairro}</code> e <code class="font-mono">{empresa}</code>.</p>
                </div>

                <div class="flex justify-end gap-2 pt-2">
                    <button type="button" @click="modalIa = false" class="px-4 py-2 bg-gray-100 text-gray-700 rounded-xl text-xs font-semibold">Cancelar</button>
                    <button type="submit" class="px-5 py-2 bg-purple-600 hover:bg-purple-700 text-white font-bold rounded-xl text-xs shadow transition">✨ Gerar com IA</button>
                </div>
            </form>
        </div>
    </div>

</div>
@endsection
