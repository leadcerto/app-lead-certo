@extends('layouts.app')

@section('title', 'Selecionar Páginas — Lead Certo')

@section('content')
<div class="max-w-2xl">

    <h1 class="text-xl font-bold text-gray-800 mb-1">Selecionar Páginas da Meta</h1>
    <p class="text-sm text-gray-500 mb-6">
        Sua conta da Meta pode administrar páginas de vários negócios diferentes.
        Marque <strong>somente</strong> a(s) página(s) que pertencem a esta empresa —
        as demais não serão vinculadas aqui.
    </p>

    @if(session('sucesso'))
        <div class="bg-green-50 border border-green-200 text-green-700 text-sm rounded-xl px-4 py-3 mb-5">
            {{ session('sucesso') }}
        </div>
    @endif

    @if(session('erro'))
        <div class="bg-red-50 border border-red-200 text-red-700 text-sm rounded-xl px-4 py-3 mb-5">
            {{ session('erro') }}
        </div>
    @endif

    <form method="POST" action="{{ route('meta.vincular-paginas') }}">
        @csrf

        <div class="bg-white rounded-2xl shadow-sm p-6 mb-4">
            @if(empty($paginas))
                <p class="text-sm text-gray-400 italic">
                    Nenhuma página do Facebook foi encontrada para esta conta.
                </p>
            @else
                <div class="space-y-2">
                    @foreach($paginas as $pag)
                        @php
                            $marcada = in_array($pag['id'], $idsJaAtivos, true);
                            $fotoUrl = $pag['picture']['data']['url'] ?? null;
                            $igUsername = $pag['instagram_business_account']['username'] ?? null;
                        @endphp
                        <label class="flex items-center gap-3 bg-gray-50 hover:bg-gray-100 rounded-xl border border-gray-200 p-3 cursor-pointer transition-colors">
                            <input type="checkbox" name="paginas[]" value="{{ $pag['id'] }}" {{ $marcada ? 'checked' : '' }}
                                   class="w-4 h-4 rounded border-gray-300 text-blue-600 focus:ring-blue-500 flex-shrink-0">

                            @if($fotoUrl)
                                <img src="{{ $fotoUrl }}" class="w-9 h-9 rounded-full flex-shrink-0" alt="">
                            @else
                                <div class="w-9 h-9 rounded-full bg-blue-100 text-blue-600 flex items-center justify-center font-bold text-xs flex-shrink-0">
                                    FB
                                </div>
                            @endif

                            <div class="min-w-0 flex-1">
                                <p class="font-medium text-gray-800 text-sm truncate">{{ $pag['name'] ?? 'Página Facebook' }}</p>
                                <p class="text-[11px] text-gray-400">
                                    ID: {{ $pag['id'] }}
                                    @if($igUsername)
                                        · Instagram: @<span>{{ $igUsername }}</span>
                                    @endif
                                </p>
                            </div>
                        </label>
                    @endforeach
                </div>
            @endif
        </div>

        <div class="flex gap-3">
            <a href="{{ route('integracoes') }}" class="flex-1 text-center py-2 rounded-xl text-sm font-medium border border-gray-300 text-gray-600 hover:bg-gray-50 transition-colors">
                Cancelar
            </a>
            <button type="submit"
                    style="background-color: #1877F2; color: #ffffff;"
                    class="flex-1 py-2 rounded-xl text-sm font-semibold hover:opacity-95 transition-all shadow-md">
                Confirmar Páginas
            </button>
        </div>
    </form>
</div>
@endsection
