@extends('layouts.app')

@section('title', 'Nova Postagem Meta — Lead Certo')

@section('content')
<div class="max-w-3xl mx-auto space-y-6 pb-12">

    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-2xl font-bold text-gray-800">Nova Postagem — Facebook & Instagram</h1>
            <p class="text-xs text-gray-500 mt-0.5">Agende ou publique conteúdos diretamente nas suas redes Meta com automações de direct.</p>
        </div>
        <a href="{{ route('meta-posts.index') }}" class="inline-flex items-center gap-1 px-3 py-1.5 rounded-lg border border-gray-200 text-xs font-semibold text-gray-600 hover:bg-gray-50 transition">
            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/></svg>
            Voltar ao Calendário
        </a>
    </div>

    @if($errors->any())
        <div class="p-4 bg-red-50 border border-red-200 text-red-800 rounded-xl text-sm shadow-sm">
            <div class="font-semibold mb-1 flex items-center gap-1.5">
                <svg class="w-4 h-4 text-red-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                Corrija os erros abaixo:
            </div>
            <ul class="list-disc list-inside space-y-0.5 text-xs">
                @foreach($errors->all() as $erro)
                    <li>{{ $erro }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <form method="POST" action="{{ route('meta-posts.store') }}" enctype="multipart/form-data" x-data="{ canal: 'facebook', publicarAgora: false, gatilho: 'nenhum' }" class="bg-white rounded-2xl shadow-sm border border-gray-100 p-6 space-y-6">
        @csrf

        {{-- Canal Alvo --}}
        <div>
            <label class="block text-xs font-bold text-gray-700 uppercase tracking-wider mb-2">Canal de Divulgação *</label>
            <div class="grid grid-cols-3 gap-3">
                <label :class="canal === 'facebook' ? 'border-blue-600 bg-blue-50/50 text-blue-700' : 'border-gray-200 hover:bg-gray-50 text-gray-700'" class="border rounded-xl p-3 flex flex-col items-center justify-center gap-1.5 cursor-pointer transition text-center">
                    <input type="radio" name="canal_alvo" value="facebook" x-model="canal" class="sr-only">
                    <span class="text-lg">📘</span>
                    <span class="text-xs font-bold">Facebook</span>
                </label>
                <label :class="canal === 'instagram' ? 'border-pink-600 bg-pink-50/50 text-pink-700' : 'border-gray-200 hover:bg-gray-50 text-gray-700'" class="border rounded-xl p-3 flex flex-col items-center justify-center gap-1.5 cursor-pointer transition text-center">
                    <input type="radio" name="canal_alvo" value="instagram" x-model="canal" class="sr-only">
                    <span class="text-lg">📷</span>
                    <span class="text-xs font-bold">Instagram</span>
                </label>
                <label :class="canal === 'ambos' ? 'border-purple-600 bg-purple-50/50 text-purple-700' : 'border-gray-200 hover:bg-gray-50 text-gray-700'" class="border rounded-xl p-3 flex flex-col items-center justify-center gap-1.5 cursor-pointer transition text-center">
                    <input type="radio" name="canal_alvo" value="ambos" x-model="canal" class="sr-only">
                    <span class="text-lg">✨</span>
                    <span class="text-xs font-bold">Ambos (FB + IG)</span>
                </label>
            </div>
        </div>

        {{-- Página Facebook --}}
        <div x-show="canal === 'facebook' || canal === 'ambos'" x-transition class="space-y-1.5">
            <label class="block text-xs font-bold text-gray-700 uppercase tracking-wider">Página do Facebook *</label>
            <select name="meta_pagina_id" class="w-full border border-gray-300 rounded-xl px-3.5 py-2.5 text-sm focus:ring-2 focus:ring-green-500 focus:border-green-500 outline-none">
                <option value="">Selecione a Página do Facebook...</option>
                @foreach($paginas as $pagina)
                    <option value="{{ $pagina->id }}" {{ old('meta_pagina_id') == $pagina->id ? 'selected' : '' }}>
                        {{ $pagina->nome }} (ID: {{ $pagina->facebook_page_id }})
                    </option>
                @endforeach
            </select>
            @if($paginas->isEmpty())
                <p class="text-[11px] text-amber-600">Nenhuma Página conectada. Conecte sua conta em <a href="{{ route('meta.index') }}" class="underline font-semibold">Integrações Meta</a>.</p>
            @endif
        </div>

        {{-- Conta Instagram --}}
        <div x-show="canal === 'instagram' || canal === 'ambos'" x-transition class="space-y-1.5">
            <label class="block text-xs font-bold text-gray-700 uppercase tracking-wider">Conta do Instagram *</label>
            <select name="meta_conta_instagram_id" class="w-full border border-gray-300 rounded-xl px-3.5 py-2.5 text-sm focus:ring-2 focus:ring-green-500 focus:border-green-500 outline-none">
                <option value="">Selecione a Conta Comercial do Instagram...</option>
                @foreach($contasInstagram as $conta)
                    <option value="{{ $conta->id }}" {{ old('meta_conta_instagram_id') == $conta->id ? 'selected' : '' }}>
                        @{{ $conta->username }}
                    </option>
                @endforeach
            </select>
            @if($contasInstagram->isEmpty())
                <p class="text-[11px] text-amber-600">Nenhuma Conta do Instagram encontrada. Vincule um Instagram Business à sua Página do Facebook em <a href="{{ route('meta.index') }}" class="underline font-semibold">Integrações Meta</a>.</p>
            @endif
        </div>

        {{-- Texto da Publicação --}}
        <div class="space-y-1.5">
            <div class="flex items-center justify-between">
                <label class="block text-xs font-bold text-gray-700 uppercase tracking-wider">Texto / Legenda *</label>
                <span class="text-[11px] text-gray-400">Máx. 2.200 caracteres</span>
            </div>
            <textarea name="texto" rows="4" maxlength="2200" required placeholder="Escreva aqui a legenda da postagem..." class="w-full border border-gray-300 rounded-xl p-3 text-sm focus:ring-2 focus:ring-green-500 focus:border-green-500 outline-none leading-relaxed">{{ old('texto') }}</textarea>
        </div>

        {{-- Imagem --}}
        <div class="space-y-2">
            <label class="block text-xs font-bold text-gray-700 uppercase tracking-wider">Imagem / Criativo</label>
            <div class="border border-gray-200 border-dashed rounded-xl p-4 text-center bg-gray-50/50 hover:bg-gray-50 transition">
                <input type="file" name="imagem" accept="image/*" class="w-full text-xs text-gray-500 file:mr-3 file:py-1.5 file:px-3 file:rounded-lg file:border-0 file:text-xs file:font-semibold file:bg-green-600 file:text-white hover:file:bg-green-700 file:cursor-pointer">
            </div>
            <div class="flex items-center gap-2 pt-1">
                <span class="text-[11px] text-gray-400 whitespace-nowrap">Ou URL pública da imagem:</span>
                <input type="url" name="imagem_url" value="{{ old('imagem_url') }}" placeholder="https://exemplo.com/foto.jpg" class="flex-1 border border-gray-300 rounded-lg px-3 py-1.5 text-xs focus:ring-2 focus:ring-green-500 outline-none">
            </div>
            <p class="text-[10px] text-gray-400">💡 Para o Instagram, a imagem precisa estar hospedada em URL pública ou enviada por upload (o sistema cuidará do link público).</p>
        </div>

        {{-- Botão CTA (Facebook) --}}
        <div x-show="canal === 'facebook' || canal === 'ambos'" x-transition class="border-t border-gray-100 pt-4 space-y-3">
            <div class="flex items-center justify-between">
                <p class="text-xs font-bold text-gray-700 uppercase tracking-wider">🔘 Botão de Ação CTA (Exclusivo Facebook)</p>
                <span class="text-[10px] text-gray-400">Opcional</span>
            </div>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <div>
                    <label class="block text-[11px] text-gray-500 mb-1">Tipo de Botão</label>
                    <select name="cta_tipo" class="w-full border border-gray-300 rounded-xl px-3 py-2 text-xs focus:ring-2 focus:ring-green-500 outline-none">
                        <option value="NENHUM">Sem botão</option>
                        <option value="LEARN_MORE" {{ old('cta_tipo') == 'LEARN_MORE' ? 'selected' : '' }}>Saiba Mais (LEARN_MORE)</option>
                        <option value="CALL" {{ old('cta_tipo') == 'CALL' ? 'selected' : '' }}>Ligar / Contato (CALL)</option>
                        <option value="SHOP" {{ old('cta_tipo') == 'SHOP' ? 'selected' : '' }}>Comprar (SHOP)</option>
                        <option value="ORDER" {{ old('cta_tipo') == 'ORDER' ? 'selected' : '' }}>Pedir (ORDER)</option>
                        <option value="BOOK" {{ old('cta_tipo') == 'BOOK' ? 'selected' : '' }}>Agendar (BOOK)</option>
                        <option value="SIGN_UP" {{ old('cta_tipo') == 'SIGN_UP' ? 'selected' : '' }}>Cadastrar (SIGN_UP)</option>
                    </select>
                </div>
                <div>
                    <label class="block text-[11px] text-gray-500 mb-1">Destino (URL do Site ou WhatsApp)</label>
                    <input type="url" name="cta_url" value="{{ old('cta_url') }}" placeholder="https://seusite.com.br" class="w-full border border-gray-300 rounded-xl px-3 py-2 text-xs focus:ring-2 focus:ring-green-500 outline-none">
                </div>
            </div>
        </div>

        {{-- Automação de Comentários (Comment-to-DM) --}}
        <div class="border-t border-gray-100 pt-4 space-y-3">
            <div class="flex items-center justify-between">
                <p class="text-xs font-bold text-gray-700 uppercase tracking-wider">💬 Automação de Comentários (Comment-to-DM)</p>
                <span class="text-[10px] text-green-700 font-semibold bg-green-50 px-2 py-0.5 rounded">Robô de Conversão</span>
            </div>
            <p class="text-xs text-gray-500">Envie automaticamente uma mensagem no Direct privado de quem comentar nesta postagem.</p>

            <select name="modo_gatilho" x-model="gatilho" class="w-full border border-gray-300 rounded-xl px-3.5 py-2.5 text-xs focus:ring-2 focus:ring-green-500 outline-none">
                <option value="nenhum">Sem automação para este post</option>
                <option value="qualquer_comentario">Responder a QUALQUER comentário</option>
                <option value="palavra_chave">Responder apenas se contiver PALAVRAS-CHAVE específicas</option>
            </select>

            <div x-show="gatilho !== 'nenhum'" x-transition class="space-y-3 bg-gray-50 p-4 rounded-xl border border-gray-200/70">
                <div x-show="gatilho === 'palavra_chave'" class="space-y-1">
                    <label class="block text-xs font-semibold text-gray-700">Palavras-chave (separadas por vírgula)</label>
                    <input type="text" name="palavras_chave_texto" value="{{ old('palavras_chave_texto') }}" placeholder="eu quero, preco, valor, orcamento" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-xs focus:ring-2 focus:ring-green-500 outline-none bg-white">
                    <p class="text-[10px] text-gray-400">Ex: <code>quero, orcamento, tabela</code> (o robô ignora maiúsculas e acentos).</p>
                </div>

                <div class="space-y-1">
                    <label class="block text-xs font-semibold text-gray-700">Resposta pública no comentário (opcional)</label>
                    <input type="text" name="resposta_publica_comentario" value="{{ old('resposta_publica_comentario') }}" maxlength="500" placeholder="Te chamei no direct! Dá uma olhadinha 😉" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-xs focus:ring-2 focus:ring-green-500 outline-none bg-white">
                </div>

                <div class="space-y-1">
                    <label class="block text-xs font-semibold text-gray-700">Mensagem privada enviada no Direct *</label>
                    <textarea name="mensagem_direct" rows="3" maxlength="1000" placeholder="Olá! Vi seu comentário no nosso post. Como posso te ajudar hoje?" class="w-full border border-gray-300 rounded-lg p-3 text-xs focus:ring-2 focus:ring-green-500 outline-none bg-white leading-relaxed">{{ old('mensagem_direct') }}</textarea>
                </div>
            </div>
        </div>

        {{-- Agendamento vs Imediato --}}
        <div class="border-t border-gray-100 pt-4 space-y-3">
            <div class="flex items-center gap-3">
                <label class="flex items-center gap-2 cursor-pointer">
                    <input type="checkbox" name="publicar_imediato" value="1" x-model="publicarAgora" class="w-4 h-4 text-green-600 rounded focus:ring-green-500 border-gray-300">
                    <span class="text-xs font-bold text-gray-800">Publicar Imediatamente agora</span>
                </label>
            </div>

            <div x-show="!publicarAgora" x-transition class="space-y-1">
                <label class="block text-xs font-bold text-gray-700 uppercase tracking-wider">Data e Horário de Publicação *</label>
                <input type="datetime-local" name="data_agendada" :required="!publicarAgora" value="{{ old('data_agendada', now()->addHour()->format('Y-m-d\TH:i')) }}" class="w-full border border-gray-300 rounded-xl px-3.5 py-2.5 text-sm focus:ring-2 focus:ring-green-500 outline-none">
                <p class="text-[11px] text-gray-400">O sistema publicará automaticamente no Facebook e/ou Instagram no horário programado.</p>
            </div>
        </div>

        {{-- Botão de Submissão --}}
        <div class="pt-2">
            <button type="submit" class="w-full py-3 bg-green-600 text-white rounded-xl text-sm font-bold hover:bg-green-700 transition flex items-center justify-center gap-2 shadow-sm">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                <span x-text="publicarAgora ? 'Publicar Agora na Meta' : 'Confirmar Agendamento'"></span>
            </button>
        </div>

    </form>
</div>
@endsection
