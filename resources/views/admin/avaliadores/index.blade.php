@extends('layouts.app')
@section('title', 'Avaliadores — Lead Certo')

@section('content')
<div class="max-w-5xl mx-auto">
    <div class="flex items-center justify-between mb-6">
        <div>
            <h1 class="text-2xl font-bold text-gray-800">⭐ Avaliadores</h1>
            <p class="text-sm text-gray-500 mt-1">Quem liga pros clientes e oferece o texto sugerido.</p>
        </div>
        <a href="{{ route('admin.avaliadores.create') }}"
           class="px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 text-sm font-semibold transition">
            + Novo Avaliador
        </a>
    </div>

    @if(session('sucesso'))
        <div class="mb-4 p-3 bg-green-100 text-green-800 rounded-lg text-sm">✅ {{ session('sucesso') }}</div>
    @endif

    <div class="bg-white rounded-xl shadow overflow-hidden">
        <table class="w-full text-sm">
            <thead class="bg-gray-50 text-gray-600">
                <tr>
                    <th class="px-4 py-3 text-left">Nome</th>
                    <th class="px-4 py-3 text-left">E-mail / WhatsApp</th>
                    <th class="px-4 py-3 text-left">Cidade/UF</th>
                    <th class="px-4 py-3 text-center">Agendamentos</th>
                    <th class="px-4 py-3 text-center">Status</th>
                    <th class="px-4 py-3 text-center">Ações</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @forelse($avaliadores as $avaliador)
                <tr class="hover:bg-gray-50">
                    <td class="px-4 py-3 font-medium text-gray-800">{{ $avaliador->nome }}</td>
                    <td class="px-4 py-3 text-gray-600">
                        {{ $avaliador->email }}
                        @if($avaliador->whatsapp)
                            <br><span class="text-xs text-green-600">{{ $avaliador->whatsapp }}</span>
                        @endif
                    </td>
                    <td class="px-4 py-3 text-gray-600">{{ $avaliador->city }}/{{ $avaliador->state }}</td>
                    <td class="px-4 py-3 text-center text-gray-600">{{ $avaliador->agendamentos_avaliacao_count }}</td>
                    <td class="px-4 py-3 text-center">
                        @if($avaliador->ativo)
                            <span class="px-2 py-1 bg-green-100 text-green-700 rounded-full text-xs font-semibold">Ativo</span>
                        @else
                            <span class="px-2 py-1 bg-red-100 text-red-700 rounded-full text-xs font-semibold">Inativo</span>
                        @endif
                    </td>
                    <td class="px-4 py-3 text-center space-x-2">
                        <a href="{{ route('admin.avaliadores.edit', $avaliador) }}" class="text-blue-600 hover:underline text-xs">Editar</a>
                        @if($avaliador->ativo)
                        <form action="{{ route('admin.avaliadores.destroy', $avaliador) }}" method="POST" class="inline">
                            @csrf @method('DELETE')
                            <button type="submit" class="text-red-500 hover:underline text-xs"
                                    onclick="return confirm('Desativar este avaliador?')">Desativar</button>
                        </form>
                        @endif
                    </td>
                </tr>
                @empty
                <tr>
                    <td colspan="6" class="px-4 py-8 text-center text-gray-400">Nenhum avaliador cadastrado.</td>
                </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">{{ $avaliadores->links() }}</div>
</div>
@endsection
