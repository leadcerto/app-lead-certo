@extends('layouts.app')
@section('title', 'Editar Perfil GMB — Lead Certo')

@section('content')
<div class="max-w-2xl mx-auto">
    <h1 class="text-2xl font-bold text-gray-800 mb-6">✏️ Editar Perfil GMB</h1>

    @if($errors->any())
        <div class="mb-4 p-3 bg-red-100 text-red-800 rounded-lg text-sm">
            @foreach($errors->all() as $error) <p>{{ $error }}</p> @endforeach
        </div>
    @endif

    <form action="{{ route('admin.perfis-gmb.update', $perfil) }}" method="POST" class="bg-white rounded-xl shadow p-6 space-y-4">
        @csrf @method('PUT')

        <div>
            <label for="nome" class="block text-sm font-medium text-gray-700 mb-1">Nome da Empresa/Perfil</label>
            <input type="text" name="nome" id="nome" value="{{ old('nome', $perfil->nome) }}" required
                   class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-green-500 focus:border-green-500">
        </div>

        <div class="grid grid-cols-2 gap-4">
            <div>
                <label for="city" class="block text-sm font-medium text-gray-700 mb-1">Cidade</label>
                <input type="text" name="city" id="city" value="{{ old('city', $perfil->city) }}" required
                       class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-green-500 focus:border-green-500">
            </div>
            <div>
                <label for="state" class="block text-sm font-medium text-gray-700 mb-1">UF</label>
                <input type="text" name="state" id="state" value="{{ old('state', $perfil->state) }}" required maxlength="2"
                       class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-green-500 focus:border-green-500 uppercase">
            </div>
        </div>

        <div>
            <label for="link_gmb" class="block text-sm font-medium text-gray-700 mb-1">Link do Google Meu Negócio</label>
            <input type="url" name="link_gmb" id="link_gmb" value="{{ old('link_gmb', $perfil->link_gmb) }}" required
                   class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-green-500 focus:border-green-500">
        </div>

        <div class="flex items-center gap-2">
            <input type="checkbox" name="ativo" id="ativo" value="1" {{ $perfil->ativo ? 'checked' : '' }}
                   class="rounded border-gray-300 text-green-600 focus:ring-green-500">
            <label for="ativo" class="text-sm text-gray-700">Perfil ativo</label>
        </div>

        <div class="flex gap-3 pt-4">
            <button type="submit" class="px-6 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 text-sm font-semibold transition">
                Atualizar Perfil
            </button>
            <a href="{{ route('admin.perfis-gmb.index') }}" class="px-6 py-2 bg-gray-200 text-gray-700 rounded-lg hover:bg-gray-300 text-sm transition">
                Cancelar
            </a>
        </div>
    </form>
</div>
@endsection
