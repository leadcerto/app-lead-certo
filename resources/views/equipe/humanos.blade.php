@extends('layouts.app')
@section('title', 'Agentes Humanos — Equipe da Empresa')

@section('content')
<div class="max-w-7xl mx-auto space-y-6">

    {{-- Topo / Header --}}
    <div class="flex flex-col md:flex-row md:items-center justify-between gap-4">
        <div>
            <h1 class="text-2xl font-bold text-gray-800 flex items-center gap-2">
                <span>👥</span> Agentes Humanos (Equipe)
            </h1>
            <p class="text-sm text-gray-500 mt-1">
                Estrutura hierárquica e membros da equipe que operam os processos de atendimento, vendas e liderança.
            </p>
        </div>

        <div class="flex items-center gap-2">
            <span class="text-xs font-semibold px-3 py-1.5 bg-blue-50 text-blue-700 rounded-xl border border-blue-100">
                Total de Membros: <strong>{{ $totalMembros }}</strong>
            </span>
        </div>
    </div>

    {{-- Seções Hierárquicas --}}
    <div class="space-y-8">
        @foreach($grupos as $chave => $grupo)
        <div class="bg-white rounded-3xl border border-gray-200 shadow-sm overflow-hidden">
            {{-- Cabeçalho do Nível Hierárquico --}}
            <div class="px-6 py-4 bg-gray-50/80 border-b border-gray-100 flex flex-col sm:flex-row sm:items-center justify-between gap-2">
                <div>
                    <h2 class="text-base font-bold text-gray-800 flex items-center gap-2">
                        {{ $grupo['titulo'] }}
                        <span class="text-xs font-semibold px-2 py-0.5 bg-gray-200 text-gray-700 rounded-full">
                            {{ $grupo['usuarios']->count() }}
                        </span>
                    </h2>
                    <p class="text-xs text-gray-500 mt-0.5">{{ $grupo['descricao'] }}</p>
                </div>
            </div>

            {{-- Grid de Membros deste nível --}}
            <div class="p-6">
                @if($grupo['usuarios']->isNotEmpty())
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                    @foreach($grupo['usuarios'] as $u)
                    <div class="bg-white rounded-2xl border border-gray-100 p-4 shadow-sm hover:shadow transition flex items-start gap-4">
                        @if($u->avatar_url)
                            <img src="{{ $u->avatar_url }}" class="w-12 h-12 rounded-xl object-cover ring-2 ring-gray-100 flex-shrink-0" alt="">
                        @else
                            <div class="w-12 h-12 rounded-xl bg-gradient-to-tr from-blue-600 to-indigo-600 text-white flex items-center justify-center text-lg font-bold flex-shrink-0 shadow-sm">
                                {{ mb_substr($u->nome, 0, 1) }}
                            </div>
                        @endif

                        <div class="min-w-0 flex-1 space-y-1">
                            <div class="flex items-center justify-between gap-1">
                                <h3 class="font-bold text-gray-800 text-sm truncate">{{ $u->nome }}</h3>
                                <span class="text-[10px] font-semibold {{ $u->ativo ? 'text-emerald-600' : 'text-gray-400' }}">
                                    {{ $u->ativo ? '● Ativo' : '○ Inativo' }}
                                </span>
                            </div>

                            <p class="text-xs text-gray-400 truncate">{{ $u->email }}</p>

                            @if($u->whatsapp)
                            <a href="https://wa.me/55{{ preg_replace('/\D/', '', $u->whatsapp) }}" target="_blank"
                               class="inline-flex items-center gap-1 text-xs text-emerald-600 hover:text-emerald-700 font-medium pt-0.5">
                                <svg class="w-3.5 h-3.5" fill="currentColor" viewBox="0 0 24 24"><path d="M12 2C6.48 2 2 6.48 2 12c0 1.85.5 3.58 1.38 5.07L2 22l4.93-1.38A9.96 9.96 0 0012 22c5.52 0 10-4.48 10-10S17.52 2 12 2z"/></svg>
                                {{ $u->whatsapp }}
                            </a>
                            @endif

                            @if($u->cargos->isNotEmpty())
                            <div class="flex flex-wrap gap-1 pt-1.5">
                                @foreach($u->cargos as $c)
                                    <span class="text-[10px] font-medium bg-blue-50 text-blue-700 px-2 py-0.5 rounded-md">
                                        {{ $c->icone ?: '💼' }} {{ $c->nome }}
                                    </span>
                                @endforeach
                            </div>
                            @endif
                        </div>
                    </div>
                    @endforeach
                </div>
                @else
                <div class="text-center py-6 text-gray-400 text-xs italic">
                    Nenhum membro registrado nesta categoria hierárquica.
                </div>
                @endif
            </div>
        </div>
        @endforeach
    </div>

</div>
@endsection
