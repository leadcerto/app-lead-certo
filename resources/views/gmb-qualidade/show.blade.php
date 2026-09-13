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
        @include('gmb-qualidade.partials.gauge', ['nota' => $score->nota_geral, 'size' => 96])
        <div>
            <div class="text-sm font-bold text-gray-800">Nota geral</div>
            <p class="text-xs text-gray-500 mt-1">Média das categorias já calculadas. Categorias pendentes ou indisponíveis não entram nessa conta.</p>
        </div>
    </div>

    {{-- Categorias --}}
    <div class="grid grid-cols-1 gap-4">
        @foreach($score->categorias as $chave => $categoria)
        <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-5">
            <div class="flex items-center justify-between mb-3">
                <h3 class="font-bold text-gray-900">{{ $categoria['label'] }}</h3>
                @if($categoria['status'] !== 'calculado')
                    <span class="px-2 py-1 bg-gray-100 text-gray-500 rounded-full text-xs font-semibold">{{ $categoria['status'] === 'erro' ? 'Indisponível' : 'Em breve' }}</span>
                @else
                    @include('gmb-qualidade.partials.gauge', ['nota' => $categoria['nota'], 'size' => 48, 'strokeWidth' => 5])
                @endif
            </div>

            @php
                $diagAtencao = collect($categoria['diagnosticos'])->reject(fn ($d) => $d['tipo'] === 'ok')->values();
                $diagAprovados = collect($categoria['diagnosticos'])->filter(fn ($d) => $d['tipo'] === 'ok')->values();
            @endphp

            @foreach($diagAtencao as $diag)
                @include('gmb-qualidade.partials.diagnostico', ['diag' => $diag])
            @endforeach

            @if($diagAprovados->isNotEmpty())
                <details class="mt-1" open>
                    <summary class="cursor-pointer text-xs font-semibold text-green-700 select-none">
                        ✅ Auditorias aprovadas ({{ $diagAprovados->count() }})
                    </summary>
                    <div class="mt-2">
                        @foreach($diagAprovados as $diag)
                            @include('gmb-qualidade.partials.diagnostico', ['diag' => $diag])
                        @endforeach
                    </div>
                </details>
            @endif
        </div>
        @endforeach
    </div>
</div>
@endsection
