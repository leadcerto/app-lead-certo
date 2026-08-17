@extends('layouts.app')
@section('title', 'Categorias — Lead Certo')

@section('content')
<div class="max-w-3xl mx-auto">
    <div class="flex items-center justify-between mb-6">
        <div>
            <h1 class="text-2xl font-bold text-gray-800">🏷️ Categorias de Templates</h1>
            <p class="text-sm text-gray-500 mt-1">Organize os templates por categoria (pode usar emojis!).</p>
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
          class="bg-white rounded-xl shadow p-4 mb-6 flex gap-3 items-end">
        @csrf
        <div class="flex-1">
            <label for="nome" class="block text-sm font-medium text-gray-700 mb-1">Nova Categoria</label>
            <input type="text" name="nome" id="nome" required maxlength="100"
                   class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-green-500 focus:border-green-500"
                   placeholder="⭐ Atendimento">
        </div>
        <button type="submit" class="px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 text-sm font-semibold transition">
            Criar
        </button>
    </form>

    {{-- Lista de categorias --}}
    <div class="bg-white rounded-xl shadow overflow-hidden">
        <table class="w-full text-sm">
            <thead class="bg-gray-50 text-gray-600">
                <tr>
                    <th class="px-4 py-3 text-left">Categoria</th>
                    <th class="px-4 py-3 text-center">Templates</th>
                    <th class="px-4 py-3 text-center">Ações</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @forelse($categorias as $cat)
                <tr class="hover:bg-gray-50">
                    <td class="px-4 py-3 font-medium text-gray-800">{{ $cat->nome }}</td>
                    <td class="px-4 py-3 text-center text-gray-600">{{ $cat->templates_count }}</td>
                    <td class="px-4 py-3 text-center">
                        @if($cat->templates_count == 0)
                        <form action="{{ route('admin.templates-avaliacao.categorias.destroy', $cat) }}" method="POST" class="inline">
                            @csrf @method('DELETE')
                            <button type="submit" class="text-red-500 hover:underline text-xs"
                                    onclick="return confirm('Remover esta categoria?')">Remover</button>
                        </form>
                        @else
                            <span class="text-xs text-gray-400">Em uso</span>
                        @endif
                    </td>
                </tr>
                @empty
                <tr>
                    <td colspan="3" class="px-4 py-8 text-center text-gray-400">Nenhuma categoria cadastrada.</td>
                </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection
