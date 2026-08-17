@extends('layouts.app')
@section('title', 'Categorias — Lead Certo')

@section('content')
<div class="max-w-4xl mx-auto">
    <div class="flex items-center justify-between mb-6">
        <div>
            <h1 class="text-2xl font-bold text-gray-800">🏷️ Categorias de Templates</h1>
            <p class="text-sm text-gray-500 mt-1">Organize os templates por categoria (pode usar emojis!). As palavras-chave dão direção pro gerador de rascunho por IA.</p>
        </div>
        <a href="{{ route('admin.templates-avaliacao.index') }}" class="text-sm text-gray-500 hover:text-gray-700">← Voltar aos Templates</a>
    </div>

    @if(session('sucesso'))
        <div class="mb-4 p-3 bg-green-100 text-green-800 rounded-lg text-sm">✅ {{ session('sucesso') }}</div>
    @endif
    @if($errors->any())
        <div class="mb-4 p-3 bg-red-100 text-red-800 rounded-lg text-sm">
            @foreach($errors->all() as $error) <p>{{ $error }}</p> @endforeach
        </div>
    @endif

    {{-- Formulário de nova categoria --}}
    <form action="{{ route('admin.templates-avaliacao.categorias.store') }}" method="POST"
          class="bg-white rounded-xl shadow p-4 mb-6 space-y-3">
        @csrf
        <div>
            <label for="nome" class="block text-sm font-medium text-gray-700 mb-1">Nova Categoria</label>
            <input type="text" name="nome" id="nome" required maxlength="100"
                   class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-green-500 focus:border-green-500"
                   placeholder="⭐ Atendimento">
        </div>
        <div>
            <label for="palavras_chave" class="block text-sm font-medium text-gray-700 mb-1">Palavras-chave (opcional, separadas por vírgula)</label>
            <input type="text" name="palavras_chave" id="palavras_chave" maxlength="500"
                   class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-green-500 focus:border-green-500"
                   placeholder="pontualidade, preço justo, cuidado com os móveis">
        </div>
        <button type="submit" class="px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 text-sm font-semibold transition">
            Criar
        </button>
    </form>

    {{-- Lista de categorias --}}
    <div class="space-y-3">
        @forelse($categorias as $cat)
        <div class="bg-white rounded-xl shadow p-4">
            <div class="flex items-start justify-between gap-4">
                <div>
                    <p class="font-medium text-gray-800">{{ $cat->nome }}</p>
                    <p class="text-xs text-gray-400 mt-0.5">{{ $cat->templates_count }} template(s)</p>
                </div>
                @if($cat->templates_count == 0)
                <form action="{{ route('admin.templates-avaliacao.categorias.destroy', $cat) }}" method="POST">
                    @csrf @method('DELETE')
                    <button type="submit" class="text-red-500 hover:underline text-xs"
                            onclick="return confirm('Remover esta categoria?')">Remover</button>
                </form>
                @else
                    <span class="text-xs text-gray-400 whitespace-nowrap">Em uso</span>
                @endif
            </div>

            {{-- Palavras-chave (edição inline) --}}
            <form action="{{ route('admin.templates-avaliacao.categorias.update', $cat) }}" method="POST"
                  class="mt-3 flex gap-2 items-end">
                @csrf @method('PUT')
                <div class="flex-1">
                    <label class="block text-xs font-medium text-gray-500 mb-1">Palavras-chave</label>
                    <input type="text" name="palavras_chave" maxlength="500"
                           value="{{ implode(', ', $cat->palavras_chave ?? []) }}"
                           class="w-full border border-gray-300 rounded-lg px-3 py-1.5 text-sm focus:ring-2 focus:ring-green-500 focus:border-green-500"
                           placeholder="pontualidade, preço justo, cuidado com os móveis">
                </div>
                <button type="submit" class="px-3 py-1.5 bg-gray-200 text-gray-700 rounded-lg hover:bg-gray-300 text-xs font-semibold transition whitespace-nowrap">
                    Salvar
                </button>
            </form>

            {{-- Gerar rascunhos por IA --}}
            <form action="{{ route('admin.templates-avaliacao.gerar-ia') }}" method="POST"
                  class="mt-3 pt-3 border-t border-gray-100 flex gap-2 items-end flex-wrap">
                @csrf
                <input type="hidden" name="categoria_id" value="{{ $cat->id }}">
                <div>
                    <label class="block text-xs font-medium text-gray-500 mb-1">Quantidade</label>
                    <input type="number" name="quantidade" value="5" min="1" max="10"
                           class="w-20 border border-gray-300 rounded-lg px-2 py-1.5 text-sm focus:ring-2 focus:ring-purple-500 focus:border-purple-500">
                </div>
                <div class="flex-1 min-w-[200px]">
                    <label class="block text-xs font-medium text-gray-500 mb-1">Contexto extra (opcional)</label>
                    <input type="text" name="contexto" maxlength="1000"
                           class="w-full border border-gray-300 rounded-lg px-3 py-1.5 text-sm focus:ring-2 focus:ring-purple-500 focus:border-purple-500"
                           placeholder="ex.: somos especializados em mudanças de apartamento">
                </div>
                <label class="flex items-center gap-1.5 text-xs text-gray-600 pb-1.5 whitespace-nowrap">
                    <input type="checkbox" name="incluir_nome_atendente" value="1" checked
                           class="rounded border-gray-300 text-purple-600 focus:ring-purple-500">
                    Incluir espaço pro nome de quem atendeu
                </label>
                <button type="submit" class="px-3 py-1.5 bg-purple-600 text-white rounded-lg hover:bg-purple-700 text-xs font-semibold transition whitespace-nowrap">
                    🤖 Gerar rascunhos com IA
                </button>
            </form>
        </div>
        @empty
        <div class="bg-white rounded-xl shadow p-8 text-center text-gray-400">Nenhuma categoria cadastrada.</div>
        @endforelse
    </div>
</div>
@endsection
