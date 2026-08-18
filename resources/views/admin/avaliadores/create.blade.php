@extends('layouts.app')
@section('title', 'Novo Avaliador — Lead Certo')

@section('content')
<div class="max-w-xl mx-auto">
    <div class="mb-6">
        <h1 class="text-2xl font-bold text-gray-800">⭐ Novo Avaliador</h1>
        <p class="text-sm text-gray-500 mt-1">
            Cidade e estado são usados pra atribuição automática — o avaliador só recebe
            agendamentos de perfis da mesma região.
        </p>
    </div>

    @if($errors->any())
        <div class="mb-4 p-3 bg-red-100 text-red-800 rounded-lg text-sm">
            @foreach($errors->all() as $error) <p>{{ $error }}</p> @endforeach
        </div>
    @endif

    <form action="{{ route('admin.avaliadores.store') }}" method="POST" class="bg-white rounded-xl shadow p-6 space-y-4">
        @csrf

        <div>
            <label for="nome" class="block text-sm font-medium text-gray-700 mb-1">Nome</label>
            <input type="text" name="nome" id="nome" required maxlength="200" value="{{ old('nome') }}"
                   class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-green-500 focus:border-green-500">
        </div>

        <div>
            <label for="email" class="block text-sm font-medium text-gray-700 mb-1">E-mail de Login</label>
            <input type="email" name="email" id="email" required maxlength="200" value="{{ old('email') }}"
                   class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-green-500 focus:border-green-500">
        </div>

        <div class="grid grid-cols-2 gap-4">
            <div>
                <label for="city" class="block text-sm font-medium text-gray-700 mb-1">Cidade</label>
                <input type="text" name="city" id="city" required maxlength="100" value="{{ old('city') }}"
                       class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-green-500 focus:border-green-500">
            </div>
            <div>
                <label for="state" class="block text-sm font-medium text-gray-700 mb-1">UF</label>
                <input type="text" name="state" id="state" required maxlength="2" value="{{ old('state') }}"
                       class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm uppercase focus:ring-2 focus:ring-green-500 focus:border-green-500">
            </div>
        </div>

        <div>
            <label for="senha" class="block text-sm font-medium text-gray-700 mb-1">Senha Inicial</label>
            <div class="flex gap-2">
                <input type="text" name="senha" id="senha" required minlength="8" maxlength="100"
                       value="{{ old('senha', $senhaSugerida) }}"
                       class="flex-1 border border-gray-300 rounded-lg px-3 py-2 text-sm font-mono focus:ring-2 focus:ring-green-500 focus:border-green-500">
                <button type="button" onclick="document.getElementById('senha').value = crypto.randomUUID().replace(/-/g, '').slice(0, 12)"
                        class="px-3 py-2 bg-gray-200 text-gray-700 rounded-lg hover:bg-gray-300 text-xs font-semibold transition whitespace-nowrap">
                    🎲 Gerar outra
                </button>
            </div>
        </div>

        <div class="flex gap-3 pt-2">
            <button type="submit" class="px-6 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 text-sm font-semibold transition">
                Cadastrar
            </button>
            <a href="{{ route('admin.avaliadores.index') }}" class="px-6 py-2 bg-gray-200 text-gray-700 rounded-lg hover:bg-gray-300 text-sm transition">
                Cancelar
            </a>
        </div>
    </form>
</div>
@endsection
