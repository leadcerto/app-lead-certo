@extends('layouts.app')
@section('title', $setor->nome)

@section('content')
<div class="max-w-2xl mx-auto">
    <a href="{{ route('equipe.setores') }}" class="text-sm text-gray-400 hover:text-gray-600">&larr; Setores</a>

    <div class="bg-white rounded-xl shadow p-6 mt-3">
        <h1 class="text-lg font-bold text-gray-800">{{ $setor->nome }}</h1>
        <p class="text-sm text-gray-500">{{ $setor->descricao }}</p>
    </div>

    @if(session('sucesso'))
        <div class="my-4 p-3 bg-green-100 text-green-800 rounded-lg text-sm">✅ {{ session('sucesso') }}</div>
    @endif

    <div class="bg-white rounded-xl shadow p-6 mt-4 space-y-4">
        @forelse($historico as $item)
            <div>
                <div class="bg-blue-50 text-blue-900 rounded-2xl rounded-br-sm px-4 py-2.5 text-sm ml-auto max-w-md">
                    {{ $item->mensagem }}
                </div>
                <div class="bg-gray-100 text-gray-700 rounded-2xl rounded-bl-sm px-4 py-2.5 text-sm max-w-md mt-2">
                    {{ $item->resposta }}
                </div>
                <p class="text-xs text-gray-400 mt-1">{{ $item->created_at->format('d/m/Y H:i') }}</p>
            </div>
        @empty
            <p class="text-sm text-gray-400 text-center py-4">Nenhuma conversa ainda — pode mandar sua primeira mensagem abaixo.</p>
        @endforelse
    </div>

    <form action="{{ route('equipe.conversar.store', $setor->id) }}" method="POST" class="bg-white rounded-xl shadow p-4 mt-4 flex gap-2">
        @csrf
        <textarea name="mensagem" required maxlength="2000" placeholder="Escreva sua mensagem..."
                  class="flex-1 text-sm border border-gray-300 rounded-lg px-3 py-2" rows="2"></textarea>
        <button class="self-end bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded-lg text-sm font-semibold">Enviar</button>
    </form>
</div>
@endsection
