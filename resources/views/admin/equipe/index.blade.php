@extends('layouts.app')
@section('title', 'Equipe Lead Certo')

@section('content')
<div class="max-w-5xl mx-auto">
    <div class="flex items-center justify-between mb-6">
        <div>
            <h1 class="text-2xl font-bold text-gray-800">🧑‍💼 Equipe Lead Certo</h1>
            <p class="text-sm text-gray-500 mt-1">Agentes de IA da própria Lead Certo — identidade, cargos e serviços executados.</p>
        </div>
        <a href="{{ route('admin.equipe.cargos') }}"
           class="px-4 py-2 bg-gray-100 text-gray-700 rounded-lg hover:bg-gray-200 text-sm font-semibold transition">
            Ver cargos da estrutura
        </a>
    </div>

    @if(session('sucesso'))
        <div class="mb-4 p-3 bg-green-100 text-green-800 rounded-lg text-sm">✅ {{ session('sucesso') }}</div>
    @endif

    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
        @forelse($agentes as $agente)
        <a href="{{ route('admin.equipe.show', $agente->id) }}"
           class="bg-white rounded-xl shadow p-4 flex items-center gap-4 hover:shadow-md transition">
            @if($agente->avatar_url)
                <img src="{{ $agente->avatar_url }}" class="w-14 h-14 rounded-full object-cover flex-shrink-0" alt="">
            @else
                <div class="w-14 h-14 rounded-full bg-gray-200 flex items-center justify-center text-xl font-bold text-gray-500 flex-shrink-0">
                    {{ mb_substr($agente->nome, 0, 1) }}
                </div>
            @endif
            <div class="min-w-0">
                <p class="font-semibold text-gray-800 truncate">{{ $agente->nome }}</p>
                <p class="text-xs text-gray-500 truncate">{{ $agente->email }}</p>
                <div class="flex flex-wrap gap-1 mt-1.5">
                    @forelse($agente->cargos as $cargo)
                        <span class="text-xs bg-blue-50 text-blue-700 px-2 py-0.5 rounded-full">{{ $cargo->nome }}</span>
                    @empty
                        <span class="text-xs text-gray-400 italic">sem cargo vinculado</span>
                    @endforelse
                </div>
                <p class="text-xs text-gray-400 mt-1.5">{{ $agente->servicos_executados_count }} serviço(s) registrado(s)</p>
            </div>
        </a>
        @empty
        <p class="text-gray-400 text-sm col-span-2 text-center py-8">Nenhum agente na equipe ainda.</p>
        @endforelse
    </div>
</div>
@endsection
