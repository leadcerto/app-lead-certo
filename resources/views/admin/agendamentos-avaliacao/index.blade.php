@extends('layouts.app')
@section('title', 'Agendamentos — Lead Certo')

@section('content')
<div class="max-w-4xl mx-auto">

    {{-- Header --}}
    <div class="flex items-center justify-between mb-6">
        <div>
            <h1 class="text-2xl font-bold text-gray-800">📅 Agendamentos de Avaliações</h1>
            <p class="text-sm text-gray-500 mt-1">
                Semana de {{ $semana->copy()->startOfWeek(\Carbon\Carbon::MONDAY)->format('d/m') }}
                a {{ $semana->copy()->endOfWeek(\Carbon\Carbon::SUNDAY)->format('d/m/Y') }}
            </p>
        </div>
        <div class="flex gap-2 flex-wrap justify-end">
            <a href="{{ route('admin.agendamentos-avaliacao.create') }}"
               class="px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 text-sm font-semibold transition">
                + Individual
            </a>
            <a href="{{ route('admin.agendamentos-avaliacao.lote') }}"
               class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 text-sm font-semibold transition">
                📊 Lote (Matriz)
            </a>
            <form action="{{ route('admin.agendamentos-avaliacao.alertar') }}" method="POST" class="inline">
                @csrf
                <button type="submit" class="px-4 py-2 bg-orange-500 text-white rounded-lg hover:bg-orange-600 text-sm font-semibold transition"
                        onclick="return confirm('Enviar alerta para todos os avaliadores com tarefas pendentes?')">
                    📧 Alertar Avaliadores
                </button>
            </form>
        </div>
    </div>

    {{-- Navegação de semana --}}
    <div class="flex gap-2 mb-4">
        <a href="?semana={{ $semana->copy()->subWeek()->toDateString() }}"
           class="px-3 py-1 bg-gray-200 rounded text-sm hover:bg-gray-300">← Semana Anterior</a>
        <a href="?semana={{ now()->toDateString() }}"
           class="px-3 py-1 bg-green-100 text-green-700 rounded text-sm hover:bg-green-200">Semana Atual</a>
        <a href="?semana={{ $semana->copy()->addWeek()->toDateString() }}"
           class="px-3 py-1 bg-gray-200 rounded text-sm hover:bg-gray-300">Próxima Semana →</a>
    </div>

    {{-- Alertas --}}
    @if(session('sucesso'))
        <div class="mb-4 p-3 bg-green-100 text-green-800 rounded-lg text-sm">✅ {{ session('sucesso') }}</div>
    @endif
    @if($errors->any())
        <div class="mb-4 p-3 bg-red-100 text-red-800 rounded-lg text-sm">
            @foreach($errors->all() as $error) <p>{{ $error }}</p> @endforeach
        </div>
    @endif

    {{-- Estatísticas rápidas --}}
    <div class="grid grid-cols-4 gap-4 mb-6">
        <div class="bg-white rounded-xl shadow p-4 text-center">
            <p class="text-2xl font-bold text-gray-800">{{ $agendamentos->count() }}</p>
            <p class="text-xs text-gray-500">Total da Semana</p>
        </div>
        <div class="bg-white rounded-xl shadow p-4 text-center">
            <p class="text-2xl font-bold text-yellow-600">{{ $agendamentos->where('status', 'pendente')->count() }}</p>
            <p class="text-xs text-gray-500">Pendentes</p>
        </div>
        <div class="bg-white rounded-xl shadow p-4 text-center">
            <p class="text-2xl font-bold text-blue-600">{{ $agendamentos->where('status', 'enviado')->count() }}</p>
            <p class="text-xs text-gray-500">Enviados</p>
        </div>
        <div class="bg-white rounded-xl shadow p-4 text-center">
            <p class="text-2xl font-bold text-green-600">{{ $agendamentos->where('status', 'concluido')->count() }}</p>
            <p class="text-xs text-gray-500">Concluídos</p>
        </div>
    </div>

    {{-- Agendamentos agrupados por dia — mesmo modelo visual do painel do avaliador --}}
    @forelse($agendamentosPorDia as $dia => $tarefas)
        <div class="mb-6">
            <h2 class="text-sm font-semibold text-gray-500 uppercase tracking-wider mb-3">{{ $dia }}</h2>

            <div class="space-y-3">
                @foreach($tarefas as $ag)
                <div class="bg-white rounded-xl shadow p-4 border-l-4
                    {{ $ag->status === 'concluido' ? 'border-green-500 opacity-70' :
                       ($ag->estaAtrasado() ? 'border-red-500' : 'border-yellow-400') }}">

                    <div class="flex items-start justify-between gap-4 flex-wrap">
                        <div class="flex-1 min-w-[220px]">
                            <h3 class="font-semibold text-gray-800">{{ $ag->perfil->nome }}</h3>
                            <p class="text-xs text-gray-400">{{ $ag->perfil->city }}/{{ $ag->perfil->state }} · Avaliador: {{ $ag->avaliador->nome ?? '—' }}</p>
                            <p class="text-xs text-gray-400 mt-0.5">
                                <span class="font-mono">{{ $ag->template->codigo }}</span>
                                ({{ $ag->template->categoria?->nome }})
                            </p>
                            @if($ag->estaAtrasado())
                                <span class="inline-block mt-1 px-2 py-0.5 bg-red-100 text-red-700 rounded-full text-xs font-semibold">⚠️ Em Atraso</span>
                            @endif
                        </div>

                        <div class="flex flex-col items-end gap-2">
                            @if($ag->status === 'concluido')
                                <span class="px-3 py-1 bg-green-100 text-green-700 rounded-full text-xs font-semibold">✅ Concluído</span>
                            @elseif($ag->status === 'enviado')
                                <span class="px-3 py-1 bg-blue-100 text-blue-700 rounded-full text-xs font-semibold">Enviado</span>
                            @else
                                <span class="px-3 py-1 bg-yellow-100 text-yellow-700 rounded-full text-xs font-semibold">Pendente</span>
                            @endif

                            <div class="flex gap-2 items-center">
                                <form action="{{ route('admin.agendamentos-avaliacao.status', $ag) }}" method="POST" class="inline">
                                    @csrf @method('PATCH')
                                    <select name="status" onchange="this.form.submit()" class="text-xs border border-gray-300 rounded px-1.5 py-1">
                                        <option value="pendente" {{ $ag->status === 'pendente' ? 'selected' : '' }}>Pendente</option>
                                        <option value="enviado" {{ $ag->status === 'enviado' ? 'selected' : '' }}>Enviado</option>
                                        <option value="concluido" {{ $ag->status === 'concluido' ? 'selected' : '' }}>Concluído</option>
                                    </select>
                                </form>
                                <form action="{{ route('admin.agendamentos-avaliacao.refazer-template', $ag) }}" method="POST" class="inline">
                                    @csrf @method('PATCH')
                                    <button type="submit" class="px-2 py-1 bg-gray-100 text-purple-600 rounded hover:bg-gray-200 text-xs" title="Sortear novo template">🔄</button>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>
                @endforeach
            </div>
        </div>
    @empty
        <div class="bg-white rounded-xl shadow p-8 text-center text-gray-400">
            <p class="text-lg">🎉 Nenhum agendamento para esta semana!</p>
        </div>
    @endforelse
</div>
@endsection
