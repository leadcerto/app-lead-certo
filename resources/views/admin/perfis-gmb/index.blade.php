@extends('layouts.app')
@section('title', 'Perfis GMB — Lead Certo')

@section('content')
<div class="max-w-6xl mx-auto">

    {{-- Header --}}
    <div class="flex items-center justify-between mb-6">
        <div>
            <h1 class="text-2xl font-bold text-gray-800">📍 Perfis Google Meu Negócio</h1>
            <p class="text-sm text-gray-500 mt-1">Gerencie as fichas/empresas que recebem avaliações.</p>
        </div>
        <a href="{{ route('admin.perfis-gmb.create') }}"
           class="px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 text-sm font-semibold transition">
            + Novo Perfil
        </a>
    </div>

    {{-- Alertas --}}
    @if(session('sucesso'))
        <div class="mb-4 p-3 bg-green-100 text-green-800 rounded-lg text-sm">✅ {{ session('sucesso') }}</div>
    @endif

    {{-- Tabela --}}
    <div class="bg-white rounded-xl shadow overflow-hidden">
        <table class="w-full text-sm">
            <thead class="bg-gray-50 text-gray-600">
                <tr>
                    <th class="px-4 py-3 text-left">Nome</th>
                    <th class="px-4 py-3 text-left">Cidade</th>
                    <th class="px-4 py-3 text-left">UF</th>
                    <th class="px-4 py-3 text-left">Link GMB</th>
                    <th class="px-4 py-3 text-center">Status</th>
                    <th class="px-4 py-3 text-center">Ações</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @forelse($perfis as $perfil)
                <tr class="hover:bg-gray-50">
                    <td class="px-4 py-3 font-medium text-gray-800">{{ $perfil->nome }}</td>
                    <td class="px-4 py-3 text-gray-600">{{ $perfil->city }}</td>
                    <td class="px-4 py-3 text-gray-600">{{ $perfil->state }}</td>
                    <td class="px-4 py-3">
                        <a href="{{ $perfil->link_gmb }}" target="_blank" class="text-blue-600 hover:underline text-xs">
                            Abrir no Google ↗
                        </a>
                    </td>
                    <td class="px-4 py-3 text-center">
                        @if($perfil->ativo)
                            <span class="px-2 py-1 bg-green-100 text-green-700 rounded-full text-xs font-semibold">Ativo</span>
                        @else
                            <span class="px-2 py-1 bg-red-100 text-red-700 rounded-full text-xs font-semibold">Inativo</span>
                        @endif
                    </td>
                    <td class="px-4 py-3 text-center space-x-2">
                        <a href="{{ route('admin.perfis-gmb.edit', $perfil) }}"
                           class="text-blue-600 hover:underline text-xs">Editar</a>
                        <form action="{{ route('admin.perfis-gmb.destroy', $perfil) }}" method="POST" class="inline">
                            @csrf @method('DELETE')
                            <button type="submit" class="text-red-500 hover:underline text-xs"
                                    onclick="return confirm('Desativar este perfil?')">Desativar</button>
                        </form>
                    </td>
                </tr>
                @empty
                <tr>
                    <td colspan="6" class="px-4 py-8 text-center text-gray-400">Nenhum perfil cadastrado.</td>
                </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">{{ $perfis->links() }}</div>
</div>
@endsection
