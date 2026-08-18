@extends('layouts.app')
@section('title', 'Contatos — ' . $perfil->nome)

@section('content')
<div class="max-w-3xl mx-auto">
    <div class="mb-6">
        <a href="{{ route('admin.perfis-gmb.index') }}" class="text-sm text-gray-500 hover:text-gray-700">← Voltar aos Perfis</a>
        <h1 class="text-2xl font-bold text-gray-800 mt-1">📞 Contatos — {{ $perfil->nome }}</h1>
        <p class="text-sm text-gray-500 mt-1">
            Telefones de clientes reais que já usaram o serviço deste perfil — o avaliador escolhe
            da lista quem ainda não foi contatado.
        </p>
    </div>

    @if(session('sucesso'))
        <div class="mb-4 p-3 bg-green-100 text-green-800 rounded-lg text-sm">✅ {{ session('sucesso') }}</div>
    @endif

    {{-- Adicionar em lote --}}
    <form action="{{ route('admin.perfis-gmb.contatos.store', $perfil) }}" method="POST"
          class="bg-white rounded-xl shadow p-6 mb-6 space-y-3">
        @csrf
        <label for="lista" class="block text-sm font-medium text-gray-700">Adicionar contatos</label>
        <textarea name="lista" id="lista" rows="6" required
                  class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm font-mono focus:ring-2 focus:ring-green-500 focus:border-green-500"
                  placeholder="Um por linha. Formato: Nome, Telefone (ou só o telefone)&#10;Maria Silva, 21988887777&#10;21999996666"></textarea>
        <button type="submit" class="px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 text-sm font-semibold transition">
            Adicionar
        </button>
    </form>

    {{-- Lista --}}
    <div class="bg-white rounded-xl shadow overflow-hidden">
        <table class="w-full text-sm">
            <thead class="bg-gray-50 text-gray-600">
                <tr>
                    <th class="px-4 py-3 text-left">Nome</th>
                    <th class="px-4 py-3 text-left">Telefone</th>
                    <th class="px-4 py-3 text-center">Status</th>
                    <th class="px-4 py-3 text-center">Ações</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @forelse($contatos as $contato)
                <tr class="hover:bg-gray-50">
                    <td class="px-4 py-3 text-gray-800">{{ $contato->nome ?? '—' }}</td>
                    <td class="px-4 py-3 text-gray-600 font-mono">{{ $contato->telefone }}</td>
                    <td class="px-4 py-3 text-center">
                        @if($contato->contatado_em)
                            <span class="px-2 py-1 bg-gray-100 text-gray-500 rounded-full text-xs font-semibold">Ligado em {{ $contato->contatado_em->format('d/m') }}</span>
                        @else
                            <span class="px-2 py-1 bg-yellow-100 text-yellow-700 rounded-full text-xs font-semibold">Pendente</span>
                        @endif
                    </td>
                    <td class="px-4 py-3 text-center">
                        <form action="{{ route('admin.perfis-gmb.contatos.destroy', $contato) }}" method="POST" class="inline">
                            @csrf @method('DELETE')
                            <button type="submit" class="text-red-500 hover:underline text-xs"
                                    onclick="return confirm('Remover este contato?')">Remover</button>
                        </form>
                    </td>
                </tr>
                @empty
                <tr>
                    <td colspan="4" class="px-4 py-8 text-center text-gray-400">Nenhum contato cadastrado ainda.</td>
                </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection
