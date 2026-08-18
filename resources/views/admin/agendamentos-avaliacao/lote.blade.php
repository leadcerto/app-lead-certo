@extends('layouts.app')
@section('title', 'Gerador em Lote — Lead Certo')

@section('content')
<div class="max-w-7xl mx-auto">

    <div class="flex items-center justify-between mb-6">
        <div>
            <h1 class="text-2xl font-bold text-gray-800">📊 Gerador em Lote (Matriz)</h1>
            <p class="text-sm text-gray-500 mt-1">Defina quantas avaliações por perfil/dia da semana.</p>
        </div>
        <a href="{{ route('admin.agendamentos-avaliacao.index') }}" class="text-sm text-gray-500 hover:text-gray-700">← Voltar</a>
    </div>

    @if($errors->any())
        <div class="mb-4 p-3 bg-red-100 text-red-800 rounded-lg text-sm">
            @foreach($errors->all() as $error) <p>{{ $error }}</p> @endforeach
        </div>
    @endif

    <form action="{{ route('admin.agendamentos-avaliacao.lote.store') }}" method="POST">
        @csrf
        <input type="hidden" name="semana_referencia" value="{{ $semana->toDateString() }}">

        <div class="bg-white rounded-xl shadow overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-gray-50 text-gray-600">
                    <tr>
                        <th class="px-4 py-3 text-left">Perfil</th>
                        <th class="px-3 py-3 text-center">Segunda</th>
                        <th class="px-3 py-3 text-center">Terça</th>
                        <th class="px-3 py-3 text-center">Quarta</th>
                        <th class="px-3 py-3 text-center">Quinta</th>
                        <th class="px-3 py-3 text-center">Sexta</th>
                        <th class="px-3 py-3 text-center">Sábado</th>
                        <th class="px-3 py-3 text-center">Domingo</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @foreach($perfis as $perfil)
                    <tr class="hover:bg-gray-50">
                        <td class="px-4 py-3">
                            <div class="font-medium text-gray-800">{{ $perfil->nome }}</div>
                            <div class="text-xs text-gray-400">{{ $perfil->city }}/{{ $perfil->state }}</div>
                        </td>
                        @foreach(['segunda', 'terca', 'quarta', 'quinta', 'sexta', 'sabado', 'domingo'] as $dia)
                        <td class="px-1 py-3 text-center">
                            <input type="number" name="matriz[{{ $perfil->id }}][{{ $dia }}]"
                                   value="0" min="0" max="5"
                                   class="w-12 text-center border border-gray-300 rounded px-1 py-1 text-sm focus:ring-2 focus:ring-green-500">
                        </td>
                        @endforeach
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <div class="flex gap-3 mt-4">
            <button type="submit" class="px-6 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 text-sm font-semibold transition">
                Gerar Agendamentos em Lote
            </button>
        </div>
    </form>
</div>
@endsection
