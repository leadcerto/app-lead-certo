@extends('layouts.app')
@section('title', 'Empresas — Lead Certo')

@section('content')
<div class="max-w-5xl mx-auto">
    <div class="flex items-center justify-between mb-6">
        <div>
            <h1 class="text-2xl font-bold text-gray-800">🏢 Empresas</h1>
            <p class="text-sm text-gray-500 mt-1">Franqueados cadastrados na plataforma.</p>
        </div>
        <a href="{{ route('admin.empresas.create') }}"
           class="px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 text-sm font-semibold transition">
            + Nova Empresa
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
                    <th class="px-4 py-3 text-left">E-mail</th>
                    <th class="px-4 py-3 text-left">Nicho</th>
                    <th class="px-4 py-3 text-center">Status</th>
                    <th class="px-4 py-3 text-left">Criada em</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @forelse($empresas as $empresa)
                <tr class="hover:bg-gray-50">
                    <td class="px-4 py-3 font-medium text-gray-800">{{ $empresa->nome }}</td>
                    <td class="px-4 py-3 text-gray-600">{{ $empresa->email }}</td>
                    <td class="px-4 py-3 text-gray-600">{{ $empresa->nicho ?? '—' }}</td>
                    <td class="px-4 py-3 text-center">
                        @if($empresa->status === 'ativo')
                            <span class="px-2 py-1 bg-green-100 text-green-700 rounded-full text-xs font-semibold">Ativo</span>
                        @else
                            <span class="px-2 py-1 bg-gray-100 text-gray-600 rounded-full text-xs font-semibold">{{ $empresa->status ?? '—' }}</span>
                        @endif
                    </td>
                    <td class="px-4 py-3 text-gray-500">{{ $empresa->created_at?->format('d/m/Y') }}</td>
                </tr>
                @empty
                <tr>
                    <td colspan="5" class="px-4 py-8 text-center text-gray-400">Nenhuma empresa cadastrada.</td>
                </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">{{ $empresas->links() }}</div>
</div>
@endsection
