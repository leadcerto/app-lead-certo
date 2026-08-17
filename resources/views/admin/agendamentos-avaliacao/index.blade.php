@extends('layouts.app')
@section('title', 'Agendamentos — Lead Certo')

@section('content')
<div class="max-w-7xl mx-auto">

    {{-- Header --}}
    <div class="flex items-center justify-between mb-6">
        <div>
            <h1 class="text-2xl font-bold text-gray-800">📅 Agendamentos de Avaliações</h1>
            <p class="text-sm text-gray-500 mt-1">
                Semana de {{ $semana->copy()->startOfWeek(\Carbon\Carbon::MONDAY)->format('d/m') }}
                a {{ $semana->copy()->endOfWeek(\Carbon\Carbon::SUNDAY)->format('d/m/Y') }}
            </p>
        </div>
        <div class="flex gap-2">
            <a href="{{ route('admin.agendamentos-avaliacao.create') }}"
               class="px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 text-sm font-semibold transition">
                + Individual
            </a>
            <a href="{{ route('admin.agendamentos-avaliacao.lote') }}"
               class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 text-sm font-semibold transition">
                📊 Lote (Matriz)
            </a>
            <a href="{{ route('admin.agendamentos-avaliacao.campanha') }}"
               class="px-4 py-2 bg-purple-600 text-white rounded-lg hover:bg-purple-700 text-sm font-semibold transition">
                🚀 Campanha
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

    {{-- Tabela de agendamentos --}}
    <div class="bg-white rounded-xl shadow overflow-hidden">
        <table class="w-full text-sm">
            <thead class="bg-gray-50 text-gray-600">
                <tr>
                    <th class="px-3 py-3 text-left">Data</th>
                    <th class="px-3 py-3 text-left">Perfil</th>
                    <th class="px-3 py-3 text-left">Template</th>
                    <th class="px-3 py-3 text-left">Avaliador</th>
                    <th class="px-3 py-3 text-center">Status</th>
                    <th class="px-3 py-3 text-center">Ações</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @forelse($agendamentos as $ag)
                <tr class="hover:bg-gray-50 {{ $ag->estaAtrasado() ? 'bg-red-50' : '' }}">
                    <td class="px-3 py-3 text-gray-700">
                        {{ $ag->data_agendada->format('d/m (D)') }}
                        @if($ag->estaAtrasado())
                            <span class="text-red-500 text-xs">⚠️ Atraso</span>
                        @endif
                    </td>
                    <td class="px-3 py-3 font-medium text-gray-800">{{ $ag->perfil->nome }}</td>
                    <td class="px-3 py-3 text-gray-600">
                        <span class="text-xs font-mono">{{ $ag->template->codigo }}</span>
                        <span class="text-xs text-gray-400 ml-1">({{ $ag->template->categoria?->nome }})</span>
                    </td>
                    <td class="px-3 py-3 text-gray-600">{{ $ag->avaliador->nome ?? '—' }}</td>
                    <td class="px-3 py-3 text-center">
                        @if($ag->status === 'concluido')
                            <span class="px-2 py-1 bg-green-100 text-green-700 rounded-full text-xs font-semibold">Concluído</span>
                        @elseif($ag->status === 'enviado')
                            <span class="px-2 py-1 bg-blue-100 text-blue-700 rounded-full text-xs font-semibold">Enviado</span>
                        @else
                            <span class="px-2 py-1 bg-yellow-100 text-yellow-700 rounded-full text-xs font-semibold">Pendente</span>
                        @endif
                    </td>
                    <td class="px-3 py-3 text-center">
                        <div class="flex gap-1 justify-center">
                            {{-- Alterar Status --}}
                            <form action="{{ route('admin.agendamentos-avaliacao.status', $ag) }}" method="POST" class="inline">
                                @csrf @method('PATCH')
                                <select name="status" onchange="this.form.submit()" class="text-xs border rounded px-1 py-0.5">
                                    <option value="pendente" {{ $ag->status === 'pendente' ? 'selected' : '' }}>Pendente</option>
                                    <option value="enviado" {{ $ag->status === 'enviado' ? 'selected' : '' }}>Enviado</option>
                                    <option value="concluido" {{ $ag->status === 'concluido' ? 'selected' : '' }}>Concluído</option>
                                </select>
                            </form>

                            {{-- Refazer Template --}}
                            <form action="{{ route('admin.agendamentos-avaliacao.refazer-template', $ag) }}" method="POST" class="inline">
                                @csrf @method('PATCH')
                                <button type="submit" class="text-xs text-purple-600 hover:underline" title="Sortear novo template">🔄</button>
                            </form>
                        </div>
                    </td>
                </tr>
                @empty
                <tr>
                    <td colspan="6" class="px-4 py-8 text-center text-gray-400">Nenhum agendamento para esta semana.</td>
                </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection
