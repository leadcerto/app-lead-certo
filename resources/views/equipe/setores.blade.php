@extends('layouts.app')
@section('title', 'Suporte')

@section('content')
<div class="max-w-2xl mx-auto">
    <div class="mb-6">
        <h1 class="text-2xl font-bold text-gray-800">Suporte</h1>
        <p class="text-sm text-gray-500 mt-1">Escolha o setor certo pra sua mensagem — a gente lê e leva pra frente internamente.</p>
    </div>

    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
        @forelse($setores as $setor)
            <a href="{{ route('equipe.conversar', $setor->id) }}"
               class="bg-white rounded-xl shadow p-5 hover:shadow-md transition">
                <p class="font-semibold text-gray-800">{{ $setor->nome }}</p>
                <p class="text-sm text-gray-500 mt-1">{{ $setor->descricao }}</p>
            </a>
        @empty
            <p class="text-sm text-gray-400 text-center py-8 col-span-2">Nenhum setor disponível pra contato ainda.</p>
        @endforelse
    </div>
</div>
@endsection
