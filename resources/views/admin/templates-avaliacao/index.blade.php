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

    <div class="space-y-4">
        @php $categoriaAtual = null; @endphp
        @forelse($templates as $template)
            @if($template->categoria_id !== $categoriaAtual)
                @php $categoriaAtual = $template->categoria_id; @endphp
                <h2 class="text-sm font-semibold text-gray-600 uppercase tracking-wide {{ !$loop->first ? 'pt-2' : '' }}">
                    {{ $template->categoria?->nome ?? 'Sem categoria' }}
                </h2>
            @endif

            <div class="bg-white rounded-xl shadow p-4">
                <div class="flex items-start justify-between gap-4">
                    <div class="flex items-center gap-2">
                        <span class="font-mono text-xs text-gray-500 bg-gray-100 px-2 py-0.5 rounded">{{ $template->codigo }}</span>
                        @if($template->ativo)
                            <span class="px-2 py-0.5 bg-green-100 text-green-700 rounded-full text-xs font-semibold">Ativo</span>
                        @else
                            <span class="px-2 py-0.5 bg-red-100 text-red-700 rounded-full text-xs font-semibold">Inativo</span>
                        @endif
                    </div>
                    <div class="flex gap-3 flex-shrink-0">
                        <a href="{{ route('admin.templates-avaliacao.edit', $template) }}" class="text-blue-600 hover:underline text-xs">Editar</a>
                        <form action="{{ route('admin.templates-avaliacao.destroy', $template) }}" method="POST" class="inline">
                            @csrf @method('DELETE')
                            <button type="submit" class="text-red-500 hover:underline text-xs"
                                    onclick="return confirm('Desativar este template?')">Desativar</button>
                        </form>
                    </div>
                </div>
                <p class="text-sm text-gray-700 mt-2 whitespace-pre-line">{{ $template->texto }}</p>
            </div>
        @empty
            <div class="bg-white rounded-xl shadow p-8 text-center text-gray-400">Nenhum template cadastrado.</div>
        @endforelse
    </div>

    <div class="mt-4">{{ $templates->links() }}</div>
</div>
@endsection
