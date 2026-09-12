@extends('layouts.app')

@section('title', 'Qualidade da Ficha — ' . $perfil->nome)

@section('content')
<div class="max-w-4xl mx-auto space-y-6 pb-16">

    <div class="flex items-center justify-between border-b border-gray-200 pb-4">
        <div>
            <div class="flex items-center gap-2 text-xs text-gray-500 mb-1">
                <a href="{{ route('admin.perfis-gmb.index') }}" class="hover:underline">Perfis GMB</a>
                <span>/</span>
                <span class="text-gray-700">Qualidade</span>
            </div>
            <h1 class="text-2xl font-bold text-gray-900 font-heading">📊 {{ $perfil->nome }}</h1>
            <p class="text-sm text-gray-500 mt-1">
                Avaliado em {{ $score->avaliado_em?->format('d/m/Y H:i') ?? 'nunca' }}
            </p>
        </div>
        <form action="{{ route('admin.gmb-qualidade.reavaliar', $perfil) }}" method="POST">
            @csrf
            <button type="submit" class="px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 text-sm font-semibold transition">
                🔄 Reavaliar agora
            </button>
        </form>
    </div>

    @if(session('sucesso'))
        <div class="p-3 bg-green-100 text-green-800 rounded-lg text-sm">✅ {{ session('sucesso') }}</div>
    @endif

    {{-- Nota geral --}}
    <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-6 flex items-center gap-6">
        @php
            $nota = $score->nota_geral;
            $corNota = is_null($nota) ? 'text-gray-400 border-gray-300' : ($nota >= 90 ? 'text-green-600 border-green-500' : ($nota >= 50 ? 'text-amber-500 border-amber-400' : 'text-red-600 border-red-500'));
        @endphp
        <div class="w-24 h-24 rounded-full border-4 {{ $corNota }} flex items-center justify-center flex-shrink-0">
            <span class="text-3xl font-bold {{ $corNota }}">{{ $nota ?? '—' }}</span>
        </div>
        <div>
            <div class="text-sm font-bold text-gray-800">Nota geral</div>
            <p class="text-xs text-gray-500 mt-1">Média das categorias já calculadas. Categorias ainda pendentes não entram nessa conta.</p>
        </div>
    </div>

    {{-- Categorias --}}
    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
        @foreach($score->categorias as $chave => $categoria)
        <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-5">
            <div class="flex items-center justify-between mb-3">
                <h3 class="font-bold text-gray-900">{{ $categoria['label'] }}</h3>
                @if($categoria['status'] === 'pendente')
                    <span class="px-2 py-1 bg-gray-100 text-gray-500 rounded-full text-xs font-semibold">Em breve</span>
                @else
                    @php
                        $corCategoria = $categoria['nota'] >= 90 ? 'bg-green-100 text-green-700' : ($categoria['nota'] >= 50 ? 'bg-amber-100 text-amber-700' : 'bg-red-100 text-red-700');
                    @endphp
                    <span class="px-2 py-1 {{ $corCategoria }} rounded-full text-xs font-bold">{{ $categoria['nota'] }}</span>
                @endif
            </div>

            @foreach($categoria['diagnosticos'] as $diag)
                @php
                    $corDiag = match($diag['tipo']) {
                        'ok' => 'border-green-400 text-gray-700',
                        'aviso' => 'border-amber-400 text-gray-700',
                        'erro' => 'border-red-400 text-gray-700',
                        default => 'border-gray-300 text-gray-500',
                    };
                @endphp
                <div class="pl-3 border-l-2 {{ $corDiag }} text-sm mb-2">
                    <p>{{ $diag['mensagem'] }}</p>
                    @if(!empty($diag['acao_url']))
                        <a href="{{ $diag['acao_url'] }}" class="inline-block mt-1 text-xs font-semibold text-green-700 hover:underline">{{ $diag['acao_label'] }} →</a>
                    @endif
                </div>
            @endforeach
        </div>
        @endforeach
    </div>
</div>
@endsection
