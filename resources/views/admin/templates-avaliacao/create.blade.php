@extends('layouts.app')
@section('title', 'Novo Template — Lead Certo')

@section('content')
<div class="max-w-2xl mx-auto">
    <h1 class="text-2xl font-bold text-gray-800 mb-6">📝 Novo Template de Avaliação</h1>

    @if($errors->any())
        <div class="mb-4 p-3 bg-red-100 text-red-800 rounded-lg text-sm">
            @foreach($errors->all() as $error) <p>{{ $error }}</p> @endforeach
        </div>
    @endif

    <form action="{{ route('admin.templates-avaliacao.store') }}" method="POST" class="bg-white rounded-xl shadow p-6 space-y-4">
        @csrf

        <div class="grid grid-cols-2 gap-4">
            <div>
                <label for="codigo" class="block text-sm font-medium text-gray-700 mb-1">Código</label>
                <input type="text" name="codigo" id="codigo" value="{{ old('codigo') }}" required maxlength="30"
                       class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm font-mono focus:ring-2 focus:ring-green-500 focus:border-green-500"
                       placeholder="AVAL-001">
            </div>
            <div>
                <label for="categoria_id" class="block text-sm font-medium text-gray-700 mb-1">Categoria</label>
                <select name="categoria_id" id="categoria_id" required
                        class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-green-500 focus:border-green-500">
                    <option value="">Selecione...</option>
                    @foreach($categorias as $cat)
                        <option value="{{ $cat->id }}" {{ old('categoria_id') == $cat->id ? 'selected' : '' }}>
                            {{ $cat->nome }}
                        </option>
                    @endforeach
                </select>
            </div>
        </div>

        <div>
            <label for="texto" class="block text-sm font-medium text-gray-700 mb-1">Texto da Avaliação</label>
            <textarea name="texto" id="texto" rows="6" required
                      class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-green-500 focus:border-green-500"
                      placeholder="Excelente serviço! O atendimento do Leonardo foi nota 10...">{{ old('texto') }}</textarea>
            <p class="text-xs text-gray-400 mt-1">Este texto será copiado e colado pelo avaliador no Google.</p>
        </div>

        <div class="flex items-center gap-2">
            <input type="checkbox" name="ativo" id="ativo" value="1" checked
                   class="rounded border-gray-300 text-green-600 focus:ring-green-500">
            <label for="ativo" class="text-sm text-gray-700">Template ativo</label>
        </div>

        <div class="flex gap-3 pt-4">
            <button type="submit" class="px-6 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 text-sm font-semibold transition">
                Salvar Template
            </button>
            <a href="{{ route('admin.templates-avaliacao.index') }}" class="px-6 py-2 bg-gray-200 text-gray-700 rounded-lg hover:bg-gray-300 text-sm transition">
                Cancelar
            </a>
        </div>
    </form>
</div>
@endsection
