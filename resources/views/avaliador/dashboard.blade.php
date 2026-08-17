@extends('layouts.app')
@section('title', 'Minhas Avaliações — Lead Certo')

@section('content')
<div class="max-w-4xl mx-auto">

    {{-- Header --}}
    <div class="mb-6">
        <h1 class="text-2xl font-bold text-gray-800">📋 Minhas Avaliações</h1>
        <p class="text-sm text-gray-500 mt-1">
            Semana de {{ $semana->copy()->startOfWeek(\Carbon\Carbon::MONDAY)->format('d/m') }}
            a {{ $semana->copy()->endOfWeek(\Carbon\Carbon::SUNDAY)->format('d/m/Y') }}
        </p>
    </div>

    {{-- Navegação de semana --}}
    <div class="flex gap-2 mb-4">
        <a href="?semana={{ $semana->copy()->subWeek()->toDateString() }}"
           class="px-3 py-1 bg-gray-200 rounded text-sm hover:bg-gray-300">← Anterior</a>
        <a href="?semana={{ now()->toDateString() }}"
           class="px-3 py-1 bg-green-100 text-green-700 rounded text-sm hover:bg-green-200">Semana Atual</a>
        <a href="?semana={{ $semana->copy()->addWeek()->toDateString() }}"
           class="px-3 py-1 bg-gray-200 rounded text-sm hover:bg-gray-300">Próxima →</a>
    </div>

    {{-- Alertas --}}
    @if(session('sucesso'))
        <div class="mb-4 p-3 bg-green-100 text-green-800 rounded-lg text-sm">{{ session('sucesso') }}</div>
    @endif
    @if(session('aviso'))
        <div class="mb-4 p-3 bg-yellow-100 text-yellow-800 rounded-lg text-sm">{{ session('aviso') }}</div>
    @endif

    {{-- Estatísticas --}}
    <div class="grid grid-cols-4 gap-3 mb-6">
        <div class="bg-white rounded-xl shadow p-4 text-center">
            <p class="text-2xl font-bold text-gray-800">{{ $totalSemana }}</p>
            <p class="text-xs text-gray-500">Total</p>
        </div>
        <div class="bg-white rounded-xl shadow p-4 text-center">
            <p class="text-2xl font-bold text-green-600">{{ $concluidos }}</p>
            <p class="text-xs text-gray-500">Concluídos ✅</p>
        </div>
        <div class="bg-white rounded-xl shadow p-4 text-center">
            <p class="text-2xl font-bold text-yellow-600">{{ $pendentes }}</p>
            <p class="text-xs text-gray-500">Pendentes</p>
        </div>
        <div class="bg-white rounded-xl shadow p-4 text-center">
            <p class="text-2xl font-bold text-red-600">{{ $atrasados }}</p>
            <p class="text-xs text-gray-500">Em Atraso ⚠️</p>
        </div>
    </div>

    {{-- Tarefas agrupadas por dia --}}
    @forelse($tarefasPorDia as $dia => $tarefas)
        <div class="mb-6">
            <h2 class="text-sm font-semibold text-gray-500 uppercase tracking-wider mb-3">{{ $dia }}</h2>

            <div class="space-y-3">
                @foreach($tarefas as $tarefa)
                <div class="bg-white rounded-xl shadow p-4 border-l-4
                    {{ $tarefa->status === 'concluido' ? 'border-green-500 opacity-70' :
                       ($tarefa->estaAtrasado() ? 'border-red-500' : 'border-yellow-400') }}">

                    <div class="flex items-start justify-between">
                        <div class="flex-1">
                            {{-- Nome da empresa --}}
                            <h3 class="font-semibold text-gray-800 text-lg">{{ $tarefa->perfil->nome }}</h3>
                            <p class="text-xs text-gray-400 mb-3">{{ $tarefa->perfil->city }}/{{ $tarefa->perfil->state }}</p>

                            {{-- Link GMB (referência do perfil a ligar) --}}
                            <a href="{{ $tarefa->perfil->link_gmb }}" target="_blank"
                               class="inline-flex items-center gap-1 px-3 py-1.5 bg-blue-100 text-blue-700 rounded-lg text-sm hover:bg-blue-200 transition mb-3">
                                🌐 Ver perfil no Google Meu Negócio ↗
                            </a>

                            {{-- Texto sugerido para enviar ao cliente --}}
                            <div class="mt-3">
                                <p class="text-xs font-medium text-gray-500 mb-1">
                                    Texto sugerido pra oferecer ao cliente ({{ $tarefa->template->categoria?->nome }}):
                                </p>
                                <div class="bg-gray-50 border border-gray-200 rounded-lg p-3 text-sm text-gray-700 relative group">
                                    <p id="texto-{{ $tarefa->id }}">{{ $tarefa->template->textoResolvido($tarefa->perfil->tenant->nome) }}</p>
                                    <button type="button"
                                            onclick="navigator.clipboard.writeText(document.getElementById('texto-{{ $tarefa->id }}').innerText).then(() => alert('Texto copiado! ✅'))"
                                            class="absolute top-2 right-2 px-2 py-1 bg-white border border-gray-300 rounded text-xs text-gray-600 hover:bg-gray-100 opacity-0 group-hover:opacity-100 transition">
                                        📋 Copiar
                                    </button>
                                </div>
                            </div>
                        </div>

                        {{-- Status e ação --}}
                        <div class="ml-4 text-center">
                            @if($tarefa->status === 'concluido')
                                <span class="px-3 py-1 bg-green-100 text-green-700 rounded-full text-xs font-semibold">
                                    ✅ Concluído
                                </span>
                                @if($tarefa->concluidoComAtraso())
                                    <p class="text-xs text-orange-500 mt-1">Com atraso</p>
                                @endif
                            @else
                                @if($tarefa->estaAtrasado())
                                    <span class="px-3 py-1 bg-red-100 text-red-700 rounded-full text-xs font-semibold mb-2 block">
                                        ⚠️ Em Atraso
                                    </span>
                                @endif
                                <form action="{{ route('avaliador.concluir', $tarefa) }}" method="POST">
                                    @csrf
                                    <button type="submit"
                                            class="px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 text-sm font-semibold transition"
                                            onclick="return confirm('Confirma que você ligou pro cliente e ofereceu o texto sugerido?')">
                                        ✅ Concluir
                                    </button>
                                </form>
                            @endif
                        </div>
                    </div>
                </div>
                @endforeach
            </div>
        </div>
    @empty
        <div class="bg-white rounded-xl shadow p-8 text-center text-gray-400">
            <p class="text-lg">🎉 Nenhuma avaliação para esta semana!</p>
            <p class="text-sm mt-1">Volte mais tarde ou confira outra semana.</p>
        </div>
    @endforelse
</div>
@endsection
