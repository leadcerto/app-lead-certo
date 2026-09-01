@extends('layouts.app')

@section('title', 'Postagens — Lead Certo')

@section('content')
<div class="max-w-4xl mx-auto space-y-6">

    {{-- Header --}}
    <div class="flex items-center justify-between flex-wrap gap-4">
        <div>
            <h1 class="text-2xl font-bold text-gray-800">📅 Agendamentos de Postagens (GMB)</h1>
            <p class="text-sm text-gray-500 mt-1">
                Semana de {{ $semana->copy()->startOfWeek(\Carbon\Carbon::MONDAY)->format('d/m') }}
                a {{ $semana->copy()->endOfWeek(\Carbon\Carbon::SUNDAY)->format('d/m/Y') }}
            </p>
        </div>
        <div class="flex gap-2 flex-wrap justify-end">
            <a href="{{ route('admin.gmb-posts.lote', ['semana' => $semana->toDateString()]) }}"
               class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 text-sm font-semibold transition flex items-center gap-1.5 shadow-sm">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/></svg>
                📊 Gerador em Lote
            </a>
            <a href="{{ route('admin.gmb-posts.templates') }}"
               class="px-3.5 py-2 bg-purple-50 text-purple-700 border border-purple-200 rounded-lg hover:bg-purple-100 text-sm font-semibold transition flex items-center gap-1.5 shadow-sm">
                📑 Templates
            </a>
            <a href="{{ route('admin.gmb-posts.create') }}"
               class="px-3.5 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 text-sm font-semibold transition flex items-center gap-1.5 shadow-sm">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                + Individual
            </a>
        </div>
    </div>

    {{-- Navegação de semana --}}
    <div class="flex gap-2">
        <a href="?semana={{ $semana->copy()->subWeek()->toDateString() }}"
           class="px-3 py-1.5 bg-gray-200 text-gray-700 rounded-lg text-xs font-semibold hover:bg-gray-300 transition">
            ← Semana Anterior
        </a>
        <a href="?semana={{ now()->toDateString() }}"
           class="px-3 py-1.5 bg-green-100 text-green-700 rounded-lg text-xs font-bold hover:bg-green-200 transition">
            Semana Atual
        </a>
        <a href="?semana={{ $semana->copy()->addWeek()->toDateString() }}"
           class="px-3 py-1.5 bg-gray-200 text-gray-700 rounded-lg text-xs font-semibold hover:bg-gray-300 transition">
            Próxima Semana →
        </a>
    </div>

    {{-- Feedback Alerts --}}
    @if(session('sucesso'))
        <div class="p-3 bg-green-100 text-green-800 rounded-xl text-sm flex items-center gap-2">
            <span>✅</span>
            <span>{{ session('sucesso') }}</span>
        </div>
    @endif
    @if(session('aviso'))
        <div class="p-3 bg-amber-100 text-amber-800 rounded-xl text-sm flex items-center gap-2">
            <span>⚠️</span>
            <span>{{ session('aviso') }}</span>
        </div>
    @endif

    {{-- Estatísticas Rápidas da Semana --}}
    <div class="grid grid-cols-2 sm:grid-cols-4 gap-4">
        <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-4 text-center">
            <p class="text-2xl font-bold text-gray-800 font-mono">{{ $stats['total_semana'] }}</p>
            <p class="text-xs text-gray-500 mt-0.5">Total da Semana</p>
        </div>
        <div class="bg-white rounded-xl shadow-sm border border-amber-100 p-4 text-center">
            <p class="text-2xl font-bold text-amber-600 font-mono">{{ $stats['agendados'] }}</p>
            <p class="text-xs text-gray-500 mt-0.5">Agendados</p>
        </div>
        <div class="bg-white rounded-xl shadow-sm border border-green-100 p-4 text-center">
            <p class="text-2xl font-bold text-green-600 font-mono">{{ $stats['publicados'] }}</p>
            <p class="text-xs text-gray-500 mt-0.5">Publicados no Google</p>
        </div>
        <div class="bg-white rounded-xl shadow-sm border border-red-100 p-4 text-center">
            <p class="text-2xl font-bold text-red-600 font-mono">{{ $stats['falhas'] }}</p>
            <p class="text-xs text-gray-500 mt-0.5">Falhas / Atenção</p>
        </div>
    </div>

    {{-- Postagens Agrupadas por Dia (Formato Idêntico ao de Avaliações) --}}
    @forelse($postsPorDia as $dia => $postsDoDia)
        <div class="space-y-3">
            <h2 class="text-xs font-bold text-gray-500 uppercase tracking-wider">{{ $dia }}</h2>

            <div class="space-y-3">
                @foreach($postsDoDia as $post)
                    @php
                        $borda = match($post->status) {
                            'publicado' => 'border-green-500 bg-white opacity-90',
                            'falha'     => 'border-red-500 bg-white',
                            default     => 'border-amber-400 bg-white'
                        };
                    @endphp

                    <div class="rounded-xl shadow-sm p-4 border-l-4 {{ $borda }} hover:shadow transition">
                        <div class="flex items-start justify-between gap-4 flex-wrap">

                            {{-- Lado Esquerdo: Conteúdo do Post --}}
                            <div class="flex gap-3 flex-1 min-w-[240px]">
                                @if($post->imagem_url)
                                    <img src="{{ $post->imagem_url }}" alt="Mídia" class="w-16 h-16 rounded-lg object-cover border border-gray-200 flex-shrink-0">
                                @else
                                    <div class="w-16 h-16 rounded-lg bg-gray-100 border border-gray-200 flex items-center justify-center text-gray-400 flex-shrink-0 text-[10px]">
                                        Sem foto
                                    </div>
                                @endif

                                <div class="space-y-1 flex-1">
                                    <div class="flex items-center gap-2 flex-wrap">
                                        <h3 class="font-semibold text-gray-900 text-sm">{{ $post->perfil?->nome }}</h3>
                                        <span class="text-xs text-gray-400">({{ $post->perfil?->city }}/{{ $post->perfil?->state }})</span>
                                        <span class="px-2 py-0.5 rounded text-[10px] uppercase font-bold bg-gray-100 text-gray-700">
                                            {{ ucfirst($post->tipo) }}
                                        </span>
                                        @if($post->gerado_por_ia)
                                            <span class="px-1.5 py-0.5 rounded text-[10px] font-bold bg-purple-100 text-purple-700">
                                                ✨ IA
                                            </span>
                                        @endif
                                    </div>

                                    @if($post->titulo)
                                        <p class="text-xs font-bold text-gray-800">{{ $post->titulo }}</p>
                                    @endif

                                    <p class="text-xs text-gray-600 line-clamp-2 leading-relaxed whitespace-pre-line">{{ $post->texto }}</p>

                                    <div class="flex items-center gap-3 text-[11px] text-gray-400 pt-1">
                                        <span>⏰ Horário: <strong class="text-gray-700 font-mono">{{ $post->data_agendada->format('H:i') }}</strong></span>
                                        @if($post->cta_tipo !== 'NENHUM')
                                            <span>🔘 Botão: <strong class="text-gray-700">{{ $post->cta_tipo }}</strong></span>
                                        @endif
                                    </div>
                                </div>
                            </div>

                            {{-- Lado Direito: Status & Ações --}}
                            <div class="flex flex-col items-end gap-2">
                                @if($post->status === 'publicado')
                                    <span class="px-2.5 py-1 bg-green-100 text-green-700 rounded-full text-xs font-bold">
                                        ✓ Publicado
                                    </span>
                                @elseif($post->status === 'falha')
                                    <span class="px-2.5 py-1 bg-red-100 text-red-700 rounded-full text-xs font-bold">
                                        ⚠️ Falha
                                    </span>
                                @else
                                    <span class="px-2.5 py-1 bg-amber-100 text-amber-800 rounded-full text-xs font-bold">
                                        ⏳ Agendado
                                    </span>
                                @endif

                                <div class="flex items-center gap-2 pt-1">
                                    @if($post->status !== 'publicado')
                                        <form method="POST" action="{{ route('admin.gmb-posts.publicar-agora', $post) }}">
                                            @csrf
                                            <button type="submit" class="px-2.5 py-1 bg-blue-600 hover:bg-blue-700 text-white rounded text-xs font-semibold transition" title="Publicar no Google agora">
                                                Publicar Agora
                                            </button>
                                        </form>
                                    @endif

                                    <form method="POST" action="{{ route('admin.gmb-posts.destroy', $post) }}" onsubmit="return confirm('Excluir este post agendado?');">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="p-1 text-gray-400 hover:text-red-600 transition" title="Excluir">
                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                                        </button>
                                    </form>
                                </div>
                            </div>

                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    @empty
        <div class="bg-white rounded-2xl border border-gray-200 shadow-sm p-12 text-center text-gray-400">
            <p class="text-base font-semibold text-gray-700">✨ Nenhuma postagem agendada para esta semana!</p>
            <p class="text-xs text-gray-400 mt-1">Crie publicações para manter o perfil da empresa ativo no Google Maps.</p>
            <div class="mt-4 flex justify-center gap-3">
                <a href="{{ route('admin.gmb-posts.create') }}" class="px-4 py-2 bg-green-600 hover:bg-green-700 text-white text-xs font-bold rounded-lg transition">
                    + Agendar Postagem
                </a>
            </div>
        </div>
    @endforelse

</div>
@endsection
