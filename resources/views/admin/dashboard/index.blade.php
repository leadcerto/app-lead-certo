@extends('layouts.app')

@section('title', 'Centro de Comando Master — Lead Certo')

@section('content')
<div class="max-w-7xl mx-auto space-y-6" x-data="{
    tab: 'empresas',
    modalHumano: false,
    modalIa: false
}">

    {{-- Topo Executivo --}}
    <div class="flex flex-col lg:flex-row justify-between items-start lg:items-center gap-4 bg-gradient-to-r from-gray-900 via-gray-800 to-slate-900 text-white p-6 rounded-2xl shadow-lg">
        <div>
            <div class="flex items-center gap-2 text-xs font-mono text-green-400 mb-1">
                <span class="w-2 h-2 rounded-full bg-green-400 animate-pulse"></span>
                SISTEMA OPERACIONAL • SUPER ADMIN
            </div>
            <h1 class="text-2xl font-bold font-heading tracking-tight">Centro de Comando Master</h1>
            <p class="text-xs text-gray-300 mt-1">Supervisão global de franquias, agentes autônomos de IA, equipe de vendas e canais de automação.</p>
        </div>

        {{-- Barra de Ações Rápidas --}}
        <div class="flex flex-wrap items-center gap-2.5">
            <a href="{{ route('admin.empresas.create') }}"
               class="px-3.5 py-2 bg-green-600 hover:bg-green-500 text-white text-xs font-bold rounded-xl shadow transition flex items-center gap-1.5">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                + Nova Empresa
            </a>

            <button type="button" @click="modalIa = true"
                    class="px-3.5 py-2 bg-purple-600 hover:bg-purple-500 text-white text-xs font-bold rounded-xl shadow transition flex items-center gap-1.5">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
                + Novo Agente IA
            </button>

            <button type="button" @click="modalHumano = true"
                    class="px-3.5 py-2 bg-blue-600 hover:bg-blue-500 text-white text-xs font-bold rounded-xl shadow transition flex items-center gap-1.5">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18 9v3m0 0v3m0-3h3m-3 0h-3m-2-5a4 4 0 11-8 0 4 4 0 018 0zM3 20a6 6 0 0112 0v1H3v-1z"/></svg>
                + Novo Agente Humano
            </button>

            <a href="{{ route('admin.gmb-posts.create') }}"
               class="px-3.5 py-2 bg-amber-600 hover:bg-amber-500 text-white text-xs font-bold rounded-xl shadow transition flex items-center gap-1.5">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 20H5a2 2 0 01-2-2V6a2 2 0 012-2h10a2 2 0 012 2v1m2 13a2 2 0 01-2-2V7m2 13a2 2 0 002-2V9a2 2 0 00-2-2h-2m-4-3H9M7 16h6M7 8h6v4H7V8z"/></svg>
                + Post Google Maps
            </a>
        </div>
    </div>

    {{-- Feedback Alerts --}}
    @if(session('sucesso'))
        <div class="p-4 bg-green-50 border border-green-200 text-green-800 rounded-xl text-sm flex items-center gap-3">
            <svg class="w-5 h-5 text-green-600 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20">
                <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/>
            </svg>
            {{ session('sucesso') }}
        </div>
    @endif

    {{-- Grid de KPIs Globais --}}
    <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-6 gap-4">
        {{-- Empresas --}}
        <div class="bg-white p-4 rounded-xl border border-gray-200 shadow-sm">
            <span class="text-[11px] font-bold text-gray-400 uppercase tracking-wider block">Empresas</span>
            <div class="flex items-baseline gap-1.5 mt-1">
                <span class="text-2xl font-bold text-gray-900 font-mono">{{ $kpis['empresas_ativas'] }}</span>
                <span class="text-xs text-gray-400 font-mono">/ {{ $kpis['total_empresas'] }}</span>
            </div>
            <span class="text-[10px] text-green-600 font-medium">Franquias Ativas</span>
        </div>

        {{-- Leads Hoje --}}
        <div class="bg-white p-4 rounded-xl border border-gray-200 shadow-sm">
            <span class="text-[11px] font-bold text-gray-400 uppercase tracking-wider block">Leads Hoje</span>
            <p class="text-2xl font-bold text-blue-600 font-mono mt-1">{{ $kpis['leads_hoje'] }}</p>
            <span class="text-[10px] text-gray-500 font-mono">{{ $kpis['leads_mes'] }} no mês</span>
        </div>

        {{-- Conversão Global --}}
        <div class="bg-white p-4 rounded-xl border border-gray-200 shadow-sm">
            <span class="text-[11px] font-bold text-gray-400 uppercase tracking-wider block">Conversão</span>
            <p class="text-2xl font-bold text-green-600 font-mono mt-1">{{ $kpis['taxa_conversao'] }}%</p>
            <span class="text-[10px] text-gray-500 font-mono">{{ $kpis['fechados_mes'] }} vendas fechadas</span>
        </div>

        {{-- Agentes de IA --}}
        <div class="bg-white p-4 rounded-xl border border-purple-200 bg-purple-50/20 shadow-sm">
            <span class="text-[11px] font-bold text-purple-700 uppercase tracking-wider block">Agentes IA</span>
            <p class="text-2xl font-bold text-purple-900 font-mono mt-1">{{ $kpis['total_agentes_ia'] }}</p>
            <span class="text-[10px] text-purple-600">SDRs & Robôs Ativos</span>
        </div>

        {{-- Agentes Humanos --}}
        <div class="bg-white p-4 rounded-xl border border-blue-200 bg-blue-50/20 shadow-sm">
            <span class="text-[11px] font-bold text-blue-700 uppercase tracking-wider block">Agentes Humanos</span>
            <p class="text-2xl font-bold text-blue-900 font-mono mt-1">{{ $kpis['total_agentes_humanos'] }}</p>
            <span class="text-[10px] text-blue-600">Vendedores / Gestores</span>
        </div>

        {{-- Conexões WhatsApp --}}
        <div class="bg-white p-4 rounded-xl border border-emerald-200 bg-emerald-50/20 shadow-sm">
            <span class="text-[11px] font-bold text-emerald-700 uppercase tracking-wider block">WhatsApp</span>
            <div class="flex items-baseline gap-1.5 mt-1">
                <span class="text-2xl font-bold text-emerald-900 font-mono">{{ $kpis['whatsapp_conectados'] }}</span>
                <span class="text-xs text-gray-400 font-mono">/ {{ $kpis['whatsapp_total'] }}</span>
            </div>
            <span class="text-[10px] text-emerald-600 font-medium">Instâncias Online</span>
        </div>
    </div>

    {{-- Abas de Gestão Integrada --}}
    <div class="bg-white rounded-2xl border border-gray-200 shadow-sm overflow-hidden">
        {{-- Barra de Navegação das Abas --}}
        <div class="flex border-b border-gray-200 bg-gray-50/70 px-4 pt-2 gap-2 overflow-x-auto">
            <button type="button" @click="tab = 'empresas'" :class="tab === 'empresas' ? 'border-green-600 text-green-800 font-bold border-b-2 bg-white' : 'text-gray-500 hover:text-gray-800'" class="px-4 py-3 text-sm transition flex items-center gap-2 rounded-t-lg">
                <span>🏢 Empresas & Franqueadas ({{ $empresas->count() }})</span>
            </button>

            <button type="button" @click="tab = 'agentes_ia'" :class="tab === 'agentes_ia' ? 'border-green-600 text-green-800 font-bold border-b-2 bg-white' : 'text-gray-500 hover:text-gray-800'" class="px-4 py-3 text-sm transition flex items-center gap-2 rounded-t-lg">
                <span>🤖 Agentes de IA Autônomos ({{ $agentesIa->count() }})</span>
            </button>

            <button type="button" @click="tab = 'agentes_humanos'" :class="tab === 'agentes_humanos' ? 'border-green-600 text-green-800 font-bold border-b-2 bg-white' : 'text-gray-500 hover:text-gray-800'" class="px-4 py-3 text-sm transition flex items-center gap-2 rounded-t-lg">
                <span>👥 Agentes Humanos & Usuários</span>
            </button>

            <button type="button" @click="tab = 'posts_gmb'" :class="tab === 'posts_gmb' ? 'border-green-600 text-green-800 font-bold border-b-2 bg-white' : 'text-gray-500 hover:text-gray-800'" class="px-4 py-3 text-sm transition flex items-center gap-2 rounded-t-lg">
                <span>📅 Google Posts ({{ $kpis['posts_agendados_gmb'] }} Agendados)</span>
            </button>
        </div>

        {{-- ABA 1: EMPRESAS & CANAIS --}}
        <div x-show="tab === 'empresas'" class="p-5 space-y-4">
            <div class="flex items-center justify-between">
                <div>
                    <h3 class="text-sm font-bold text-gray-900 uppercase tracking-wider font-heading">Saúde das Franquias e Conexões</h3>
                    <p class="text-xs text-gray-500">Supervisione as conexões de Google Ads, Meta Ads, GMB e WhatsApp de cada unidade.</p>
                </div>
                <a href="{{ route('admin.empresas.create') }}" class="px-3 py-1.5 bg-green-600 text-white text-xs font-semibold rounded-lg hover:bg-green-700 transition">
                    + Cadastrar Empresa
                </a>
            </div>

            <div class="overflow-x-auto border border-gray-200 rounded-xl">
                <table class="w-full text-xs text-left">
                    <thead class="bg-gray-50 text-gray-600 font-semibold border-b border-gray-200">
                        <tr>
                            <th class="p-3">Empresa</th>
                            <th class="p-3">Nicho</th>
                            <th class="p-3 text-center">Google Ads</th>
                            <th class="p-3 text-center">Meta Ads</th>
                            <th class="p-3 text-center">Google Maps</th>
                            <th class="p-3 text-center">WhatsApp</th>
                            <th class="p-3 text-center">Status</th>
                            <th class="p-3 text-right">Ações</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @foreach($empresas as $emp)
                            <tr class="hover:bg-gray-50 transition">
                                <td class="p-3">
                                    <a href="{{ route('admin.empresas.show', $emp) }}" class="font-bold text-gray-900 hover:text-green-600 transition block">
                                        {{ $emp->nome }}
                                    </a>
                                    <span class="text-gray-400 font-mono">{{ $emp->email }}</span>
                                </td>

                                <td class="p-3">
                                    <span class="px-2 py-0.5 rounded bg-gray-100 text-gray-700 font-mono uppercase font-semibold">
                                        {{ $emp->nicho }}
                                    </span>
                                </td>

                                <td class="p-3 text-center">
                                    @if($emp->temGoogleAds())
                                        <span class="px-2 py-0.5 bg-blue-100 text-blue-800 rounded-full font-bold">✓ Conectado</span>
                                    @else
                                        <span class="text-gray-400">—</span>
                                    @endif
                                </td>

                                <td class="p-3 text-center">
                                    @if($emp->temMetaAds())
                                        <span class="px-2 py-0.5 bg-purple-100 text-purple-800 rounded-full font-bold">✓ Conectado</span>
                                    @else
                                        <span class="text-gray-400">—</span>
                                    @endif
                                </td>

                                <td class="p-3 text-center">
                                    @if($emp->temGmb())
                                        <span class="px-2 py-0.5 bg-green-100 text-green-800 rounded-full font-bold">✓ Ativo</span>
                                    @else
                                        <span class="text-gray-400">—</span>
                                    @endif
                                </td>

                                <td class="p-3 text-center">
                                    @if($emp->temWhatsappConectado())
                                        <span class="px-2 py-0.5 bg-emerald-100 text-emerald-800 rounded-full font-bold">✓ Online</span>
                                    @else
                                        <span class="px-2 py-0.5 bg-red-100 text-red-700 rounded-full font-bold">Desconectado</span>
                                    @endif
                                </td>

                                <td class="p-3 text-center">
                                    <span class="px-2 py-0.5 {{ $emp->status === 'ativo' ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-600' }} rounded-full font-semibold">
                                        {{ ucfirst($emp->status) }}
                                    </span>
                                </td>

                                <td class="p-3 text-right">
                                    <div class="flex items-center justify-end gap-1.5">
                                        <a href="{{ route('admin.empresas.show', $emp) }}" class="px-2.5 py-1 bg-gray-100 hover:bg-gray-200 text-gray-700 rounded transition">
                                            Ver 360º
                                        </a>
                                        <a href="{{ route('admin.empresas.edit', $emp) }}" class="px-2.5 py-1 bg-green-50 hover:bg-green-100 text-green-700 font-semibold rounded transition">
                                            ⚙️ Cofre
                                        </a>
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>

        {{-- ABA 2: AGENTES DE IA --}}
        <div x-show="tab === 'agentes_ia'" class="p-5 space-y-4">
            <div class="flex items-center justify-between">
                <div>
                    <h3 class="text-sm font-bold text-gray-900 uppercase tracking-wider font-heading">Agentes Autônomos de Inteligência Artificial</h3>
                    <p class="text-xs text-gray-500">Personas SDR de atendimento WhatsApp, Mineradores e Gestores de Funil.</p>
                </div>
                <button type="button" @click="modalIa = true" class="px-3 py-1.5 bg-purple-600 hover:bg-purple-700 text-white text-xs font-semibold rounded-lg shadow transition flex items-center gap-1.5">
                    + Criar Novo Agente IA
                </button>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                @foreach($agentesIa as $agente)
                    <div class="p-4 bg-gray-50 border border-gray-200 rounded-xl space-y-3 relative hover:border-purple-300 transition">
                        <div class="flex items-start justify-between">
                            <div class="flex items-center gap-3">
                                <div class="w-10 h-10 rounded-full bg-purple-600 text-white font-bold flex items-center justify-center text-sm shadow">
                                    {{ substr($agente->nome_display, 0, 2) }}
                                </div>
                                <div>
                                    <h4 class="font-bold text-gray-900 text-sm">{{ $agente->nome_display }}</h4>
                                    <p class="text-[11px] text-gray-500">Empresa: <strong class="text-gray-700">{{ $agente->tenant?->nome }}</strong></p>
                                </div>
                            </div>
                            <span class="px-2 py-0.5 rounded-full text-[10px] font-bold {{ $agente->ativo ? 'bg-green-100 text-green-800' : 'bg-gray-200 text-gray-600' }}">
                                {{ $agente->ativo ? 'Ativo' : 'Pausado' }}
                            </span>
                        </div>

                        <div class="text-xs text-gray-600 space-y-1 bg-white p-2.5 rounded-lg border border-gray-100">
                            <p><strong>Tom de Voz:</strong> {{ $agente->tom_de_voz }}</p>
                            <p><strong>Localidade:</strong> {{ $agente->localidade }}</p>
                            <p><strong>Tier:</strong> <span class="font-mono uppercase">{{ $agente->tier }}</span></p>
                        </div>

                        <div class="text-[11px] text-gray-500 line-clamp-2 italic">
                            "{{ Str::limit($agente->system_prompt, 120) }}"
                        </div>
                    </div>
                @endforeach
            </div>
        </div>

        {{-- ABA 3: AGENTES HUMANOS --}}
        <div x-show="tab === 'agentes_humanos'" class="p-5 space-y-4">
            <div class="flex items-center justify-between">
                <div>
                    <h3 class="text-sm font-bold text-gray-900 uppercase tracking-wider font-heading">Equipe & Agentes Humanos</h3>
                    <p class="text-xs text-gray-500">Colaboradores, vendedores, gerentes e avaliadores de todas as franquias.</p>
                </div>
                <button type="button" @click="modalHumano = true" class="px-3 py-1.5 bg-blue-600 hover:bg-blue-700 text-white text-xs font-semibold rounded-lg shadow transition flex items-center gap-1.5">
                    + Cadastrar Agente Humano
                </button>
            </div>

            <div class="overflow-x-auto border border-gray-200 rounded-xl">
                <table class="w-full text-xs text-left">
                    <thead class="bg-gray-50 text-gray-600 font-semibold border-b border-gray-200">
                        <tr>
                            <th class="p-3">Nome / E-mail</th>
                            <th class="p-3">Empresa</th>
                            <th class="p-3">Cargo / Papel</th>
                            <th class="p-3">WhatsApp</th>
                            <th class="p-3">Cidade/UF</th>
                            <th class="p-3 text-center">Status</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @foreach($agentesHumanos as $user)
                            <tr class="hover:bg-gray-50 transition">
                                <td class="p-3">
                                    <strong class="text-gray-900 block">{{ $user->nome }}</strong>
                                    <span class="text-gray-400 font-mono">{{ $user->email }}</span>
                                </td>
                                <td class="p-3 font-semibold text-gray-700">
                                    {{ $user->tenant?->nome ?? 'Lead Certo (Global)' }}
                                </td>
                                <td class="p-3">
                                    <span class="px-2 py-0.5 bg-blue-50 text-blue-800 rounded font-semibold">
                                        {{ $user->perfilLabel() }}
                                    </span>
                                </td>
                                <td class="p-3 font-mono text-gray-600">
                                    {{ $user->whatsapp ?: '—' }}
                                </td>
                                <td class="p-3 text-gray-600">
                                    {{ $user->city ? $user->city . '/' . $user->state : '—' }}
                                </td>
                                <td class="p-3 text-center">
                                    <span class="px-2 py-0.5 {{ $user->ativo ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800' }} rounded-full font-bold">
                                        {{ $user->ativo ? 'Ativo' : 'Inativo' }}
                                    </span>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            @if($agentesHumanos->hasPages())
                <div class="pt-2">
                    {{ $agentesHumanos->links() }}
                </div>
            @endif
        </div>

        {{-- ABA 4: POSTS GMB --}}
        <div x-show="tab === 'posts_gmb'" class="p-5 space-y-4">
            <div class="flex items-center justify-between">
                <div>
                    <h3 class="text-sm font-bold text-gray-900 uppercase tracking-wider font-heading">Publicações no Google Maps</h3>
                    <p class="text-xs text-gray-500">Agende posts e novidades em massa para os perfis das empresas.</p>
                </div>
                <a href="{{ route('admin.gmb-posts.create') }}" class="px-3.5 py-1.5 bg-amber-600 hover:bg-amber-700 text-white text-xs font-semibold rounded-lg shadow transition">
                    + Agendar Nova Publicação
                </a>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                <div class="p-4 bg-amber-50 border border-amber-200 rounded-xl">
                    <p class="text-xs font-bold text-amber-800 uppercase">Posts Agendados</p>
                    <p class="text-2xl font-bold text-amber-900 font-mono mt-1">{{ $kpis['posts_agendados_gmb'] }}</p>
                </div>
                <div class="p-4 bg-green-50 border border-green-200 rounded-xl">
                    <p class="text-xs font-bold text-green-800 uppercase">Posts Publicados</p>
                    <p class="text-2xl font-bold text-green-900 font-mono mt-1">{{ $kpis['posts_publicados_gmb'] }}</p>
                </div>
                <div class="p-4 bg-purple-50 border border-purple-200 rounded-xl">
                    <p class="text-xs font-bold text-purple-800 uppercase">Tokens IA Gastos no Mês</p>
                    <p class="text-2xl font-bold text-purple-900 font-mono mt-1">{{ $kpis['tokens_usados_mes'] }}</p>
                </div>
            </div>

            <div class="pt-2">
                <a href="{{ route('admin.gmb-posts.index') }}" class="text-xs text-blue-600 font-semibold hover:underline flex items-center gap-1">
                    Abrir Painel Completo de Postagens GMB →
                </a>
            </div>
        </div>

    </div>

    {{-- MODAL 1: CADASTRO RÁPIDO DE AGENTE HUMANO --}}
    <div x-show="modalHumano" x-transition.opacity class="fixed inset-0 bg-black/50 backdrop-blur-sm z-50 flex items-center justify-center p-4">
        <div @click.away="modalHumano = false" class="bg-white rounded-2xl max-w-lg w-full p-6 shadow-2xl space-y-4">
            <div class="flex items-center justify-between border-b pb-3">
                <h3 class="text-lg font-bold text-gray-900 font-heading">👤 Cadastrar Novo Agente Humano</h3>
                <button type="button" @click="modalHumano = false" class="text-gray-400 hover:text-gray-600">✕</button>
            </div>

            <form action="{{ route('admin.agentes-humanos.store') }}" method="POST" class="space-y-4">
                @csrf

                <div>
                    <label class="block text-xs font-semibold text-gray-700 mb-1">Empresa / Franquia *</label>
                    <select name="tenant_id" required class="w-full text-sm border-gray-300 rounded-lg">
                        <option value="">Selecione a Empresa</option>
                        @foreach($empresas as $e)
                            <option value="{{ $e->id }}">{{ $e->nome }} ({{ $e->nicho }})</option>
                        @endforeach
                    </select>
                </div>

                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="block text-xs font-semibold text-gray-700 mb-1">Nome Completo *</label>
                        <input type="text" name="nome" required placeholder="Ex: Carlos Silva" class="w-full text-sm border-gray-300 rounded-lg">
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-gray-700 mb-1">Cargo / Papel *</label>
                        <select name="perfil" required class="w-full text-sm border-gray-300 rounded-lg">
                            <option value="vendedor">Vendedor / Closer</option>
                            <option value="gerente">Gerente de Vendas</option>
                            <option value="gestor">Gestor de Atendimento</option>
                            <option value="growth_manager">Growth Manager</option>
                            <option value="avaliador">Avaliador GMB</option>
                            <option value="diretor">Diretor</option>
                            <option value="dono">Dono da Franquia</option>
                        </select>
                    </div>
                </div>

                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="block text-xs font-semibold text-gray-700 mb-1">E-mail de Acesso *</label>
                        <input type="email" name="email" required placeholder="carlos@empresa.com" class="w-full text-sm border-gray-300 rounded-lg">
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-gray-700 mb-1">WhatsApp</label>
                        <input type="text" name="whatsapp" placeholder="21999998888" class="w-full text-sm border-gray-300 rounded-lg font-mono">
                    </div>
                </div>

                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="block text-xs font-semibold text-gray-700 mb-1">Cidade</label>
                        <input type="text" name="city" placeholder="Rio de Janeiro" class="w-full text-sm border-gray-300 rounded-lg">
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-gray-700 mb-1">Estado (UF)</label>
                        <input type="text" name="state" placeholder="RJ" maxlength="2" class="w-full text-sm border-gray-300 rounded-lg uppercase">
                    </div>
                </div>

                <div>
                    <label class="block text-xs font-semibold text-gray-700 mb-1">Senha Inicial *</label>
                    <input type="text" name="password" required value="LeadCerto@2026" class="w-full text-sm border-gray-300 rounded-lg font-mono">
                </div>

                <div class="flex items-center justify-end gap-3 pt-3 border-t">
                    <button type="button" @click="modalHumano = false" class="px-4 py-2 text-sm text-gray-600">Cancelar</button>
                    <button type="submit" class="px-5 py-2 bg-blue-600 hover:bg-blue-700 text-white text-sm font-bold rounded-lg shadow">
                        Salvar Agente Humano
                    </button>
                </div>
            </form>
        </div>
    </div>

    {{-- MODAL 2: CRIAÇÃO RÁPIDA DE AGENTE DE IA --}}
    <div x-show="modalIa" x-transition.opacity class="fixed inset-0 bg-black/50 backdrop-blur-sm z-50 flex items-center justify-center p-4">
        <div @click.away="modalIa = false" class="bg-white rounded-2xl max-w-xl w-full p-6 shadow-2xl space-y-4">
            <div class="flex items-center justify-between border-b pb-3">
                <h3 class="text-lg font-bold text-gray-900 font-heading">🤖 Criar Novo Agente de IA (Persona SDR)</h3>
                <button type="button" @click="modalIa = false" class="text-gray-400 hover:text-gray-600">✕</button>
            </div>

            <form action="{{ route('admin.agentes-ia.store') }}" method="POST" class="space-y-4">
                @csrf

                <div>
                    <label class="block text-xs font-semibold text-gray-700 mb-1">Empresa / Franquia *</label>
                    <select name="tenant_id" required class="w-full text-sm border-gray-300 rounded-lg">
                        <option value="">Selecione a Empresa</option>
                        @foreach($empresas as $e)
                            <option value="{{ $e->id }}">{{ $e->nome }} ({{ $e->nicho }})</option>
                        @endforeach
                    </select>
                </div>

                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="block text-xs font-semibold text-gray-700 mb-1">Nome de Exibição (Display) *</label>
                        <input type="text" name="nome_display" required placeholder="Ex: João da Frete Rio" class="w-full text-sm border-gray-300 rounded-lg">
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-gray-700 mb-1">Identificador Interno *</label>
                        <input type="text" name="nome_interno" required placeholder="Ex: sdr_joao_frete" class="w-full text-sm border-gray-300 rounded-lg font-mono">
                    </div>
                </div>

                <div class="grid grid-cols-3 gap-3">
                    <div>
                        <label class="block text-xs font-semibold text-gray-700 mb-1">Gênero</label>
                        <select name="genero" class="w-full text-sm border-gray-300 rounded-lg">
                            <option value="M">Masculino</option>
                            <option value="F">Feminino</option>
                            <option value="N">Neutro</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-gray-700 mb-1">Tom de Voz *</label>
                        <input type="text" name="tom_de_voz" required value="Prestativo, carioca, direto e confiável" class="w-full text-sm border-gray-300 rounded-lg">
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-gray-700 mb-1">Localidade</label>
                        <input type="text" name="localidade" value="Rio de Janeiro/RJ" class="w-full text-sm border-gray-300 rounded-lg">
                    </div>
                </div>

                <div>
                    <label class="block text-xs font-semibold text-gray-700 mb-1">Prompt do Sistema (Instruções de Atendimento) *</label>
                    <textarea name="system_prompt" rows="4" required class="w-full text-xs font-mono border-gray-300 rounded-lg" placeholder="Você é o especialista comercial da empresa. Seu objetivo é qualificar o lead com simpatia e agendar o serviço pelo WhatsApp..."></textarea>
                </div>

                <div class="flex items-center justify-end gap-3 pt-3 border-t">
                    <button type="button" @click="modalIa = false" class="px-4 py-2 text-sm text-gray-600">Cancelar</button>
                    <button type="submit" class="px-5 py-2 bg-purple-600 hover:bg-purple-700 text-white text-sm font-bold rounded-lg shadow">
                        Instanciar Agente IA
                    </button>
                </div>
            </form>
        </div>
    </div>

</div>
@endsection
