@extends('layouts.app')
@section('title', 'Empresas — Lead Certo')

@section('content')
<div class="max-w-7xl mx-auto space-y-6">

    {{-- Header --}}
    <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4 border-b border-gray-200 pb-4">
        <div>
            <h1 class="text-2xl font-bold text-gray-900 font-heading">🏢 Gestão de Empresas & Franqueadas</h1>
            <p class="text-sm text-gray-500 mt-1">Gerencie os tenants da plataforma, cofre de credenciais de anúncios e canais conectados.</p>
        </div>
        <a href="{{ route('admin.empresas.create') }}"
           class="inline-flex items-center gap-2 px-4 py-2.5 bg-green-600 hover:bg-green-700 text-white text-sm font-semibold rounded-lg shadow-sm transition">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
            </svg>
            + Cadastrar Nova Empresa
        </a>
    </div>

    @if(session('sucesso'))
        <div class="p-4 bg-green-50 border border-green-200 text-green-800 rounded-xl text-sm flex items-center gap-3">
            <svg class="w-5 h-5 text-green-600 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20">
                <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/>
            </svg>
            {{ session('sucesso') }}
        </div>
    @endif

    {{-- Filtros de Busca --}}
    <div class="bg-white p-4 rounded-xl border border-gray-200 shadow-sm">
        <form method="GET" action="{{ route('admin.empresas.index') }}" class="flex flex-wrap items-center gap-3">
            <div class="flex-1 min-w-[240px]">
                <input type="text" name="busca" value="{{ request('busca') }}" placeholder="Buscar por nome, razão social, CNPJ, e-mail ou cidade..." class="w-full text-sm border-gray-300 rounded-lg">
            </div>

            <div class="w-48">
                <select name="nicho" class="w-full text-sm border-gray-300 rounded-lg">
                    <option value="">Todos os Nichos</option>
                    <option value="frete" {{ request('nicho') == 'frete' ? 'selected' : '' }}>Fretes & Mudanças</option>
                    <option value="pizza" {{ request('nicho') == 'pizza' ? 'selected' : '' }}>Pizzaria</option>
                    <option value="imoveis_caixa" {{ request('nicho') == 'imoveis_caixa' ? 'selected' : '' }}>Imóveis Caixa</option>
                    <option value="varejo" {{ request('nicho') == 'varejo' ? 'selected' : '' }}>Varejo</option>
                    <option value="educacao" {{ request('nicho') == 'educacao' ? 'selected' : '' }}>Educação</option>
                </select>
            </div>

            <button type="submit" class="px-4 py-2 bg-gray-800 hover:bg-gray-900 text-white text-sm font-medium rounded-lg transition">
                Filtrar
            </button>

            @if(request()->hasAny(['busca', 'nicho']))
                <a href="{{ route('admin.empresas.index') }}" class="text-sm text-gray-500 hover:text-gray-700 underline">
                    Limpar
                </a>
            @endif
        </form>
    </div>

    {{-- Tabela de Empresas --}}
    <div class="bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden">
        <table class="w-full text-sm">
            <thead class="bg-gray-50 text-gray-600 border-b border-gray-200">
                <tr>
                    <th class="px-4 py-3 text-left">Empresa / Razão Social</th>
                    <th class="px-4 py-3 text-left">Nicho / Localização</th>
                    <th class="px-4 py-3 text-center">Conexões Ativas</th>
                    <th class="px-4 py-3 text-center">Status</th>
                    <th class="px-4 py-3 text-right">Ações</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @forelse($empresas as $empresa)
                <tr class="hover:bg-gray-50 transition">
                    <td class="px-4 py-3">
                        <a href="{{ route('admin.empresas.show', $empresa) }}" class="font-bold text-gray-900 hover:text-green-600 transition block">
                            {{ $empresa->nome }}
                        </a>
                        <span class="text-xs text-gray-500 font-mono">{{ $empresa->email }}</span>
                    </td>

                    <td class="px-4 py-3">
                        <span class="text-xs font-semibold px-2 py-0.5 rounded bg-gray-100 text-gray-700 uppercase font-mono">
                            {{ $empresa->nicho }}
                        </span>
                        <p class="text-xs text-gray-500 mt-0.5">{{ $empresa->cidade ? $empresa->cidade . '/' . $empresa->estado : '—' }}</p>
                    </td>

                    <td class="px-4 py-3 text-center">
                        <div class="flex items-center justify-center gap-1.5">
                            {{-- Google Ads --}}
                            <span class="px-2 py-0.5 text-[10px] font-bold rounded-full {{ $empresa->temGoogleAds() ? 'bg-blue-100 text-blue-800' : 'bg-gray-100 text-gray-400' }}" title="Google Ads">
                                G-Ads
                            </span>

                            {{-- Meta Ads --}}
                            <span class="px-2 py-0.5 text-[10px] font-bold rounded-full {{ $empresa->temMetaAds() ? 'bg-purple-100 text-purple-800' : 'bg-gray-100 text-gray-400' }}" title="Meta Ads">
                                Meta
                            </span>

                            {{-- GMB --}}
                            <span class="px-2 py-0.5 text-[10px] font-bold rounded-full {{ $empresa->temGmb() ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-400' }}" title="Google Meu Negócio">
                                GMB
                            </span>

                            {{-- WhatsApp --}}
                            <span class="px-2 py-0.5 text-[10px] font-bold rounded-full {{ $empresa->temWhatsappConectado() ? 'bg-emerald-100 text-emerald-800' : 'bg-gray-100 text-gray-400' }}" title="WhatsApp">
                                WA
                            </span>
                        </div>
                    </td>

                    <td class="px-4 py-3 text-center">
                        @if($empresa->status === 'ativo')
                            <span class="px-2.5 py-0.5 bg-green-100 text-green-800 rounded-full text-xs font-semibold">Ativo</span>
                        @else
                            <span class="px-2.5 py-0.5 bg-gray-100 text-gray-600 rounded-full text-xs font-semibold">{{ ucfirst($empresa->status) }}</span>
                        @endif
                    </td>

                    <td class="px-4 py-3 text-right">
                        <div class="flex items-center justify-end gap-2">
                            <a href="{{ route('admin.empresas.show', $empresa) }}" class="px-3 py-1 bg-gray-100 hover:bg-gray-200 text-gray-700 text-xs font-medium rounded-lg transition">
                                Detalhes
                            </a>
                            <a href="{{ route('admin.empresas.edit', $empresa) }}" class="px-3 py-1 bg-green-50 hover:bg-green-100 text-green-700 text-xs font-semibold rounded-lg transition">
                                ⚙️ Cofre
                            </a>
                        </div>
                    </td>
                </tr>
                @empty
                <tr>
                    <td colspan="5" class="px-4 py-12 text-center text-gray-400">
                        Nenhuma empresa cadastrada com os filtros atuais.
                    </td>
                </tr>
                @endforelse
            </tbody>
        </table>

        @if($empresas->hasPages())
            <div class="p-4 border-t border-gray-100">
                {{ $empresas->links() }}
            </div>
        @endif
    </div>

</div>
@endsection
