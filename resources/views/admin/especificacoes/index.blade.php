@extends('layouts.app')

@section('title', 'Especificações Técnicas | Lead Certo')

@section('content')
<div class="max-w-3xl mx-auto pb-16">

    <div class="mb-6">
        <span class="text-xs font-semibold text-gray-500 bg-gray-100 px-2 py-0.5 rounded-full">Admin</span>
        <h1 class="text-2xl font-bold text-gray-800 mt-1">Especificações Técnicas</h1>
        <p class="text-sm text-gray-500 mt-1 max-w-2xl">
            Documentos de design registrados durante o desenvolvimento — decisões de arquitetura, spec de features novas antes de serem construídas. Ficam aqui pra consultar de novo mais pra frente, sem depender de memória.
        </p>
    </div>

    @if ($arquivos->isEmpty())
        <div class="bg-white border border-dashed border-gray-300 rounded-xl p-6 text-center text-sm text-gray-400">
            Nenhuma especificação registrada ainda.
        </div>
    @else
        <div class="space-y-2">
            @foreach ($arquivos as $item)
                <a href="{{ route('admin.especificacoes.show', $item['arquivo']) }}"
                   class="block bg-white border border-gray-200 rounded-xl p-4 hover:border-green-300 hover:bg-green-50/30 transition-colors">
                    <p class="text-sm font-semibold text-gray-800">{{ $item['titulo'] }}</p>
                    <p class="text-xs text-gray-400 font-mono mt-0.5">{{ $item['arquivo'] }}</p>
                </a>
            @endforeach
        </div>
    @endif

</div>
@endsection
