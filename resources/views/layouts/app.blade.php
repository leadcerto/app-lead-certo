<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Lead Certo')</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>
<body class="bg-gray-100 font-sans">

@php
    $user   = auth()->user();
    $perfil = $user?->perfil ?? '';

    $verDashboard  = $user?->podeAcessar('dashboard');
    $verKanban     = $user?->podeAcessar('kanban');
    $verContatos   = $user?->podeAcessar('contatos');
    $verIntegra    = $user?->podeAcessar('integracoes');
    $verConfig     = $user?->podeAcessar('configuracoes');
    $verAuditor    = $user?->podeAcessar('auditor');
    $verPersonas   = $user?->podeAcessar('personas');
    $verCampanhas  = $user?->podeAcessar('campanhas');
    $verSecretaria = in_array($perfil, ['admin', 'dono']);
    $verFormularios = in_array($perfil, ['admin', 'dono']);

    $pendentesAuditoria = $verAuditor
        ? \App\Models\VinculoContatoTenant::where('auditoria_pendente', true)->count()
        : 0;

    $pendentesContatosAuditoria = $verContatos
        ? \App\Models\AuditoriaContato::where('status', 'pendente')->count()
        : 0;
@endphp

<div class="flex h-screen overflow-hidden">

    {{-- Sidebar --}}
    <aside class="w-56 bg-gray-900 text-white flex flex-col flex-shrink-0">
        <div class="px-6 py-5 border-b border-gray-700">
            <span class="text-lg font-bold tracking-wide">Lead Certo</span>
            <p class="text-xs text-gray-400 mt-0.5">{{ $user?->tenant?->nome ?? 'Admin' }}</p>
            <p class="text-xs text-gray-500 mt-0.5">{{ $user?->perfilLabel() }}</p>
        </div>

        <nav class="flex-1 px-3 py-4 space-y-1 overflow-y-auto">

            {{-- Dashboard --}}
            @if($verDashboard)
            <a href="{{ route('dashboard') }}"
               class="flex items-center gap-3 px-3 py-2 rounded-lg text-sm {{ request()->routeIs('dashboard') ? 'bg-green-600 text-white' : 'text-gray-300 hover:bg-gray-700' }}">
                <svg class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"/>
                </svg>
                Dashboard
            </a>
            @endif

            {{-- Kanban --}}
            @if($verKanban)
            @php $verKanbanConfig = in_array($perfil, ['admin', 'dono']); @endphp
            @if($verKanbanConfig)
            <div x-data="{ aberto: {{ request()->routeIs('kanban*') ? 'true' : 'false' }} }">
                <button @click="aberto = !aberto"
                        class="flex items-center gap-3 px-3 py-2 rounded-lg text-sm w-full {{ request()->routeIs('kanban*') ? 'bg-green-600 text-white' : 'text-gray-300 hover:bg-gray-700' }}">
                    <svg class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 17V7m0 10a2 2 0 01-2 2H5a2 2 0 01-2-2V7a2 2 0 012-2h2a2 2 0 012 2m0 10a2 2 0 002 2h2a2 2 0 002-2M9 7a2 2 0 012-2h2a2 2 0 012 2m0 10V7m0 10a2 2 0 002 2h2a2 2 0 002-2V7a2 2 0 00-2-2h-2a2 2 0 00-2 2"/>
                    </svg>
                    <span class="flex-1 text-left">Kanban</span>
                    <svg class="w-3 h-3 transition-transform duration-200 flex-shrink-0" :class="aberto ? 'rotate-180' : ''" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
                    </svg>
                </button>
                <div x-show="aberto" x-transition:enter="transition ease-out duration-100" x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100" class="ml-6 mt-1 space-y-0.5">
                    <a href="{{ route('kanban') }}"
                       class="flex items-center gap-2 px-3 py-1.5 rounded-lg text-xs {{ request()->routeIs('kanban') && !request()->routeIs('kanban.*') ? 'bg-green-700 text-white' : 'text-gray-400 hover:bg-gray-700 hover:text-gray-200' }}">
                        <svg class="w-3.5 h-3.5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 17V7m0 10a2 2 0 01-2 2H5a2 2 0 01-2-2V7a2 2 0 012-2h2a2 2 0 012 2m0 10a2 2 0 002 2h2a2 2 0 002-2M9 7a2 2 0 012-2h2a2 2 0 012 2m0 10V7m0 10a2 2 0 002 2h2a2 2 0 002-2V7a2 2 0 00-2-2h-2a2 2 0 00-2 2"/>
                        </svg>
                        Atendimentos
                    </a>
                    <a href="{{ route('kanban.variaveis') }}"
                       class="flex items-center gap-2 px-3 py-1.5 rounded-lg text-xs {{ request()->routeIs('kanban.variaveis') ? 'bg-green-700 text-white' : 'text-gray-400 hover:bg-gray-700 hover:text-gray-200' }}">
                        <svg class="w-3.5 h-3.5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 7h.01M7 3h5c.512 0 1.024.195 1.414.586l7 7a2 2 0 010 2.828l-7 7a2 2 0 01-2.828 0l-7-7A1.994 1.994 0 013 12V7a4 4 0 014-4z"/>
                        </svg>
                        Variáveis
                    </a>
                    <a href="{{ route('kanban.config') }}"
                       class="flex items-center gap-2 px-3 py-1.5 rounded-lg text-xs {{ request()->routeIs('kanban.config') ? 'bg-green-700 text-white' : 'text-gray-400 hover:bg-gray-700 hover:text-gray-200' }}">
                        <svg class="w-3.5 h-3.5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756 2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/>
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                        </svg>
                        Configurações
                    </a>
                </div>
            </div>
            @else
            <a href="{{ route('kanban') }}"
               class="flex items-center gap-3 px-3 py-2 rounded-lg text-sm {{ request()->routeIs('kanban') ? 'bg-green-600 text-white' : 'text-gray-300 hover:bg-gray-700' }}">
                <svg class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 17V7m0 10a2 2 0 01-2 2H5a2 2 0 01-2-2V7a2 2 0 012-2h2a2 2 0 012 2m0 10a2 2 0 002 2h2a2 2 0 002-2M9 7a2 2 0 012-2h2a2 2 0 012 2m0 10V7m0 10a2 2 0 002 2h2a2 2 0 002-2V7a2 2 0 00-2-2h-2a2 2 0 00-2 2"/>
                </svg>
                Kanban
            </a>
            @endif
            @endif

            {{-- Contatos (com submenu) --}}
            @if($verContatos)
            <div x-data="{ aberto: {{ request()->routeIs('contatos.*') ? 'true' : 'false' }} }">

                {{-- Cabeçalho do grupo --}}
                <button @click="aberto = !aberto"
                        class="flex items-center gap-3 px-3 py-2 rounded-lg text-sm w-full {{ request()->routeIs('contatos.*') ? 'bg-green-600 text-white' : 'text-gray-300 hover:bg-gray-700' }}">
                    <svg class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z"/>
                    </svg>
                    <span class="flex-1 text-left">Contatos</span>
                    @if($pendentesContatosAuditoria > 0)
                        <span class="bg-red-500 text-white text-xs font-bold rounded-full px-1.5 py-0.5 mr-1">{{ $pendentesContatosAuditoria }}</span>
                    @endif
                    <svg class="w-3 h-3 transition-transform duration-200 flex-shrink-0" :class="aberto ? 'rotate-180' : ''" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
                    </svg>
                </button>

                {{-- Subitens --}}
                <div x-show="aberto" x-transition:enter="transition ease-out duration-100" x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100" class="ml-6 mt-1 space-y-0.5">

                    {{-- Lista --}}
                    <a href="{{ route('contatos.importar') }}"
                       class="flex items-center gap-2 px-3 py-1.5 rounded-lg text-xs {{ request()->routeIs('contatos.importar') ? 'bg-green-700 text-white' : 'text-gray-400 hover:bg-gray-700 hover:text-gray-200' }}">
                        <svg class="w-3.5 h-3.5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 10h16M4 14h16M4 18h16"/>
                        </svg>
                        Lista
                    </a>

                    {{-- Auditoria --}}
                    <a href="{{ route('contatos.auditoria') }}"
                       class="flex items-center gap-2 px-3 py-1.5 rounded-lg text-xs {{ request()->routeIs('contatos.auditoria') ? 'bg-green-700 text-white' : 'text-gray-400 hover:bg-gray-700 hover:text-gray-200' }}">
                        <svg class="w-3.5 h-3.5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
                        </svg>
                        <span class="flex-1">Auditoria</span>
                        @if($pendentesContatosAuditoria > 0)
                            <span class="bg-red-500 text-white text-xs font-bold rounded-full px-1.5 py-0.5">{{ $pendentesContatosAuditoria }}</span>
                        @endif
                    </a>

                    {{-- Marcadores --}}
                    <a href="{{ route('contatos.marcadores') }}"
                       class="flex items-center gap-2 px-3 py-1.5 rounded-lg text-xs {{ request()->routeIs('contatos.marcadores') ? 'bg-green-700 text-white' : 'text-gray-400 hover:bg-gray-700 hover:text-gray-200' }}">
                        <svg class="w-3.5 h-3.5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 7h.01M7 3h5c.512 0 1.024.195 1.414.586l7 7a2 2 0 010 2.828l-7 7a2 2 0 01-2.828 0l-7-7A1.994 1.994 0 013 12V7a4 4 0 014-4z"/>
                        </svg>
                        Marcadores
                    </a>

                </div>
            </div>
            @endif

            {{-- Campanhas de Mineração --}}
            @if($verCampanhas)
            <a href="{{ route('campanhas') }}"
               class="flex items-center gap-3 px-3 py-2 rounded-lg text-sm {{ request()->routeIs('campanhas') ? 'bg-orange-600 text-white' : 'text-gray-300 hover:bg-gray-700' }}">
                <svg class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
                </svg>
                Campanhas
            </a>
            @endif

            {{-- Personas SDR --}}
            @if($verPersonas)
            <a href="{{ route('personas') }}"
               class="flex items-center gap-3 px-3 py-2 rounded-lg text-sm {{ request()->routeIs('personas') ? 'bg-purple-600 text-white' : 'text-gray-300 hover:bg-gray-700' }}">
                <svg class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/>
                </svg>
                SDR Personas
            </a>
            @endif

            {{-- Integrações --}}
            @if($verIntegra)
            <a href="{{ route('integracoes') }}"
               class="flex items-center gap-3 px-3 py-2 rounded-lg text-sm {{ request()->routeIs('integracoes') ? 'bg-green-600 text-white' : 'text-gray-300 hover:bg-gray-700' }}">
                <svg class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.656 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1"/>
                </svg>
                Integrações
            </a>
            @endif

            {{-- Configurações --}}
            @if($verConfig)
            <a href="{{ route('configuracoes') }}"
               class="flex items-center gap-3 px-3 py-2 rounded-lg text-sm {{ request()->routeIs('configuracoes') ? 'bg-green-600 text-white' : 'text-gray-300 hover:bg-gray-700' }}">
                <svg class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/>
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                </svg>
                Configurações
            </a>
            @endif

            {{-- Secretária Eletrônica --}}
            @if($verSecretaria)
            <a href="{{ route('secretaria-eletronica') }}"
               class="flex items-center gap-3 px-3 py-2 rounded-lg text-sm {{ request()->routeIs('secretaria-eletronica') ? 'bg-green-600 text-white' : 'text-gray-300 hover:bg-gray-700' }}">
                <svg class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z"/>
                </svg>
                Secretária
            </a>
            @endif


            {{-- Formulários --}}
            @if($verFormularios)
            <a href="{{ route('formularios') }}"
               class="flex items-center gap-3 px-3 py-2 rounded-lg text-sm {{ request()->routeIs('formularios') ? 'bg-green-600 text-white' : 'text-gray-300 hover:bg-gray-700' }}">
                <svg class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                </svg>
                Formulários
            </a>
            @endif

            {{-- Auditor --}}
            @if($verAuditor)
            <a href="{{ route('auditor') }}"
               class="flex items-center gap-3 px-3 py-2 rounded-lg text-sm {{ request()->routeIs('auditor') ? 'bg-yellow-600 text-white' : 'text-gray-300 hover:bg-gray-700' }}">
                <svg class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/>
                </svg>
                <span class="flex-1">Auditor</span>
                @if($pendentesAuditoria > 0)
                    <span class="bg-yellow-400 text-gray-900 text-xs font-bold rounded-full px-1.5 py-0.5">{{ $pendentesAuditoria }}</span>
                @endif
            </a>
            @endif

        </nav>

        <div class="px-3 py-4 border-t border-gray-700">
            <div class="px-3 mb-1 text-xs text-gray-400 truncate">{{ $user?->nome }}</div>
            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button type="submit"
                        class="w-full text-left flex items-center gap-3 px-3 py-2 rounded-lg text-sm text-gray-300 hover:bg-gray-700">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/>
                    </svg>
                    Sair
                </button>
            </form>
        </div>
    </aside>

    {{-- Conteúdo principal --}}
    <main class="flex-1 overflow-y-auto flex flex-col">

        {{-- Barra de topo com sino --}}
        @if($verKanban || $verDashboard)
        <div class="flex items-center justify-end px-6 py-2 border-b border-gray-200 bg-white flex-shrink-0">
            <div x-data="agendaSino()"
                 x-init="carregar(); setInterval(() => carregar(), 60000)"
                 @click.outside="aberto = false"
                 class="relative">
            <button @click="aberto = !aberto"
                    class="relative p-1.5 rounded-lg hover:bg-gray-100 transition-colors">
                <svg class="w-5 h-5 text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                          d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"/>
                </svg>
                <template x-if="totalUrgente > 0">
                    <span class="absolute -top-0.5 -right-0.5 bg-red-500 text-white text-xs font-bold rounded-full w-4 h-4 flex items-center justify-center leading-none"
                          x-text="totalUrgente > 9 ? '9+' : totalUrgente"></span>
                </template>
            </button>

            <template x-if="aberto">
                <div class="absolute right-0 top-full mt-1 w-80 bg-white rounded-2xl shadow-xl border border-gray-200 overflow-hidden z-50">
                    <div class="px-4 py-3 border-b border-gray-100 flex items-center justify-between">
                        <span class="text-sm font-semibold text-gray-800">Agenda para agora</span>
                        <template x-if="totalUrgente > 0">
                            <span class="text-xs text-red-600 font-medium" x-text="totalUrgente + ' urgente(s)'"></span>
                        </template>
                    </div>

                    <template x-if="urgentes.length > 0">
                        <div class="px-4 py-2">
                            <p class="text-xs font-semibold text-red-600 uppercase tracking-wide mb-2">Urgente</p>
                            <template x-for="item in urgentes" :key="item.id">
                                <div class="flex items-center justify-between py-1.5 gap-3">
                                    <div class="min-w-0">
                                        <p class="text-xs font-medium text-gray-800 truncate" x-text="item.titulo"></p>
                                        <p class="text-xs text-gray-400" x-text="item.descricao"></p>
                                    </div>
                                    <a :href="item.url" @click="aberto = false"
                                       class="text-xs text-green-600 font-medium hover:underline flex-shrink-0">Abrir</a>
                                </div>
                            </template>
                        </div>
                    </template>

                    <template x-if="hoje.length > 0">
                        <div class="px-4 py-2" :class="urgentes.length > 0 ? 'border-t border-gray-100' : ''">
                            <p class="text-xs font-semibold text-blue-600 uppercase tracking-wide mb-2">Hoje</p>
                            <template x-for="item in hoje" :key="item.id">
                                <div class="flex items-center justify-between py-1.5 gap-3">
                                    <div class="min-w-0">
                                        <p class="text-xs font-medium text-gray-800 truncate" x-text="item.titulo"></p>
                                        <p class="text-xs text-gray-400" x-text="item.descricao"></p>
                                    </div>
                                    <a :href="item.url" @click="aberto = false"
                                       class="text-xs text-green-600 font-medium hover:underline flex-shrink-0">Ver</a>
                                </div>
                            </template>
                        </div>
                    </template>

                    <template x-if="urgentes.length === 0 && hoje.length === 0">
                        <div class="px-4 py-6 text-center text-xs text-gray-400">
                            Nenhuma tarefa pendente agora.
                        </div>
                    </template>
                </div>
            </template>
            </div>
        </div>
        @endif

        <div class="px-8 py-6 flex-1">
            @yield('content')
        </div>

    </main>

<script>
function agendaSino() {
    return {
        aberto:       false,
        urgentes:     [],
        hoje:         [],
        totalUrgente: 0,

        async carregar() {
            try {
                const res = await fetch('/api/painel/agenda-imediata', {
                    headers: {
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                    },
                });
                if (res.ok) {
                    const data  = await res.json();
                    this.urgentes     = data.urgentes ?? [];
                    this.hoje         = data.hoje     ?? [];
                    this.totalUrgente = this.urgentes.length;
                }
            } catch (_) {}
        },
    };
}
</script>

</div>

@livewireScripts
@stack('scripts')
</body>
</html>
