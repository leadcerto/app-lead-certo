@extends('layouts.app')

@section('title', 'Nova Postagem Meta — Lead Certo')

@section('content')
<div class="max-w-3xl mx-auto space-y-6 pb-12"
     x-data="{
         canal: '{{ old('canal_alvo', 'ambos') }}',
         publicarAgora: {{ old('publicar_imediato', '1') == '1' ? 'true' : 'false' }},
         gatilho: '{{ old('modo_gatilho', 'nenhum') }}',
         textoLegenda: @js(old('texto', '')),
         imagemUrlEscolhida: @js(old('imagem_url', '')),
         modalImagens: false,
         modalTemplates: false,
         ctaTipo: '{{ old('cta_tipo', 'LEARN_MORE') }}',
         ctaUrl: @js(old('cta_url', 'https://api.whatsapp.com/send/?phone=5521981813106&text=Ol%C3%A1%2C+gostaria+de+um+or%C3%A7amento+de+frete+no+Rio+de+Janeiro%21+%28Vi+no+Facebook%29&type=phone_number&app_absent=0')),
         aplicarWhatsAppPadrao() {
             this.ctaTipo = 'LEARN_MORE';
             this.ctaUrl = 'https://api.whatsapp.com/send/?phone=5521981813106&text=Ol%C3%A1%2C+gostaria+de+um+or%C3%A7amento+de+frete+no+Rio+de+Janeiro%21+%28Vi+no+Facebook%29&type=phone_number&app_absent=0';
         },
         escolherImagem(url) {
             this.imagemUrlEscolhida = url;
             this.modalImagens = false;
         },
         aplicarTemplate(texto) {
             this.textoLegenda = texto;
             this.modalTemplates = false;
         }
     }">

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

    <form method="POST" action="{{ route('meta-posts.store') }}" enctype="multipart/form-data" class="bg-white rounded-2xl shadow-sm border border-gray-100 p-6 space-y-6">
        @csrf

        {{-- Canal Alvo --}}
        <div>
            <label class="block text-xs font-bold text-gray-700 uppercase tracking-wider mb-2">Canal de Divulgação *</label>
            <div class="grid grid-cols-3 gap-3">
                <label :class="canal === 'facebook' ? 'border-blue-600 bg-blue-50/50 text-blue-700 font-bold' : 'border-gray-200 hover:bg-gray-50 text-gray-700'" class="border rounded-xl p-3 flex flex-col items-center justify-center gap-1.5 cursor-pointer transition text-center">
                    <input type="radio" name="canal_alvo" value="facebook" x-model="canal" class="sr-only">
                    <span class="text-lg">📘</span>
                    <span class="text-xs font-bold">Facebook</span>
                </label>
                <label :class="canal === 'instagram' ? 'border-pink-600 bg-pink-50/50 text-pink-700 font-bold' : 'border-gray-200 hover:bg-gray-50 text-gray-700'" class="border rounded-xl p-3 flex flex-col items-center justify-center gap-1.5 cursor-pointer transition text-center">
                    <input type="radio" name="canal_alvo" value="instagram" x-model="canal" class="sr-only">
                    <span class="text-lg">📷</span>
                    <span class="text-xs font-bold">Instagram</span>
                </label>
                <label :class="canal === 'ambos' ? 'border-purple-600 bg-purple-50/50 text-purple-700 font-bold' : 'border-gray-200 hover:bg-gray-50 text-gray-700'" class="border rounded-xl p-3 flex flex-col items-center justify-center gap-1.5 cursor-pointer transition text-center">
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
                    @php
                        $selecionada = (old('meta_pagina_id') == $pagina->id) || ($paginas->count() === 1);
                    @endphp
                    <option value="{{ $pagina->id }}" {{ $selecionada ? 'selected' : '' }}>
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
                    @php
                        $nomeExibicao = '@' . ($conta->username ?: $conta->nome ?: $conta->instagram_business_id);
                        $selecionada = (old('meta_conta_instagram_id') == $conta->id) || ($contasInstagram->count() === 1);
                    @endphp
                    <option value="{{ $conta->id }}" {{ $selecionada ? 'selected' : '' }}>
                        {{ $nomeExibicao }}
                    </option>
                @endforeach
            </select>
            @if($contasInstagram->isEmpty())
                <p class="text-[11px] text-amber-600">Nenhuma Conta do Instagram encontrada. Vincule um Instagram Business à sua Página do Facebook em <a href="{{ route('meta.index') }}" class="underline font-semibold">Integrações Meta</a>.</p>
            @endif
        </div>

        {{-- Texto da Publicação + Banco de Textos --}}
        <div class="space-y-2">
            <div class="flex items-center justify-between">
                <label class="block text-xs font-bold text-gray-700 uppercase tracking-wider">Texto / Legenda *</label>
                <div class="flex items-center gap-2">
                    <button type="button" @click="modalTemplates = true" class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-lg bg-green-50 text-green-700 border border-green-200 text-xs font-bold hover:bg-green-100 transition">
                        <span>📝</span>
                        <span>Banco de Textos</span>
                    </button>
                    <span class="text-[11px] text-gray-400">Máx. 2.200 carac.</span>
                </div>
            </div>
            <textarea name="texto" x-model="textoLegenda" rows="5" maxlength="2200" required placeholder="Escreva aqui a legenda da postagem..." class="w-full border border-gray-300 rounded-xl p-3 text-sm focus:ring-2 focus:ring-green-500 focus:border-green-500 outline-none leading-relaxed"></textarea>
        </div>

        {{-- Imagem + Banco de Imagens --}}
        <div class="space-y-3">
            <div class="flex items-center justify-between">
                <label class="block text-xs font-bold text-gray-700 uppercase tracking-wider">Imagem / Criativo</label>
                <button type="button" @click="modalImagens = true" class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-lg bg-blue-50 text-blue-700 border border-blue-200 text-xs font-bold hover:bg-blue-100 transition">
                    <span>🖼️</span>
                    <span>Banco de Imagens</span>
                </button>
            </div>

            {{-- Pré-visualização da imagem selecionada do banco --}}
            <template x-if="imagemUrlEscolhida">
                <div class="p-3 bg-gray-50 rounded-xl border border-gray-200 flex items-center justify-between gap-3">
                    <div class="flex items-center gap-3 overflow-hidden">
                        <img :src="imagemUrlEscolhida" alt="Imagem Selecionada" class="w-14 h-14 object-cover rounded-lg border border-gray-200 flex-shrink-0">
                        <div class="overflow-hidden">
                            <p class="text-xs font-bold text-gray-800 truncate">Imagem do Banco selecionada</p>
                            <p class="text-[11px] text-gray-500 truncate" x-text="imagemUrlEscolhida"></p>
                        </div>
                    </div>
                    <button type="button" @click="imagemUrlEscolhida = ''" class="text-xs text-red-600 hover:underline px-2 py-1 flex-shrink-0">Remover</button>
                </div>
            </template>

            <div class="border border-gray-200 border-dashed rounded-xl p-4 text-center bg-gray-50/50 hover:bg-gray-50 transition">
                <p class="text-xs text-gray-500 mb-2">Fazer upload de nova imagem do seu computador:</p>
                <input type="file" name="imagem" accept="image/*" class="w-full text-xs text-gray-500 file:mr-3 file:py-1.5 file:px-3 file:rounded-lg file:border-0 file:text-xs file:font-semibold file:bg-green-600 file:text-white hover:file:bg-green-700 file:cursor-pointer">
            </div>

            <div class="flex items-center gap-2 pt-1">
                <span class="text-[11px] text-gray-400 whitespace-nowrap">URL da imagem:</span>
                <input type="url" name="imagem_url" x-model="imagemUrlEscolhida" placeholder="https://exemplo.com/foto.jpg" class="flex-1 border border-gray-300 rounded-lg px-3 py-1.5 text-xs focus:ring-2 focus:ring-green-500 outline-none">
            </div>
            <p class="text-[10px] text-gray-400">💡 No Instagram, a imagem precisa ter proporção adequada (quadrada 1:1 ou retrato 4:5).</p>
        </div>

        {{-- Botão CTA (Facebook) --}}
        <div x-show="canal === 'facebook' || canal === 'ambos'" x-transition class="border-t border-gray-100 pt-4 space-y-3">
            <div class="flex items-center justify-between flex-wrap gap-2">
                <div>
                    <p class="text-xs font-bold text-gray-700 uppercase tracking-wider">🔘 Botão de Ação CTA (Exclusivo Facebook)</p>
                    <p class="text-[11px] text-gray-400">Adicione um botão clicável na postagem direcionando os clientes direto para o seu WhatsApp.</p>
                </div>
                <button type="button" @click="aplicarWhatsAppPadrao()" class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-lg bg-green-50 text-green-700 border border-green-300 text-xs font-bold hover:bg-green-100 transition">
                    <span>📲</span>
                    <span>Usar WhatsApp Frete Rio</span>
                </button>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                <div>
                    <label class="block text-[11px] text-gray-500 mb-1">Tipo de Botão</label>
                    <select name="cta_tipo" x-model="ctaTipo" class="w-full border border-gray-300 rounded-xl px-3 py-2 text-xs focus:ring-2 focus:ring-green-500 outline-none">
                        <option value="NENHUM">Sem botão</option>
                        <option value="LEARN_MORE">Saiba Mais (Chamar no WhatsApp)</option>
                        <option value="CALL">Ligar / Contato (CALL)</option>
                        <option value="SHOP">Comprar (SHOP)</option>
                        <option value="ORDER">Pedir (ORDER)</option>
                        <option value="BOOK">Agendar (BOOK)</option>
                        <option value="SIGN_UP">Cadastrar (SIGN_UP)</option>
                    </select>
                </div>
                <div class="sm:col-span-2">
                    <label class="block text-[11px] text-gray-500 mb-1">Destino (Link do WhatsApp com rastreio de origem)</label>
                    <input type="url" name="cta_url" x-model="ctaUrl" placeholder="https://api.whatsapp.com/send/..." class="w-full border border-gray-300 rounded-xl px-3 py-2 text-xs focus:ring-2 focus:ring-green-500 outline-none">
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

    {{-- MODAL: BANCO DE IMAGENS --}}
    <div x-show="modalImagens" x-transition class="fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-4" style="display: none;">
        <div @click.outside="modalImagens = false" class="bg-white rounded-2xl shadow-2xl w-full max-w-2xl max-h-[85vh] flex flex-col overflow-hidden">
            <div class="p-4 border-b border-gray-100 flex items-center justify-between">
                <div>
                    <h3 class="font-bold text-base text-gray-800 flex items-center gap-2">
                        <span>🖼️</span> Banco de Imagens da Empresa
                    </h3>
                    <p class="text-xs text-gray-500">Clique em qualquer foto para usá-la como criativo da postagem.</p>
                </div>
                <button type="button" @click="modalImagens = false" class="text-gray-400 hover:text-gray-600 p-1 text-lg">✕</button>
            </div>

            <div class="p-4 overflow-y-auto flex-1">
                @if($imagensGaleria->isEmpty())
                    <div class="py-12 text-center text-gray-400">
                        <p class="text-sm">Nenhuma imagem cadastrada no seu banco ainda.</p>
                        <a href="{{ route('admin.gmb-posts.imagens') }}" target="_blank" class="mt-2 inline-block px-4 py-2 bg-green-600 text-white text-xs font-bold rounded-lg hover:bg-green-700">
                            Fazer Upload de Imagens
                        </a>
                    </div>
                @else
                    <div class="grid grid-cols-2 sm:grid-cols-3 gap-3">
                        @foreach($imagensGaleria as $img)
                            <div @click="escolherImagem('{{ $img->imagem_url }}')"
                                 class="group cursor-pointer border rounded-xl overflow-hidden hover:border-green-500 hover:shadow-md transition bg-gray-50 flex flex-col">
                                <img src="{{ $img->imagem_url }}" alt="{{ $img->titulo }}" class="w-full h-32 object-cover group-hover:scale-105 transition-transform duration-200">
                                <div class="p-2 bg-white flex-1 flex flex-col justify-between">
                                    <p class="text-xs font-semibold text-gray-700 truncate" title="{{ $img->titulo }}">{{ $img->titulo ?: 'Sem título' }}</p>
                                    <span class="text-[10px] text-green-600 font-bold mt-1 group-hover:underline">Usar esta imagem →</span>
                                </div>
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>

            <div class="p-3 border-t border-gray-100 bg-gray-50 flex items-center justify-between">
                <a href="{{ route('admin.gmb-posts.imagens') }}" target="_blank" class="text-xs font-semibold text-green-700 hover:underline">
                    ➕ Gerenciar / Enviar Novas Fotos
                </a>
                <button type="button" @click="modalImagens = false" class="px-4 py-1.5 bg-gray-200 text-gray-700 rounded-lg text-xs font-semibold hover:bg-gray-300">
                    Fechar
                </button>
            </div>
        </div>
    </div>

    {{-- MODAL: BANCO DE TEXTOS / TEMPLATES --}}
    <div x-show="modalTemplates" x-transition class="fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-4" style="display: none;">
        <div @click.outside="modalTemplates = false" class="bg-white rounded-2xl shadow-2xl w-full max-w-2xl max-h-[85vh] flex flex-col overflow-hidden">
            <div class="p-4 border-b border-gray-100 flex items-center justify-between">
                <div>
                    <h3 class="font-bold text-base text-gray-800 flex items-center gap-2">
                        <span>📝</span> Banco de Textos & Modelos Prontos
                    </h3>
                    <p class="text-xs text-gray-500">Selecione um modelo de alta conversão para preencher a sua legenda.</p>
                </div>
                <button type="button" @click="modalTemplates = false" class="text-gray-400 hover:text-gray-600 p-1 text-lg">✕</button>
            </div>

            <div class="p-4 overflow-y-auto flex-1 space-y-3">
                {{-- Templates específicos e recomendados de Frete e Mudança --}}
                <div class="border border-green-200 bg-green-50/40 rounded-xl p-3.5 space-y-2">
                    <div class="flex items-center justify-between">
                        <span class="text-xs font-bold text-green-800">🚚 Frete & Mudança sem Estresse no Rio de Janeiro</span>
                        <button type="button"
                                @click="aplicarTemplate('🚚 Precisa de Frete ou Mudança no Rio de Janeiro sem dor de cabeça?\n\nA Frete Rio cuida de tudo para você com agilidade, pontualidade e máximo cuidado com seus pertences!\n\n✅ Transporte rápido e seguro\n✅ Profissionais experientes\n✅ O melhor custo-benefício do RJ\n\n📲 Solicite seu orçamento sem compromisso agora mesmo pelo WhatsApp!')"
                                class="px-3 py-1 bg-green-600 text-white rounded-lg text-xs font-bold hover:bg-green-700 transition">
                            Usar Este Texto
                        </button>
                    </div>
                    <p class="text-xs text-gray-600 leading-relaxed">
                        Precisa de Frete ou Mudança no Rio de Janeiro sem dor de cabeça? A Frete Rio cuida de tudo para você com agilidade, pontualidade e máximo cuidado com seus pertences...
                    </p>
                </div>

                <div class="border border-blue-200 bg-blue-50/40 rounded-xl p-3.5 space-y-2">
                    <div class="flex items-center justify-between">
                        <span class="text-xs font-bold text-blue-800">📦 Mudanças Residenciais & Comerciais</span>
                        <button type="button"
                                @click="aplicarTemplate('📦 Mudando de casa ou escritório no Rio?\n\nConte com quem é especialista em transporte seguro e pontual. Atendemos toda a capital, Zona Sul, Zona Norte, Zona Oeste e Baixada!\n\n⭐ Avaliação 5 estrelas\n⭐ Preço justo e transparência\n\n👉 Clique no botão abaixo para conversar no WhatsApp e garantir sua data!')"
                                class="px-3 py-1 bg-blue-600 text-white rounded-lg text-xs font-bold hover:bg-blue-700 transition">
                            Usar Este Texto
                        </button>
                    </div>
                    <p class="text-xs text-gray-600 leading-relaxed">
                        Mudando de casa ou escritório no Rio? Conte com quem é especialista em transporte seguro e pontual. Atendemos toda a capital, Zona Sul, Zona Norte, Zona Oeste e Baixada...
                    </p>
                </div>

                <div class="border border-purple-200 bg-purple-50/40 rounded-xl p-3.5 space-y-2">
                    <div class="flex items-center justify-between">
                        <span class="text-xs font-bold text-purple-800">⚡ Entregas Rápidas & Fretes Express</span>
                        <button type="button"
                                @click="aplicarTemplate('⚡ Entregas e Fretes Rápidos no RJ!\n\nComprou móveis, eletrodomésticos ou precisa transportar cargas no mesmo dia? Nós resolvemos com agilidade e segurança.\n\n📲 Chame no WhatsApp e receba uma cotação em minutos!')"
                                class="px-3 py-1 bg-purple-600 text-white rounded-lg text-xs font-bold hover:bg-purple-700 transition">
                            Usar Este Texto
                        </button>
                    </div>
                    <p class="text-xs text-gray-600 leading-relaxed">
                        Entregas e Fretes Rápidos no RJ! Comprou móveis, eletrodomésticos ou precisa transportar cargas no mesmo dia? Nós resolvemos com agilidade e segurança...
                    </p>
                </div>

                {{-- Templates cadastrados no banco pelo usuário --}}
                @foreach($templatesTexto as $tpl)
                    @php
                        $textoFormatado = str_replace(['{empresa}', '{cidade}', '{bairro}'], ['Frete Rio', 'Rio de Janeiro', 'RJ'], $tpl->texto_template);
                    @endphp
                    <div class="border border-gray-200 rounded-xl p-3.5 space-y-2 hover:border-gray-300 bg-white">
                        <div class="flex items-center justify-between">
                            <span class="text-xs font-bold text-gray-800">{{ $tpl->titulo_template }}</span>
                            <button type="button"
                                    @click="aplicarTemplate(@js($textoFormatado))"
                                    class="px-3 py-1 bg-gray-100 hover:bg-green-600 hover:text-white text-gray-700 rounded-lg text-xs font-bold transition">
                                Usar Este Texto
                            </button>
                        </div>
                        <p class="text-xs text-gray-600 leading-relaxed whitespace-pre-line">{{ Str::limit($textoFormatado, 180) }}</p>
                    </div>
                @endforeach
            </div>

            <div class="p-3 border-t border-gray-100 bg-gray-50 flex items-center justify-between">
                <a href="{{ route('admin.gmb-posts.templates') }}" target="_blank" class="text-xs font-semibold text-green-700 hover:underline">
                    ➕ Gerenciar / Criar Novos Templates
                </a>
                <button type="button" @click="modalTemplates = false" class="px-4 py-1.5 bg-gray-200 text-gray-700 rounded-lg text-xs font-semibold hover:bg-gray-300">
                    Fechar
                </button>
            </div>
        </div>
    </div>

</div>
@endsection
