@extends('layouts.app')
@section('title', 'Campanha — Lead Certo')

@section('content')
<div class="max-w-3xl mx-auto">
    <div class="flex items-center justify-between mb-6">
        <div>
            <h1 class="text-2xl font-bold text-gray-800">🚀 Enviar Agora (Campanha)</h1>
            <p class="text-sm text-gray-500 mt-1">Dispare avaliações massivas para vários perfis de uma vez.</p>
        </div>
        <a href="{{ route('admin.agendamentos-avaliacao.index') }}" class="text-sm text-gray-500 hover:text-gray-700">← Voltar</a>
    </div>

    @if($errors->any())
        <div class="mb-4 p-3 bg-red-100 text-red-800 rounded-lg text-sm">
            @foreach($errors->all() as $error) <p>{{ $error }}</p> @endforeach
        </div>
    @endif

    <form action="{{ route('admin.agendamentos-avaliacao.campanha.store') }}" method="POST" class="bg-white rounded-xl shadow p-6 space-y-4">
        @csrf

        <div>
            <label for="data_agendada" class="block text-sm font-medium text-gray-700 mb-1">Data para Disparo</label>
            <input type="date" name="data_agendada" id="data_agendada" value="{{ now()->toDateString() }}" required
                   class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-green-500 focus:border-green-500">
        </div>

        <div>
            <label class="block text-sm font-medium text-gray-700 mb-2">Selecione os Perfis</label>
            <div class="space-y-2 max-h-80 overflow-y-auto border border-gray-200 rounded-lg p-3">
                @foreach($perfis as $perfil)
                <label class="flex items-center gap-3 p-2 rounded hover:bg-gray-50 cursor-pointer">
                    <input type="checkbox" name="perfil_ids[]" value="{{ $perfil->id }}"
                           class="rounded border-gray-300 text-green-600 focus:ring-green-500">
                    <div>
                        <span class="text-sm font-medium text-gray-800">{{ $perfil->nome }}</span>
                        <span class="text-xs text-gray-400 ml-2">{{ $perfil->city }}/{{ $perfil->state }}</span>
                    </div>
                </label>
                @endforeach
            </div>
        </div>

        <div class="flex gap-3 pt-4">
            <button type="submit" class="px-6 py-2 bg-purple-600 text-white rounded-lg hover:bg-purple-700 text-sm font-semibold transition"
                    onclick="return confirm('Disparar avaliações para todos os perfis selecionados?')">
                🚀 Disparar Campanha
            </button>
            <a href="{{ route('admin.agendamentos-avaliacao.index') }}" class="px-6 py-2 bg-gray-200 text-gray-700 rounded-lg hover:bg-gray-300 text-sm transition">
                Cancelar
            </a>
        </div>
    </form>
</div>
@endsection
