@extends('layouts.app')
@section('title', 'Cargos da estrutura — Equipe Lead Certo')

@section('content')
<div class="max-w-4xl mx-auto">
    <a href="{{ route('admin.equipe.index') }}" class="text-sm text-gray-400 hover:text-gray-600">&larr; Equipe</a>

    <div class="flex items-center justify-between mt-3 mb-6">
        <div>
            <h1 class="text-2xl font-bold text-gray-800">🗂️ Cargos da estrutura</h1>
            <p class="text-sm text-gray-500 mt-1">Todo cargo já pensado pra equipe Lead Certo, mesmo os que ainda não têm ninguém ocupando.</p>
        </div>
    </div>

    @if(session('sucesso'))
        <div class="mb-4 p-3 bg-green-100 text-green-800 rounded-lg text-sm">✅ {{ session('sucesso') }}</div>
    @endif

    <div class="bg-white rounded-xl shadow p-6 mb-4">
        <h2 class="font-semibold text-gray-800 mb-3">Criar novo cargo</h2>
        <form action="{{ route('admin.equipe.cargos.store') }}" method="POST" class="space-y-2">
            @csrf
            <input type="text" name="nome" required maxlength="100" placeholder="Nome do cargo"
                   class="w-full text-sm border border-gray-300 rounded-lg px-2 py-1.5">
            <textarea name="descricao" required maxlength="2000" placeholder="Descrição das funções (pode ser um rascunho, sem problema)"
                      class="w-full text-sm border border-gray-300 rounded-lg px-2 py-1.5" rows="2"></textarea>
            <select name="cargo_pai_id" class="text-xs border border-gray-300 rounded-lg px-2 py-1.5">
                <option value="">Sem cargo superior (topo do organograma)</option>
                @foreach($cargos as $c)
                    <option value="{{ $c->id }}">Reporta pra: {{ $c->nome }}</option>
                @endforeach
            </select>
            <label class="flex items-center gap-1.5 text-xs text-gray-600">
                <input type="checkbox" name="visivel_para_clientes" value="1">
                Aparece na tela de Suporte pros clientes escolherem
            </label>
            <button class="text-xs bg-blue-600 hover:bg-blue-700 text-white px-3 py-1.5 rounded-lg font-medium">Criar cargo</button>
        </form>
    </div>

    {{-- Hierarquia: cargo do topo, com os subordinados recuados embaixo --}}
    <div class="bg-white rounded-xl shadow divide-y divide-gray-100">
        @forelse($topo as $cargo)
            @include('admin.equipe._cargo-linha', ['cargo' => $cargo])
            @foreach($subordinados->get($cargo->id, collect()) as $filho)
                <div class="pl-8 border-l-2 border-blue-100 ml-6">
                    @include('admin.equipe._cargo-linha', ['cargo' => $filho])
                </div>
            @endforeach
        @empty
            <p class="text-sm text-gray-400 text-center py-8">Nenhum cargo cadastrado ainda.</p>
        @endforelse
    </div>
</div>
@endsection
