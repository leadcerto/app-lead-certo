@extends('layouts.app')

@section('title', 'Gatilhos Comment-to-DM — Lead Certo')

@section('content')
<div class="max-w-5xl mx-auto">

    <div class="flex items-center justify-between mb-6">
        <div>
            <h1 class="text-xl font-bold text-gray-800">Automação Comment-to-DM</h1>
            <p class="text-sm text-gray-500">Responda comentários no Instagram e Facebook e abra conversas no Direct automaticamente</p>
        </div>
        <button onclick="document.getElementById('modal-novo-gatilho').classList.remove('hidden')"
                class="px-4 py-2 bg-gradient-to-r from-pink-600 to-purple-600 hover:from-pink-700 hover:to-purple-700 text-white rounded-xl text-sm font-semibold shadow-sm flex items-center gap-2">
            <span>+ Novo Gatilho</span>
        </button>
    </div>

    @if(session('sucesso'))
        <div class="bg-green-50 border border-green-200 text-green-700 text-sm rounded-xl px-4 py-3 mb-5">
            {{ session('sucesso') }}
        </div>
    @endif

    @if($gatilhos->isEmpty())
        <div class="bg-white rounded-2xl border border-gray-200 p-8 text-center">
            <div class="w-14 h-14 mx-auto rounded-2xl bg-pink-50 text-pink-500 flex items-center justify-center text-2xl mb-3">
                💬
            </div>
            <h3 class="font-bold text-gray-800 text-base mb-1">Nenhuma regra Comment-to-DM ativa</h3>
            <p class="text-sm text-gray-500 max-w-md mx-auto mb-5">
                Crie um gatilho para que quando alguém comentar <strong class="text-gray-700">"SAIBA MAIS"</strong> ou <strong class="text-gray-700">"QUERO"</strong> nos seus posts, o sistema envie uma mensagem no Direct instantaneamente!
            </p>
            <button onclick="document.getElementById('modal-novo-gatilho').classList.remove('hidden')"
                    class="px-5 py-2.5 bg-pink-600 hover:bg-pink-700 text-white text-sm font-semibold rounded-xl">
                Criar Primeiro Gatilho
            </button>
        </div>
    @else
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            @foreach($gatilhos as $g)
                <div class="bg-white rounded-2xl border border-gray-200 p-5 shadow-sm space-y-3 relative">
                    <div class="flex items-start justify-between">
                        <div>
                            <div class="flex items-center gap-2">
                                <span class="px-2.5 py-0.5 rounded-full text-xs font-bold {{ $g->canal_alvo === 'instagram' ? 'bg-pink-100 text-pink-700' : ($g->canal_alvo === 'facebook' ? 'bg-blue-100 text-blue-700' : 'bg-purple-100 text-purple-700') }}">
                                    {{ ucfirst($g->canal_alvo) }}
                                </span>
                                @if($g->ativo)
                                    <span class="text-[10px] text-green-600 bg-green-50 border border-green-200 px-2 py-0.5 rounded font-medium">Ativo</span>
                                @else
                                    <span class="text-[10px] text-gray-400 bg-gray-50 border border-gray-200 px-2 py-0.5 rounded font-medium">Pausado</span>
                                @endif
                            </div>
                            <h3 class="font-bold text-gray-800 text-base mt-1">{{ $g->nome }}</h3>
                        </div>

                        <form method="POST" action="{{ route('meta.gatilhos.destroy', $g) }}" onsubmit="return confirm('Deseja excluir esta regra?')">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="text-gray-400 hover:text-red-500 p-1 text-sm">
                                🗑️
                            </button>
                        </form>
                    </div>

                    <div class="bg-gray-50 rounded-xl p-3 text-xs space-y-1.5">
                        <div class="flex justify-between">
                            <span class="text-gray-500">Modo:</span>
                            <span class="font-medium text-gray-700">{{ $g->modo_gatilho === 'palavra_chave' ? 'Palavras-chave específicas' : 'Qualquer comentário' }}</span>
                        </div>
                        @if($g->modo_gatilho === 'palavra_chave' && !empty($g->palavras_chave))
                            <div class="flex flex-wrap gap-1 mt-1">
                                @foreach($g->palavras_chave as $kw)
                                    <span class="bg-white border border-gray-200 text-gray-700 px-2 py-0.5 rounded text-[11px] font-mono">{{ $kw }}</span>
                                @endforeach
                            </div>
                        @endif
                    </div>

                    <div class="text-xs space-y-1">
                        <p class="font-semibold text-gray-700">Mensagem que será enviada no Direct:</p>
                        <p class="bg-blue-50/50 border border-blue-100 rounded-lg p-2.5 text-gray-600 italic">
                            "{{ $g->mensagem_direct }}"
                        </p>
                    </div>

                    @if($g->resposta_publica_comentario)
                        <div class="text-xs space-y-1">
                            <p class="font-semibold text-gray-700">Resposta pública no comentário:</p>
                            <p class="bg-gray-50 rounded-lg p-2 text-gray-500">
                                "{{ $g->resposta_publica_comentario }}"
                            </p>
                        </div>
                    @endif
                </div>
            @endforeach
        </div>
    @endif

    {{-- Modal Criar Gatilho --}}
    <div id="modal-novo-gatilho" class="fixed inset-0 bg-black/50 backdrop-blur-sm z-50 flex items-center justify-center hidden p-4">
        <div class="bg-white rounded-3xl max-w-lg w-full p-6 shadow-xl space-y-4 max-h-[90vh] overflow-y-auto">
            <div class="flex items-center justify-between">
                <h2 class="text-lg font-bold text-gray-800">Novo Gatilho Comment-to-DM</h2>
                <button onclick="document.getElementById('modal-novo-gatilho').classList.add('hidden')" class="text-gray-400 hover:text-gray-600 text-xl">&times;</button>
            </div>

            <form method="POST" action="{{ route('meta.gatilhos.store') }}" class="space-y-4 text-sm">
                @csrf

                <div>
                    <label class="block text-xs font-semibold text-gray-700 mb-1">Nome do Gatilho *</label>
                    <input type="text" name="nome" required placeholder="Ex: Campanha Mudança Barra - Quero" class="w-full rounded-xl border border-gray-300 px-3 py-2 text-sm focus:ring-2 focus:ring-pink-500 focus:outline-none">
                </div>

                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="block text-xs font-semibold text-gray-700 mb-1">Canal Alvo *</label>
                        <select name="canal_alvo" class="w-full rounded-xl border border-gray-300 px-3 py-2 text-sm focus:ring-2 focus:ring-pink-500 focus:outline-none">
                            <option value="ambos">Instagram & Facebook</option>
                            <option value="instagram">Apenas Instagram</option>
                            <option value="facebook">Apenas Facebook</option>
                        </select>
                    </div>

                    <div>
                        <label class="block text-xs font-semibold text-gray-700 mb-1">Modo de Disparo *</label>
                        <select name="modo_gatilho" class="w-full rounded-xl border border-gray-300 px-3 py-2 text-sm focus:ring-2 focus:ring-pink-500 focus:outline-none">
                            <option value="palavra_chave">Palavras-chave específicas</option>
                            <option value="qualquer_comentario">Qualquer comentário no post</option>
                        </select>
                    </div>
                </div>

                <div>
                    <label class="block text-xs font-semibold text-gray-700 mb-1">Palavras-chave (separadas por vírgula)</label>
                    <input type="text" name="palavras_chave_texto" placeholder="saiba mais, quero, valor, tabela, frete" class="w-full rounded-xl border border-gray-300 px-3 py-2 text-sm focus:ring-2 focus:ring-pink-500 focus:outline-none">
                    <p class="text-[11px] text-gray-400 mt-0.5">Se o comentário contiver qualquer uma dessas palavras, o robô dispara.</p>
                </div>

                <div>
                    <label class="block text-xs font-semibold text-gray-700 mb-1">Mensagem Privada no Direct (DM) *</label>
                    <textarea name="mensagem_direct" rows="3" required placeholder="Olá {primeiro_nome}! Vi que você comentou no nosso post. Como podemos te ajudar?" class="w-full rounded-xl border border-gray-300 px-3 py-2 text-sm focus:ring-2 focus:ring-pink-500 focus:outline-none"></textarea>
                    <p class="text-[11px] text-gray-400 mt-0.5">Tags disponíveis: <code class="bg-gray-100 px-1 rounded">{primeiro_nome}</code>, <code class="bg-gray-100 px-1 rounded">{nome}</code>, <code class="bg-gray-100 px-1 rounded">{username}</code></p>
                </div>

                <div>
                    <label class="block text-xs font-semibold text-gray-700 mb-1">Resposta Pública no Comentário (Opcional - aquece no algoritmo)</label>
                    <input type="text" name="resposta_publica_comentario" placeholder="Te chamei no Direct! Dá uma olhadinha lá 😉" class="w-full rounded-xl border border-gray-300 px-3 py-2 text-sm focus:ring-2 focus:ring-pink-500 focus:outline-none">
                </div>

                <div class="flex gap-3 pt-2">
                    <button type="button" onclick="document.getElementById('modal-novo-gatilho').classList.add('hidden')" class="flex-1 py-2.5 rounded-xl border border-gray-300 text-gray-600 font-semibold hover:bg-gray-50">
                        Cancelar
                    </button>
                    <button type="submit" class="flex-1 py-2.5 rounded-xl bg-gradient-to-r from-pink-600 to-purple-600 text-white font-semibold hover:from-pink-700 hover:to-purple-700 shadow-sm">
                        Salvar Gatilho
                    </button>
                </div>
            </form>
        </div>
    </div>

</div>
@endsection
