@extends('layouts.app')

@section('title', 'Postagens Meta — Lead Certo')

@section('content')
<div class="max-w-4xl mx-auto space-y-6">

    {{-- Header --}}
    <div class="flex items-center justify-between flex-wrap gap-4">
        <div>
            <h1 class="text-2xl font-bold text-gray-800">📅 Agendamentos de Postagens (Facebook & Instagram)</h1>
            <p class="text-sm text-gray-500 mt-1">
                Semana de {{ $semana->copy()->startOfWeek(\Carbon\Carbon::MONDAY)->format('d/m') }}
                a {{ $semana->copy()->endOfWeek(\Carbon\Carbon::SUNDAY)->format('d/m/Y') }}
            </p>
        </div>
        <a href="{{ route('meta-posts.create') }}"
           class="px-3.5 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 text-sm font-semibold transition flex items-center gap-1.5 shadow-sm">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
            + Nova Postagem
        </a>
    </div>

    {{-- Navegação da semana --}}
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
            <span>✅</span><span>{{ session('sucesso') }}</span>
        </div>
    @endif
    @if(session('erro'))
        <div class="p-4 bg-red-50 border border-red-200 text-red-800 rounded-xl text-sm flex items-start gap-3 shadow-sm">
            <span class="text-xl leading-none">❌</span>
            <div>
                <p class="font-bold text-red-900">Atenção ao publicar na Meta:</p>
                <p class="mt-1 text-xs text-red-700 leading-relaxed">{{ session('erro') }}</p>
            </div>
        </div>
    @endif

    {{-- Estatísticas Rápidas --}}
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
            <p class="text-xs text-gray-500 mt-0.5">Publicados</p>
        </div>
        <div class="bg-white rounded-xl shadow-sm border border-red-100 p-4 text-center">
            <p class="text-2xl font-bold text-red-600 font-mono">{{ $stats['falhas'] }}</p>
            <p class="text-xs text-gray-500 mt-0.5">Falhas / Atenção</p>
        </div>
    </div>

    {{-- Lista de Posts por Dia --}}
    @forelse($postsPorDia as $dia => $postsDoDia)
        <div class="space-y-3">
            <h2 class="text-xs font-bold text-gray-500 uppercase tracking-wider">{{ $dia }}</h2>

            <div class="space-y-3">
                @foreach($postsDoDia as $post)
                    @php $badge = $post->statusBadge(); @endphp

                    <div class="bg-white rounded-xl shadow-sm p-4 border-l-4 {{ str_contains($badge['class'], 'red') ? 'border-red-500' : (str_contains($badge['class'], 'green') ? 'border-green-500' : 'border-amber-400') }} hover:shadow transition">
                        <div class="flex items-start justify-between gap-4 flex-wrap">
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
                                        <span class="px-2 py-0.5 rounded text-[10px] uppercase font-bold bg-gray-100 text-gray-700">
                                            {{ match($post->canal_alvo) { 'facebook' => '📘 Facebook', 'instagram' => '📷 Instagram', default => '📘📷 Ambos' } }}
                                        </span>
                                        <span class="px-2 py-0.5 rounded text-[10px] font-bold border {{ $badge['class'] }}">
                                            {{ $badge['label'] }}
                                        </span>
                                        @if($post->pagina)
                                            <span class="text-xs text-gray-500">Página: {{ $post->pagina->nome }}</span>
                                        @endif
                                        @if($post->contaInstagram)
                                            <span class="text-xs text-pink-600 font-medium">@ {{ $post->contaInstagram->username }}</span>
                                        @endif
                                    </div>

                                    <p class="text-xs text-gray-600 line-clamp-2 leading-relaxed whitespace-pre-line">{{ $post->texto }}</p>

                                    <div class="flex items-center gap-3 text-[11px] text-gray-400 pt-1 flex-wrap">
                                        <span>⏰ Horário: <strong class="text-gray-700 font-mono">{{ $post->data_agendada->format('H:i') }}</strong></span>
                                        @if($post->cta_tipo !== 'NENHUM')
                                            <span>🔘 Botão: <strong class="text-gray-700">{{ $post->cta_tipo }}</strong></span>
                                        @endif
                                        @if($post->modo_gatilho !== 'nenhum')
                                            <span>💬 Comment-to-DM: <strong class="text-purple-700">{{ $post->modo_gatilho === 'qualquer_comentario' ? 'Qualquer comentário' : 'Palavra-chave' }}</strong></span>
                                        @endif
                                    </div>

                                    @if($post->status === 'falha' && $post->log_erro)
                                        <div class="mt-2 p-2 bg-red-50 border border-red-200 rounded-lg text-xs text-red-800 leading-snug">
                                            <span class="font-bold text-red-900">⚠️ Erro retornado:</span> {{ $post->log_erro }}
                                        </div>
                                    @endif
                                </div>
                            </div>

                            <div class="flex gap-2 flex-shrink-0 items-center">
                                @if($post->status !== 'publicado')
                                    <form method="POST" action="{{ route('meta-posts.publicar-agora', $post) }}" onsubmit="const btn = this.querySelector('button'); btn.disabled = true; btn.innerHTML = 'Publicando...'; btn.classList.add('opacity-75', 'cursor-not-allowed');">
                                        @csrf
                                        <button type="submit" class="px-3 py-1.5 bg-blue-600 text-white rounded-lg text-xs font-semibold hover:bg-blue-700 transition shadow-sm">
                                            Publicar Agora
                                        </button>
                                    </form>
                                @endif
                                <a href="{{ route('meta-posts.create', ['duplicar_de' => $post->id]) }}"
                                   class="px-3 py-1.5 bg-indigo-50 text-indigo-700 border border-indigo-200 rounded-lg text-xs font-semibold hover:bg-indigo-100 transition">
                                    🔁 Duplicar
                                </a>
                                @if($post->podeCancelar())
                                    <form method="POST" action="{{ route('meta-posts.destroy', $post) }}" onsubmit="return confirm('Cancelar esta postagem?')">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="px-3 py-1.5 bg-gray-100 text-gray-600 rounded-lg text-xs font-semibold hover:bg-gray-200 transition">
                                            Cancelar
                                        </button>
                                    </form>
                                @endif
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    @empty
        <div class="bg-white rounded-2xl border border-gray-200 shadow-sm p-12 text-center text-gray-400">
            <p class="text-base font-semibold text-gray-700">✨ Nenhuma postagem agendada para esta semana!</p>
            <p class="text-xs text-gray-400 mt-1">Crie publicações para manter o Facebook e Instagram da empresa sempre engajados.</p>
            <div class="mt-4 flex justify-center">
                <a href="{{ route('meta-posts.create') }}" class="px-4 py-2 bg-green-600 hover:bg-green-700 text-white text-xs font-bold rounded-lg transition">
                    + Agendar Postagem
                </a>
            </div>
        </div>
    @endforelse
</div>
@endsection
