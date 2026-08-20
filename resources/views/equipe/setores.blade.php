@extends('layouts.app')
@section('title', 'Suporte')

@section('content')
<div class="max-w-2xl mx-auto">
    <div class="mb-6">
        <h1 class="text-2xl font-bold text-gray-800">Suporte</h1>
        <p class="text-sm text-gray-500 mt-1">Fale direto com a nossa equipe.</p>
    </div>

    @if(session('sucesso'))
        <div class="mb-4 p-3 bg-green-100 text-green-800 rounded-lg text-sm">✅ {{ session('sucesso') }}</div>
    @endif
    @error('mensagem')
        <div class="mb-4 p-3 bg-red-100 text-red-800 rounded-lg text-sm">{{ $message }}</div>
    @enderror

    <div class="space-y-4">
        @forelse($blocos as $bloco)
            @php $historico = $historicos->get($bloco['cargo']->id, collect()); @endphp
            <div x-data="{ aberto: {{ $historico->isNotEmpty() ? 'true' : 'false' }} }" class="bg-white rounded-xl shadow overflow-hidden">

                {{-- Linha 01: Nome + Cargo --}}
                <div class="px-5 pt-5">
                    <p class="font-bold text-gray-800">{{ $bloco['agente']?->nome ?? 'Equipe Lead Certo' }}</p>
                    <p class="text-xs text-gray-400">{{ $bloco['cargo']->nome }}</p>
                </div>

                {{-- Linha 02: foto (esquerda) + composer estilo WhatsApp (direita) --}}
                <div class="px-5 pt-3 flex gap-4 items-start">
                    <div class="flex-shrink-0">
                        @if($bloco['agente']?->avatar_url)
                            <img src="{{ $bloco['agente']->avatar_url }}" class="w-16 h-16 rounded-lg object-cover" alt="">
                        @else
                            <div class="w-16 h-16 rounded-lg bg-gray-200 flex items-center justify-center text-2xl font-bold text-gray-500">
                                {{ mb_substr($bloco['agente']?->nome ?? $bloco['cargo']->nome, 0, 1) }}
                            </div>
                        @endif
                    </div>

                    <div class="flex-1 min-w-0">
                        <template x-if="!aberto">
                            <button @click="aberto = true"
                                    class="w-full bg-green-600 hover:bg-green-700 text-white text-sm font-semibold py-2.5 rounded-lg transition flex items-center justify-center gap-2">
                                <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 24 24"><path d="M12 2C6.48 2 2 6.48 2 12c0 1.85.5 3.58 1.38 5.07L2 22l4.93-1.38A9.96 9.96 0 0012 22c5.52 0 10-4.48 10-10S17.52 2 12 2z"/></svg>
                                Iniciar conversa
                            </button>
                        </template>

                        <template x-if="aberto">
                            <div>
                                <div class="space-y-2 max-h-64 overflow-y-auto mb-2 pr-1">
                                    @forelse($historico as $item)
                                        <div>
                                            <div class="bg-green-50 text-green-900 rounded-2xl rounded-tr-sm px-3 py-2 text-xs ml-auto max-w-[85%]">
                                                @if($item->tipo_midia === 'imagem' && $item->midia_url)
                                                    <img src="{{ $item->midia_url }}" class="rounded-lg max-w-full max-h-32 mb-1">
                                                @elseif($item->tipo_midia === 'arquivo' && $item->midia_url)
                                                    <a href="{{ $item->midia_url }}" target="_blank" class="underline block mb-1">📎 Arquivo enviado</a>
                                                @endif
                                                {{ $item->mensagem }}
                                            </div>
                                            <div class="bg-gray-100 text-gray-700 rounded-2xl rounded-tl-sm px-3 py-2 text-xs max-w-[85%] mt-1">
                                                {{ $item->resposta }}
                                            </div>
                                        </div>
                                    @empty
                                        <p class="text-xs text-gray-400 text-center py-2">Nenhuma conversa ainda.</p>
                                    @endforelse
                                </div>

                                <form action="{{ route('equipe.conversar.store', $bloco['cargo']->id) }}" method="POST"
                                      enctype="multipart/form-data" class="flex items-center gap-1.5 bg-gray-50 rounded-full pl-3 pr-1 py-1">
                                    @csrf
                                    <label class="cursor-pointer text-gray-400 hover:text-gray-600 flex-shrink-0" title="Anexar imagem, arquivo ou áudio">
                                        <input type="file" name="arquivo" class="hidden" accept="image/*,audio/*,.pdf,.doc,.docx,.xls,.xlsx,.txt,.zip">
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.172 7l-6.586 6.586a2 2 0 102.828 2.828l6.414-6.586a4 4 0 00-5.656-5.656l-6.415 6.585a6 6 0 108.486 8.486L20.5 13"/></svg>
                                    </label>
                                    <input type="text" name="mensagem" placeholder="Escreva sua mensagem..."
                                           class="flex-1 text-sm bg-transparent focus:outline-none py-1.5">
                                    <button class="bg-green-600 hover:bg-green-700 text-white w-8 h-8 rounded-full flex items-center justify-center flex-shrink-0">
                                        <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 24 24"><path d="M2 21l21-9L2 3v7l15 2-15 2z"/></svg>
                                    </button>
                                </form>
                            </div>
                        </template>
                    </div>
                </div>

                {{-- Linha 03: descrição amigável (não técnica) --}}
                <p class="text-sm text-gray-500 px-5 py-4">
                    {{ $bloco['cargo']->descricao_cliente ?: $bloco['cargo']->descricao }}
                </p>
            </div>
        @empty
            <p class="text-sm text-gray-400 text-center py-8">Nenhum setor disponível pra contato ainda.</p>
        @endforelse
    </div>
</div>
@endsection
