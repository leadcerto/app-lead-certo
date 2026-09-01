@extends('layouts.app')
@section('title', 'Categorias de Postagens — Lead Certo')

@section('content')
<div class="max-w-4xl mx-auto">
    <div class="flex items-center justify-between mb-6 flex-wrap gap-4">
        <div>
            <h1 class="text-2xl font-bold text-gray-800">🏷️ Categorias de Postagens</h1>
            <p class="text-sm text-gray-500 mt-1">Organize as postagens e templates do Google Meu Negócio por categorias estratégicas. As palavras-chave orientam o gerador com IA.</p>
        </div>
        <div class="flex gap-2">
            <a href="{{ route('admin.gmb-posts.templates') }}" class="px-3.5 py-2 bg-gray-100 text-gray-700 rounded-lg hover:bg-gray-200 text-xs font-semibold transition">
                ← Templates
            </a>
            <a href="{{ route('admin.gmb-posts.index') }}" class="px-3.5 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 text-xs font-semibold transition">
                Agenda de Postagens
            </a>
        </div>
    </div>

    @if(session('sucesso'))
        <div class="mb-4 p-3 bg-green-100 text-green-800 rounded-lg text-sm flex items-center gap-2">
            <span>✅</span>
            <span>{{ session('sucesso') }}</span>
        </div>
    @endif
    @if(isset($errors) && $errors->any())
        <div class="mb-4 p-3 bg-red-100 text-red-800 rounded-lg text-sm">
            @foreach($errors->all() as $error) <p>• {{ $error }}</p> @endforeach
        </div>
    @endif

    {{-- Formulário de nova categoria --}}
    <form action="{{ route('admin.gmb-posts.categorias.store') }}" method="POST"
          class="bg-white rounded-2xl shadow-sm border border-gray-100 p-5 mb-6 space-y-4">
        @csrf
        <div>
            <label for="nome" class="block text-sm font-bold text-gray-700 mb-1">Nova Categoria de Postagem</label>
            <input type="text" name="nome" id="nome" required maxlength="100"
                   class="w-full border border-gray-300 rounded-xl px-4 py-2.5 text-sm bg-gray-50 focus:bg-white focus:ring-2 focus:ring-green-500 focus:border-green-500 transition shadow-sm"
                   placeholder="Ex: ⚡ Atendimento Emergencial ou 🏷️ Promoção Relâmpago">
        </div>
        <div>
            <label for="palavras_chave" class="block text-sm font-bold text-gray-700 mb-1">Palavras-chave (separadas por vírgula para SEO e IA)</label>
            <input type="text" name="palavras_chave" id="palavras_chave" maxlength="500"
                   class="w-full border border-gray-300 rounded-xl px-4 py-2.5 text-sm bg-gray-50 focus:bg-white focus:ring-2 focus:ring-green-500 focus:border-green-500 transition shadow-sm"
                   placeholder="mudança rápida, orçamento no whatsapp, caminhão de frete, melhor preço">
        </div>
        <button type="submit" class="px-5 py-2.5 bg-green-600 text-white rounded-xl hover:bg-green-700 text-sm font-bold transition shadow-sm">
            + Criar Categoria
        </button>
    </form>

    {{-- Lista de categorias --}}
    <div class="space-y-4">
        @forelse($categorias as $cat)
        <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-5 space-y-4">
            <div class="flex items-start justify-between gap-4">
                <div>
                    <p class="font-bold text-gray-800 text-base">{{ $cat->nome }}</p>
                    <p class="text-xs text-gray-400 mt-0.5">{{ $cat->templates_count }} template(s) vinculados</p>
                </div>
                <div>
                    @if($cat->templates_count == 0)
                    <form action="{{ route('admin.gmb-posts.categorias.destroy', $cat) }}" method="POST">
                        @csrf @method('DELETE')
                        <button type="submit" class="text-red-500 hover:underline text-xs font-semibold"
                                onclick="return confirm('Deseja realmente remover esta categoria?')">Remover</button>
                    </form>
                    @else
                        <span class="text-xs text-gray-400 bg-gray-100 px-2 py-0.5 rounded">Em uso</span>
                    @endif
                </div>
            </div>

            {{-- Palavras-chave (edição inline) --}}
            <form action="{{ route('admin.gmb-posts.categorias.update', $cat) }}" method="POST"
                  class="flex gap-3 items-end flex-wrap">
                @csrf @method('PUT')
                <div class="w-full sm:w-56">
                    <label class="block text-xs font-semibold text-gray-600 mb-1">Nome da Categoria</label>
                    <input type="text" name="nome" value="{{ $cat->nome }}" required class="w-full border border-gray-300 rounded-xl px-3.5 py-2 text-sm bg-gray-50 focus:bg-white focus:ring-2 focus:ring-green-500">
                </div>
                <div class="flex-1 min-w-[240px]">
                    <label class="block text-xs font-semibold text-gray-600 mb-1">Palavras-chave SEO</label>
                    <input type="text" name="palavras_chave" maxlength="500"
                           value="{{ implode(', ', $cat->palavras_chave ?? []) }}"
                           class="w-full border border-gray-300 rounded-xl px-3.5 py-2 text-sm bg-gray-50 focus:bg-white focus:ring-2 focus:ring-green-500"
                           placeholder="palavras-chave separadas por vírgula">
                </div>
                <button type="submit" class="px-4 py-2 bg-gray-200 text-gray-700 rounded-xl hover:bg-gray-300 text-xs font-bold transition whitespace-nowrap">
                    Salvar
                </button>
            </form>

            {{-- Gerar rascunhos por IA para esta categoria --}}
            <form action="{{ route('admin.gmb-posts.templates.gerar-ia') }}" method="POST"
                  class="pt-3 border-t border-gray-100 flex gap-2 items-end flex-wrap">
                @csrf
                <input type="hidden" name="categoria" value="{{ $cat->slug ?: \Illuminate\Support\Str::slug($cat->nome) }}">
                <div>
                    <label class="block text-xs font-medium text-gray-500 mb-1">Gerar com IA</label>
                    <select name="quantidade" class="border border-gray-300 rounded-lg px-2 py-1.5 text-xs focus:ring-2 focus:ring-purple-500 bg-purple-50 text-purple-900 font-semibold">
                        <option value="3">3 novos templates</option>
                        <option value="5">5 novos templates</option>
                    </select>
                </div>
                <button type="submit" class="px-3 py-1.5 bg-purple-600 text-white rounded-lg hover:bg-purple-700 text-xs font-semibold transition">
                    🤖 Gerar Templates para esta Categoria
                </button>
            </form>
        </div>
        @empty
        <div class="bg-white rounded-xl shadow p-8 text-center text-gray-400">
            Nenhuma categoria de postagem cadastrada.
        </div>
        @endforelse
    </div>
</div>
@endsection
