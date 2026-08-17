@extends('layouts.app')
@section('title', 'Templates de Avaliação — Lead Certo')

@section('content')
<div class="max-w-6xl mx-auto">

    <div class="flex items-center justify-between mb-6">
        <div>
            <h1 class="text-2xl font-bold text-gray-800">📝 Templates de Avaliação</h1>
            <p class="text-sm text-gray-500 mt-1">Rascunhos que os avaliadores oferecem ao cliente por telefone — o cliente decide se usa, edita ou ignora.</p>
        </div>
        <div class="flex gap-2">
            <a href="{{ route('admin.templates-avaliacao.categorias') }}"
               class="px-4 py-2 bg-gray-200 text-gray-700 rounded-lg hover:bg-gray-300 text-sm transition">
                🏷️ Categorias
            </a>
            <a href="{{ route('admin.templates-avaliacao.create') }}"
               class="px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 text-sm font-semibold transition">
                + Novo Template
            </a>
        </div>
    </div>

    @if(session('sucesso'))
        <div class="mb-4 p-3 bg-green-100 text-green-800 rounded-lg text-sm">✅ {{ session('sucesso') }}</div>
    @endif

    <div class="bg-white rounded-xl shadow overflow-hidden">
        <table class="w-full text-sm">
            <thead class="bg-gray-50 text-gray-600">
                <tr>
                    <th class="px-4 py-3 text-left">Código</th>
                    <th class="px-4 py-3 text-left">Categoria</th>
                    <th class="px-4 py-3 text-left">Texto (prévia)</th>
                    <th class="px-4 py-3 text-center">Status</th>
                    <th class="px-4 py-3 text-center">Ações</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @forelse($templates as $template)
                <tr class="hover:bg-gray-50">
                    <td class="px-4 py-3 font-mono text-gray-800 font-medium">{{ $template->codigo }}</td>
                    <td class="px-4 py-3 text-gray-600">{{ $template->categoria?->nome ?? '—' }}</td>
                    <td class="px-4 py-3 text-gray-600 max-w-md truncate">{{ Str::limit($template->texto, 100) }}</td>
                    <td class="px-4 py-3 text-center">
                        @if($template->ativo)
                            <span class="px-2 py-1 bg-green-100 text-green-700 rounded-full text-xs font-semibold">Ativo</span>
                        @else
                            <span class="px-2 py-1 bg-red-100 text-red-700 rounded-full text-xs font-semibold">Inativo</span>
                        @endif
                    </td>
                    <td class="px-4 py-3 text-center space-x-2">
                        <a href="{{ route('admin.templates-avaliacao.edit', $template) }}" class="text-blue-600 hover:underline text-xs">Editar</a>
                        <form action="{{ route('admin.templates-avaliacao.destroy', $template) }}" method="POST" class="inline">
                            @csrf @method('DELETE')
                            <button type="submit" class="text-red-500 hover:underline text-xs"
                                    onclick="return confirm('Desativar este template?')">Desativar</button>
                        </form>
                    </td>
                </tr>
                @empty
                <tr>
                    <td colspan="5" class="px-4 py-8 text-center text-gray-400">Nenhum template cadastrado.</td>
                </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">{{ $templates->links() }}</div>
</div>
@endsection
