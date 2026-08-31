@extends('layouts.app')
@section('title', 'Empresa — ' . $empresa->nome)

@section('content')
<div class="max-w-6xl mx-auto space-y-6">

    {{-- Header --}}
    <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4 border-b border-gray-200 pb-4">
        <div>
            <div class="flex items-center gap-2 text-xs text-gray-500 mb-1">
                <a href="{{ route('admin.empresas.index') }}" class="hover:underline">Empresas</a>
                <span>/</span>
                <span class="text-gray-700">{{ $empresa->nome }}</span>
            </div>
            <div class="flex items-center gap-3">
                <h1 class="text-2xl font-bold text-gray-900 font-heading">{{ $empresa->nome }}</h1>
                @if($empresa->status === 'ativo')
                    <span class="px-2.5 py-0.5 bg-green-100 text-green-800 rounded-full text-xs font-bold">Ativo</span>
                @else
                    <span class="px-2.5 py-0.5 bg-gray-100 text-gray-700 rounded-full text-xs font-bold">{{ ucfirst($empresa->status) }}</span>
                @endif
                <span class="text-xs bg-gray-100 text-gray-600 px-2 py-0.5 rounded font-mono uppercase">
                    {{ $empresa->nicho }}
                </span>
            </div>
        </div>
        <div class="flex items-center gap-2">
            <a href="{{ route('admin.empresas.edit', $empresa) }}" class="px-4 py-2 bg-green-600 hover:bg-green-700 text-white text-sm font-semibold rounded-lg shadow-sm transition">
                ⚙️ Editar Configurações & Cofre
            </a>
            <a href="{{ route('admin.empresas.index') }}" class="px-4 py-2 bg-gray-100 hover:bg-gray-200 text-gray-700 text-sm font-medium rounded-lg transition">
                Lista de Empresas
            </a>
        </div>
    </div>

    @if(session('sucesso'))
        <div class="p-4 bg-green-50 border border-green-200 text-green-800 rounded-xl text-sm flex items-center gap-3">
            <svg class="w-5 h-5 text-green-600 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20">
                <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/>
            </svg>
            {{ session('sucesso') }}
        </div>
    @endif

    {{-- Cards de Status dos Canais de Automação --}}
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
        {{-- Google Ads --}}
        <div class="bg-white p-4 rounded-xl border border-gray-200 shadow-sm space-y-2">
            <div class="flex items-center justify-between">
                <span class="text-xs font-bold text-gray-500 uppercase">Google Ads</span>
                @if($empresa->temGoogleAds())
                    <span class="px-2 py-0.5 bg-green-100 text-green-800 rounded-full text-xs font-bold">Conectado</span>
                @else
                    <span class="px-2 py-0.5 bg-gray-100 text-gray-500 rounded-full text-xs font-medium">Pendente</span>
                @endif
            </div>
            <p class="text-xs text-gray-600 font-mono">CID: {{ $empresa->google_ads_customer_id ?: 'Não informado' }}</p>
        </div>

        {{-- Meta Ads --}}
        <div class="bg-white p-4 rounded-xl border border-gray-200 shadow-sm space-y-2">
            <div class="flex items-center justify-between">
                <span class="text-xs font-bold text-gray-500 uppercase">Meta Ads</span>
                @if($empresa->temMetaAds())
                    <span class="px-2 py-0.5 bg-green-100 text-green-800 rounded-full text-xs font-bold">Conectado</span>
                @else
                    <span class="px-2 py-0.5 bg-gray-100 text-gray-500 rounded-full text-xs font-medium">Pendente</span>
                @endif
            </div>
            <p class="text-xs text-gray-600 font-mono">Conta: {{ $empresa->meta_ad_account_id ?: 'Não informada' }}</p>
        </div>

        {{-- Google Meu Negócio --}}
        <div class="bg-white p-4 rounded-xl border border-gray-200 shadow-sm space-y-2">
            <div class="flex items-center justify-between">
                <span class="text-xs font-bold text-gray-500 uppercase">Google Meu Negócio</span>
                @if($empresa->temGmb())
                    <span class="px-2 py-0.5 bg-green-100 text-green-800 rounded-full text-xs font-bold">Ativo</span>
                @else
                    <span class="px-2 py-0.5 bg-gray-100 text-gray-500 rounded-full text-xs font-medium">Pendente</span>
                @endif
            </div>
            <p class="text-xs text-gray-600 truncate">{{ $empresa->gmb_url ? 'Link configurado' : ($empresa->perfisGmb()->count() . ' perfil(s)') }}</p>
        </div>

        {{-- WhatsApp --}}
        <div class="bg-white p-4 rounded-xl border border-gray-200 shadow-sm space-y-2">
            <div class="flex items-center justify-between">
                <span class="text-xs font-bold text-gray-500 uppercase">WhatsApp</span>
                @if($empresa->temWhatsappConectado())
                    <span class="px-2 py-0.5 bg-green-100 text-green-800 rounded-full text-xs font-bold">Conectado</span>
                @else
                    <span class="px-2 py-0.5 bg-gray-100 text-gray-500 rounded-full text-xs font-medium">Desconectado</span>
                @endif
            </div>
            <p class="text-xs text-gray-600 font-mono">{{ $empresa->whatsapp_phone ?: ($empresa->telefone ?: 'Sem número') }}</p>
        </div>
    </div>

    {{-- Grid de Informações --}}
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">

        {{-- Coluna 1 e 2: Dados e Contexto --}}
        <div class="lg:col-span-2 space-y-6">
            {{-- Dados Cadastrais --}}
            <div class="bg-white rounded-xl border border-gray-200 shadow-sm p-6 space-y-4">
                <h3 class="text-sm font-bold text-gray-800 uppercase tracking-wider border-b pb-2">Dados Institucionais</h3>
                
                <div class="grid grid-cols-2 gap-4 text-sm">
                    <div>
                        <span class="text-xs text-gray-400 block">Razão Social</span>
                        <span class="font-medium text-gray-800">{{ $empresa->razao_social ?: '—' }}</span>
                    </div>
                    <div>
                        <span class="text-xs text-gray-400 block">CNPJ / CPF</span>
                        <span class="font-mono text-gray-800">{{ $empresa->cnpj ?: '—' }}</span>
                    </div>
                    <div>
                        <span class="text-xs text-gray-400 block">E-mail</span>
                        <span class="text-gray-800">{{ $empresa->email }}</span>
                    </div>
                    <div>
                        <span class="text-xs text-gray-400 block">Telefone</span>
                        <span class="font-mono text-gray-800">{{ $empresa->telefone ?: '—' }}</span>
                    </div>
                    <div>
                        <span class="text-xs text-gray-400 block">Localização</span>
                        <span class="text-gray-800">{{ $empresa->cidade ? $empresa->cidade . '/' . $empresa->estado : '—' }}</span>
                    </div>
                    <div>
                        <span class="text-xs text-gray-400 block">Site</span>
                        @if($empresa->site_url)
                            <a href="{{ $empresa->site_url }}" target="_blank" class="text-blue-600 hover:underline truncate block">{{ $empresa->site_url }}</a>
                        @else
                            <span class="text-gray-400">—</span>
                        @endif
                    </div>
                </div>
            </div>

            {{-- Contexto para IA --}}
            <div class="bg-white rounded-xl border border-gray-200 shadow-sm p-6 space-y-4">
                <h3 class="text-sm font-bold text-gray-800 uppercase tracking-wider border-b pb-2">Contexto Estratégico para Robôs e Anúncios</h3>
                
                <div class="space-y-3 text-sm">
                    <div>
                        <span class="text-xs font-semibold text-gray-500 block mb-1">O que a empresa faz:</span>
                        <p class="text-gray-700 bg-gray-50 p-3 rounded-lg leading-relaxed">{{ $empresa->descricao_negocio ?: 'Nenhuma descrição cadastrada ainda.' }}</p>
                    </div>

                    <div>
                        <span class="text-xs font-semibold text-gray-500 block mb-1">Público-Alvo:</span>
                        <p class="text-gray-700 bg-gray-50 p-3 rounded-lg leading-relaxed">{{ $empresa->publico_alvo ?: 'Não especificado.' }}</p>
                    </div>

                    <div>
                        <span class="text-xs font-semibold text-gray-500 block mb-1">Diferenciais Competitivos:</span>
                        <p class="text-gray-700 bg-gray-50 p-3 rounded-lg leading-relaxed">{{ $empresa->diferenciais ?: 'Não especificado.' }}</p>
                    </div>
                </div>
            </div>
        </div>

        {{-- Coluna 3: Redes Sociais & Usuários --}}
        <div class="space-y-6">
            {{-- Presença Digital --}}
            <div class="bg-white rounded-xl border border-gray-200 shadow-sm p-6 space-y-3">
                <h3 class="text-sm font-bold text-gray-800 uppercase tracking-wider border-b pb-2">Redes Sociais</h3>
                <ul class="space-y-2 text-xs">
                    <li>
                        <span class="text-gray-400 block">Google Maps:</span>
                        @if($empresa->gmb_url)
                            <a href="{{ $empresa->gmb_url }}" target="_blank" class="text-blue-600 hover:underline truncate block">Ver no Maps ↗</a>
                        @else
                            <span class="text-gray-400">—</span>
                        @endif
                    </li>
                    <li>
                        <span class="text-gray-400 block">Instagram:</span>
                        @if($empresa->instagram_url)
                            <a href="{{ $empresa->instagram_url }}" target="_blank" class="text-blue-600 hover:underline truncate block">{{ $empresa->instagram_url }}</a>
                        @else
                            <span class="text-gray-400">—</span>
                        @endif
                    </li>
                    <li>
                        <span class="text-gray-400 block">Facebook:</span>
                        @if($empresa->facebook_url)
                            <a href="{{ $empresa->facebook_url }}" target="_blank" class="text-blue-600 hover:underline truncate block">{{ $empresa->facebook_url }}</a>
                        @else
                            <span class="text-gray-400">—</span>
                        @endif
                    </li>
                </ul>
            </div>

            {{-- Usuários Vinculados --}}
            <div class="bg-white rounded-xl border border-gray-200 shadow-sm p-6 space-y-3">
                <h3 class="text-sm font-bold text-gray-800 uppercase tracking-wider border-b pb-2">Equipe / Usuários</h3>
                <div class="divide-y divide-gray-100">
                    @forelse($empresa->users as $u)
                        <div class="py-2 flex items-center justify-between text-xs">
                            <div>
                                <p class="font-bold text-gray-900">{{ $u->nome }}</p>
                                <p class="text-gray-400">{{ $u->email }}</p>
                            </div>
                            <span class="px-2 py-0.5 bg-gray-100 text-gray-700 rounded font-medium">{{ $u->perfilLabel() }}</span>
                        </div>
                    @empty
                        <p class="text-xs text-gray-400 py-2">Nenhum usuário cadastrado.</p>
                    @endforelse
                </div>
            </div>
        </div>

    </div>

</div>
@endsection
