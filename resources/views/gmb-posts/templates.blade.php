@extends('layouts.app')

@section('title', 'Templates de Postagens — Lead Certo')

@section('content')
<div class="max-w-6xl mx-auto space-y-6" x-data="{ modalCriar: false }">

    {{-- Header --}}
    <div class="flex items-center justify-between flex-wrap gap-4">
        <div>
            <div class="flex items-center gap-2">
                <span class="text-2xl">📑</span>
                <h1 class="text-2xl font-bold text-gray-800">Templates de Postagens (GMB)</h1>
            </div>
            <p class="text-sm text-gray-500 mt-1">
                Modelos prontos de alta conversão para publicações semanais com substituição dinâmica de <code class="bg-gray-100 px-1 py-0.5 rounded text-green-700 font-mono font-bold">{bairro}</code>, <code class="bg-gray-100 px-1 py-0.5 rounded text-green-700 font-mono font-bold">{cidade}</code> e <code class="bg-gray-100 px-1 py-0.5 rounded text-green-700 font-mono font-bold">{empresa}</code>.
            </p>
        </div>
        <div class="flex items-center gap-2">
            <button @click="modalCriar = true" class="px-4 py-2 bg-green-600 hover:bg-green-700 text-white text-xs font-bold rounded-xl shadow-sm transition flex items-center gap-1.5">
                + Novo Template
            </button>
            <a href="{{ route('admin.gmb-posts.lote') }}" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white text-xs font-bold rounded-xl shadow-sm transition flex items-center gap-1.5">
                📊 Gerador em Lote
            </a>
            <a href="{{ route('admin.gmb-posts.index') }}" class="px-3.5 py-2 bg-gray-100 hover:bg-gray-200 text-gray-700 text-xs font-semibold rounded-xl transition">
                ← Voltar
            </a>
        </div>
    </div>

    @if(session('sucesso'))
        <div class="p-4 bg-green-100 border border-green-200 text-green-800 rounded-2xl text-sm flex items-center gap-2 shadow-sm">
            <span>✅</span>
            <span>{{ session('sucesso') }}</span>
        </div>
    @endif

    {{-- Grid de Templates por Categoria --}}
    <div class="space-y-8">
        @foreach($templatesPorCategoria as $categoria => $tpls)
            <div class="space-y-3">
                <div class="flex items-center gap-2 border-b border-gray-200 pb-2">
                    <span class="text-xs font-black uppercase tracking-wider px-2.5 py-1 bg-gray-200 text-gray-800 rounded-lg">
                        {{ match($categoria) {
                            'promocoes' => '🔥 Promoções & Ofertas',
                            'servicos' => '💼 Serviços & Soluções',
                            'dicas' => '💡 Dicas & Utilidade Pública',
                            'depoimentos' => '⭐ Depoimentos & Prova Social',
                            'institucional' => '🏢 Institucional & Autoridade',
                            default => '📌 ' . ucfirst($categoria),
                        } }}
                    </span>
                    <span class="text-xs text-gray-400">({{ $tpls->count() }} modelos)</span>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                    @foreach($tpls as $t)
                        <div class="bg-white rounded-2xl p-5 border border-gray-100 shadow-sm hover:shadow-md transition flex flex-col justify-between space-y-3 relative group">
                            <div>
                                <div class="flex items-start justify-between gap-2 mb-2">
                                    <h3 class="font-bold text-gray-900 text-sm leading-tight">{{ $t->titulo_template }}</h3>
                                    <span class="text-[10px] font-bold px-2 py-0.5 bg-green-50 text-green-700 border border-green-200 rounded-md flex-shrink-0">
                                        {{ $t->cta_tipo_padrao }}
                                    </span>
                                </div>
                                <div class="bg-gray-50 p-3 rounded-xl border border-gray-100 text-xs text-gray-600 whitespace-pre-line leading-relaxed font-sans max-h-48 overflow-y-auto">
                                    {{ $t->texto_template }}
                                </div>
                            </div>

                            <div class="flex items-center justify-between pt-2 border-t border-gray-50 text-xs">
                                <span class="text-[11px] text-gray-400 font-mono">ID #{{ $t->id }}</span>
                                <form action="{{ route('admin.gmb-posts.templates.destroy', $t->id) }}" method="POST" onsubmit="return confirm('Deseja realmente remover este template?')">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="text-red-500 hover:text-red-700 font-semibold transition text-xs">
                                        Excluir
                                    </button>
                                </form>
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        @endforeach
    </div>

    {{-- Modal Criar Template --}}
    <div x-show="modalCriar" 
         x-transition:enter="transition ease-out duration-200"
         x-transition:enter-start="opacity-0"
         x-transition:enter-end="opacity-100"
         class="fixed inset-0 z-50 bg-black/60 backdrop-blur-sm flex items-center justify-center p-4" 
         style="display: none;">
        
        <div class="bg-white rounded-2xl max-w-lg w-full p-6 space-y-5 shadow-2xl border border-gray-100" @click.outside="modalCriar = false">
            <div class="flex items-center justify-between border-b border-gray-100 pb-3">
                <h2 class="text-lg font-bold text-gray-900 flex items-center gap-2">
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
                            <option value="depoimentos">Depoimentos / Social</option>
                            <option value="institucional">Institucional</option>
                        </select>
                    </div>

                    <div>
                        <label class="block text-xs font-bold text-gray-700 mb-1">Botão CTA Padrão</label>
                        <select name="cta_tipo_padrao" required class="w-full bg-gray-50 border border-gray-200 rounded-xl px-3 py-2 text-xs focus:ring-2 focus:ring-green-500">
                            <option value="CALL">Ligar Agora (CALL)</option>
                            <option value="LEARN_MORE">Saiba Mais (LEARN_MORE)</option>
                            <option value="ORDER">Fazer Pedido (ORDER)</option>
                            <option value="BOOK">Agendar (BOOK)</option>
                            <option value="SIGN_UP">Cadastre-se (SIGN_UP)</option>
                        </select>
                    </div>
                </div>

                <div>
                    <label class="block text-xs font-bold text-gray-700 mb-1">Título do Template</label>
                    <input type="text" name="titulo_template" required placeholder="Ex: Oferta da Semana em {bairro}" class="w-full bg-gray-50 border border-gray-200 rounded-xl px-3 py-2 text-xs focus:ring-2 focus:ring-green-500">
                </div>

                <div>
                    <label class="block text-xs font-bold text-gray-700 mb-1">Texto / Copy do Post</label>
                    <textarea name="texto_template" rows="6" required placeholder="Digite a mensagem. Use {empresa}, {bairro} e {cidade} para substituição automática." class="w-full bg-gray-50 border border-gray-200 rounded-xl px-3 py-2 text-xs focus:ring-2 focus:ring-green-500"></textarea>
                </div>

                <div class="p-3 bg-blue-50 border border-blue-200 rounded-xl text-[11px] text-blue-800 space-y-1">
                    <p class="font-bold">Tags Dinâmicas Suportadas:</p>
                    <p>• <code class="font-mono">{bairro}</code> → Nome do Bairro / Ficha (ex: Barra da Tijuca)</p>
                    <p>• <code class="font-mono">{cidade}</code> → Cidade do Perfil (ex: Rio de Janeiro)</p>
                    <p>• <code class="font-mono">{empresa}</code> → Nome da sua Empresa / Tenant</p>
                </div>

                <div class="flex justify-end gap-2 pt-2">
                    <button type="button" @click="modalCriar = false" class="px-4 py-2 bg-gray-100 text-gray-700 rounded-xl text-xs font-semibold">Cancelar</button>
                    <button type="submit" class="px-5 py-2 bg-green-600 hover:bg-green-700 text-white font-bold rounded-xl text-xs shadow transition">Salvar Template</button>
                </div>
            </form>
        </div>
    </div>

</div>
@endsection
