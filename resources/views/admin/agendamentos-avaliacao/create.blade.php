@extends('layouts.app')
@section('title', 'Novo Agendamento — Lead Certo')

@section('content')
<div class="max-w-2xl mx-auto">
    <h1 class="text-2xl font-bold text-gray-800 mb-6">📅 Novo Agendamento Individual</h1>

    @if($errors->any())
        <div class="mb-4 p-3 bg-red-100 text-red-800 rounded-lg text-sm">
            @foreach($errors->all() as $error) <p>{{ $error }}</p> @endforeach
        </div>
    @endif

    <form action="{{ route('admin.agendamentos-avaliacao.store') }}" method="POST" class="bg-white rounded-xl shadow p-6 space-y-4">
        @csrf

        <div>
            <label for="perfil_id" class="block text-sm font-medium text-gray-700 mb-1">Perfil GMB</label>
            <select name="perfil_id" id="perfil_id" required
                    class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-green-500 focus:border-green-500">
                <option value="">Selecione o perfil...</option>
                @foreach($perfis as $perfil)
                    <option value="{{ $perfil->id }}" {{ old('perfil_id') == $perfil->id ? 'selected' : '' }}>
                        {{ $perfil->nome }} ({{ $perfil->city }}/{{ $perfil->state }})
                    </option>
                @endforeach
            </select>
        </div>

        <div>
            <label for="data_agendada" class="block text-sm font-medium text-gray-700 mb-1">Data do Agendamento</label>
            <input type="date" name="data_agendada" id="data_agendada" value="{{ old('data_agendada', now()->toDateString()) }}" required
                   class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-green-500 focus:border-green-500">
        </div>

        <div class="p-3 bg-blue-50 rounded-lg text-sm text-blue-700">
            💡 <strong>Template e Avaliador são opcionais.</strong> Se não informados, o sistema escolhe automaticamente
            usando os algoritmos de sorteio anti-repetição e balanceamento de carga.
        </div>

        <div class="flex gap-3 pt-4">
            <button type="submit" class="px-6 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 text-sm font-semibold transition">
                Agendar
            </button>
            <a href="{{ route('admin.agendamentos-avaliacao.index') }}" class="px-6 py-2 bg-gray-200 text-gray-700 rounded-lg hover:bg-gray-300 text-sm transition">
                Cancelar
            </a>
        </div>
    </form>
</div>
@endsection
