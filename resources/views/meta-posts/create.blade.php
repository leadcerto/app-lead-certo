@extends('layouts.app')

@section('title', 'Nova Publicação Meta — Lead Certo')

@section('content')
<div class="p-6 max-w-7xl mx-auto space-y-6" x-data="metaPostForm()">

    {{-- Breadcrumb / Header --}}
    <div class="flex items-center justify-between border-b border-gray-200 pb-4 flex-wrap gap-4">
        <div>
            <div class="flex items-center gap-2 text-xs text-gray-500 mb-1">
                <a href="{{ route('meta-posts.index') }}" class="hover:underline">Postagens Meta</a>
                <span>/</span>
                <span class="text-gray-700">Nova Publicação</span>
            </div>
            <h1 class="text-2xl font-bold text-gray-900 font-heading">Criar & Agendar Publicação (Facebook & Instagram)</h1>
            <p class="text-xs text-gray-500 mt-0.5">Crie postagens com fotos, botões rastreados de WhatsApp e automação de comentários (Comment-to-DM).</p>
        </div>
        <div class="flex items-center gap-2">
            <a href="{{ route('meta-posts.index') }}" class="px-4 py-2 bg-gray-100 hover:bg-gray-200 text-gray-700 text-sm font-medium rounded-lg transition">
                ← Voltar ao Calendário
            </a>
        </div>
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

    {{-- Caixa Mágica: Assistente de Copywriting com IA --}}
    <div class="bg-gradient-to-r from-purple-50 via-indigo-50 to-blue-50 border border-purple-200 rounded-2xl p-6 shadow-sm">
        <div class="flex items-start justify-between flex-wrap gap-3">
            <div class="flex items-center gap-3">
                <div class="p-2.5 bg-purple-600 text-white rounded-xl shadow-md">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/>
                    </svg>
                </div>
                <div>
                    <h2 class="text-lg font-bold text-gray-900 font-heading">Assistente de Copywriting com IA</h2>
                    <p class="text-xs text-gray-600">Gere textos de alta conversão para Facebook e Instagram com chamada estratégica para o comentário em 1 clique.</p>
                </div>
            </div>
            <span class="text-xs font-semibold px-3 py-1 bg-purple-100 text-purple-800 rounded-full border border-purple-200">
                ✨ Otimizado para Fretes, Mudanças & Comentários
            </span>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mt-5">
            <div>
                <label class="block text-xs font-semibold text-gray-700 mb-1">Objetivo da Postagem</label>
                <select x-model="iaObjetivo" class="w-full text-sm border-gray-300 rounded-lg bg-white focus:ring-purple-500 focus:border-purple-500">
                    <option value="Atrair novos clientes para fretes e mudanças no RJ">Atrair Novos Clientes (Geral)</option>
                    <option value="Divulgar promoção exclusiva ou desconto especial de frete">Promoção / Desconto Especial</option>
                    <option value="Destacar agilidade, cuidado com os móveis e frota própria">Prova Social & Confiança</option>
                    <option value="Fretes rápidos para fins de semana e feriados no Rio">Urgência / Fim de Semana</option>
                    <option value="Mudanças comerciais e residenciais completas">Mudança Completa (Residencial/Comercial)</option>
                </select>
            </div>

            <div>
                <label class="block text-xs font-semibold text-gray-700 mb-1">Palavra-Chave do Comentário</label>
                <input type="text" x-model="iaPalavra" placeholder="Ex: QUERO, FRETE, ORÇAMENTO" class="w-full text-sm border-gray-300 rounded-lg bg-white focus:ring-purple-500 focus:border-purple-500 uppercase">
            </div>

            <div>
                <label class="block text-xs font-semibold text-gray-700 mb-1">Instrução / Bairro / Tema (Opcional)</label>
                <input type="text" x-model="iaTema" placeholder="Ex: Barra, Recreio, Zona Sul, desmontagem inclusa..." class="w-full text-sm border-gray-300 rounded-lg bg-white focus:ring-purple-500 focus:border-purple-500">
            </div>

            <div class="flex items-end">
                <button type="button" @click="gerarComIa()" :disabled="carregandoIa" class="w-full py-2.5 px-4 bg-purple-600 hover:bg-purple-700 disabled:opacity-50 text-white text-sm font-semibold rounded-lg shadow transition flex items-center justify-center gap-2">
                    <template x-if="!carregandoIa">
                        <span class="flex items-center gap-1.5">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19.428 15.428a2 2 0 00-1.022-.547l-2.387-.477a6 6 0 00-3.86.517l-.318.158a6 6 0 01-3.86.517L6.05 15.21a2 2 0 00-1.806.547M8 4h8l-1 1v5.172a2 2 0 00.586 1.414l5 5c1.26 1.26.367 3.414-1.415 3.414H4.828c-1.782 0-2.674-2.154-1.414-3.414l5-5A2 2 0 009 10.172V5L8 4z"/></svg>
                            Gerar Copy com IA
                        </span>
                    </template>
                    <template x-if="carregandoIa">
                        <span class="flex items-center gap-1.5">
                            <svg class="animate-spin w-4 h-4" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8H4z"></path></svg>
                            Criando Copy & Direct...
                        </span>
                    </template>
                </button>
            </div>
        </div>

        <div x-show="dicaEngajamento" x-transition class="mt-4 p-3 bg-purple-100/70 border border-purple-200 rounded-lg text-xs text-purple-900 flex items-center gap-2">
            <span class="font-bold">💡 Estratégia de Conversão:</span>
            <span x-text="dicaEngajamento"></span>
        </div>
    </div>

    {{-- Grid Principal: Formulário (7 Cols) + Live Preview em Tempo Real (5 Cols) --}}
    <div class="grid grid-cols-1 lg:grid-cols-12 gap-8 items-start">

        {{-- Lado Esquerdo: Formulário de Configuração (7 Colunas) --}}
        <div class="lg:col-span-7 bg-white p-6 rounded-2xl border border-gray-200 shadow-sm space-y-6">
            <form method="POST" action="{{ route('meta-posts.store') }}" enctype="multipart/form-data" id="formMetaPost">
                @csrf
                <input type="hidden" name="imagem_url" :value="imagemUrl">
                <input type="hidden" name="meta_post_conteudo_id" :value="conteudoId">

                <div class="space-y-6">

                    @if($conteudosSalvos->isNotEmpty())
                    <div class="p-3 bg-indigo-50 border border-indigo-200 rounded-xl flex items-center justify-between gap-3">
                        <p class="text-xs text-indigo-900">
                            <span class="font-bold">📚 Banco de Conteúdos:</span> comece a partir de um conteúdo já salvo.
                        </p>
                        <button type="button" @click="modalConteudos = true" class="text-xs bg-indigo-600 hover:bg-indigo-700 text-white px-3 py-1.5 rounded-lg font-bold transition shadow-sm whitespace-nowrap">
                            Usar Conteúdo Salvo
                        </button>
                    </div>
                    @endif

                    {{-- 1. Canal de Divulgação --}}
                    <div>
                        <label class="block text-xs font-bold text-gray-700 uppercase tracking-wider mb-2">1. Onde Publicar *</label>
                        <div class="grid grid-cols-3 gap-3">
                            <label :class="canal === 'facebook' ? 'border-blue-600 bg-blue-50/70 text-blue-700 font-bold shadow-sm' : 'border-gray-200 hover:bg-gray-50 text-gray-700'" class="border rounded-xl p-3 flex flex-col items-center justify-center gap-1.5 cursor-pointer transition text-center">
                                <input type="radio" name="canal_alvo" value="facebook" x-model="canal" class="sr-only">
                                <span class="text-xl">📘</span>
                                <span class="text-xs font-bold">Facebook</span>
                            </label>
                            <label :class="canal === 'instagram' ? 'border-pink-600 bg-pink-50/70 text-pink-700 font-bold shadow-sm' : 'border-gray-200 hover:bg-gray-50 text-gray-700'" class="border rounded-xl p-3 flex flex-col items-center justify-center gap-1.5 cursor-pointer transition text-center">
                                <input type="radio" name="canal_alvo" value="instagram" x-model="canal" class="sr-only">
                                <span class="text-xl">📷</span>
                                <span class="text-xs font-bold">Instagram</span>
                            </label>
                            <label :class="canal === 'ambos' ? 'border-purple-600 bg-purple-50/70 text-purple-700 font-bold shadow-sm' : 'border-gray-200 hover:bg-gray-50 text-gray-700'" class="border rounded-xl p-3 flex flex-col items-center justify-center gap-1.5 cursor-pointer transition text-center">
                                <input type="radio" name="canal_alvo" value="ambos" x-model="canal" class="sr-only">
                                <span class="text-xl">✨</span>
                                <span class="text-xs font-bold">Ambos (FB + IG)</span>
                            </label>
                        </div>
                    </div>

                    {{-- Seleção de Contas (FB & IG) --}}
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div x-show="canal === 'facebook' || canal === 'ambos'" x-transition class="space-y-1">
                            <label class="block text-xs font-bold text-gray-700 uppercase tracking-wider">Página do Facebook *</label>
                            <select name="meta_pagina_id" x-model="metaPaginaId" class="w-full border border-gray-300 rounded-xl px-3 py-2 text-sm focus:ring-2 focus:ring-green-500 focus:border-green-500 outline-none">
                                <option value="">Selecione a Página...</option>
                                @foreach($paginas as $pagina)
                                    @php $selPag = (old('meta_pagina_id') == $pagina->id) || ($paginas->count() === 1); @endphp
                                    <option value="{{ $pagina->id }}" {{ $selPag ? 'selected' : '' }}>
                                        {{ $pagina->nome }} ({{ $pagina->facebook_page_id }})
                                    </option>
                                @endforeach
                            </select>
                        </div>

                        <div x-show="canal === 'instagram' || canal === 'ambos'" x-transition class="space-y-1">
                            <label class="block text-xs font-bold text-gray-700 uppercase tracking-wider">Conta do Instagram *</label>
                            <select name="meta_conta_instagram_id" x-model="metaContaIgId" class="w-full border border-gray-300 rounded-xl px-3 py-2 text-sm focus:ring-2 focus:ring-green-500 focus:border-green-500 outline-none">
                                <option value="">Selecione o Instagram...</option>
                                @foreach($contasInstagram as $conta)
                                    @php $selIg = (old('meta_conta_instagram_id') == $conta->id) || ($contasInstagram->count() === 1); @endphp
                                    <option value="{{ $conta->id }}" {{ $selIg ? 'selected' : '' }}>
                                        @php echo '@' . htmlspecialchars($conta->username); @endphp ({{ $conta->nome ?? 'Frete Rio' }})
                                    </option>
                                @endforeach
                            </select>
                        </div>
                    </div>

                    {{-- 2. Texto / Legenda da Publicação --}}
                    <div>
                        <div class="flex justify-between items-center mb-1.5 flex-wrap gap-2">
                            <label class="block text-xs font-bold text-gray-700 uppercase tracking-wider">2. Legenda / Texto da Publicação *</label>
                            <div class="flex items-center gap-2">
                                <button type="button" @click="modalTemplates = true" class="text-xs text-blue-600 hover:text-blue-800 font-semibold flex items-center gap-1">
                                    <span>📝</span> Banco de Textos
                                </button>
                                <span class="text-gray-300">|</span>
                                <button type="button" @click="adicionarHashtags()" class="text-xs text-purple-600 hover:text-purple-800 font-semibold flex items-center gap-1">
                                    <span>🏷️</span> Inserir #Hashtags
                                </button>
                                <span class="text-xs text-gray-400 font-mono ml-2" :class="texto.length > 2100 ? 'text-red-500 font-bold' : ''" x-text="texto.length + '/2200 car.'"></span>
                            </div>
                        </div>
                        <textarea name="texto" x-model="texto" rows="6" required placeholder="Escreva a mensagem para prender a atenção do cliente e chamar para o comentário..." class="w-full text-sm border-gray-300 rounded-xl focus:ring-green-500 focus:border-green-500"></textarea>
                    </div>

                    {{-- 3. Imagem da Publicação --}}
                    <div class="p-4 bg-gray-50 rounded-xl border border-gray-200 space-y-3">
                        <div class="flex items-center justify-between flex-wrap gap-2">
                            <label class="block text-xs font-bold text-gray-800 uppercase tracking-wider">3. 📷 Imagem da Publicação</label>
                            <button type="button" @click="modalImagens = true" class="text-xs bg-green-100 hover:bg-green-200 text-green-800 px-3 py-1 rounded-lg font-bold flex items-center gap-1 transition shadow-sm">
                                <span>🖼️</span> Banco de Imagens da Empresa
                            </button>
                        </div>

                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                            <div>
                                <label class="block text-xs text-gray-600 mb-1">Enviar do Computador</label>
                                <input type="file" name="imagem" accept="image/*" @change="carregarImagemLocal($event)" class="w-full text-xs text-gray-700 file:mr-2 file:py-1.5 file:px-3 file:rounded-lg file:border-0 file:text-xs file:font-semibold file:bg-green-100 file:text-green-800 hover:file:bg-green-200">
                            </div>
                            <div>
                                <label class="block text-xs text-gray-600 mb-1">Ou colar URL da Imagem</label>
                                <input type="url" x-model="imagemUrl" placeholder="https://..." class="w-full text-xs border-gray-300 rounded-lg bg-white">
                            </div>
                        </div>

                        <template x-if="imagemUrl">
                            <div class="flex items-center justify-between pt-1 text-xs text-gray-600 border-t border-gray-200">
                                <span class="truncate max-w-xs text-green-700 font-medium">✓ Imagem carregada para publicação</span>
                                <button type="button" @click="imagemUrl = ''" class="text-red-500 hover:underline">Remover imagem</button>
                            </div>
                        </template>
                    </div>

                    {{-- 4. Botão de Ação CTA (Facebook) --}}
                    <div x-show="canal !== 'instagram'" x-transition class="p-4 bg-blue-50/50 border border-blue-200 rounded-xl space-y-3">
                        <div class="flex items-center justify-between">
                            <label class="block text-xs font-bold text-blue-900 uppercase tracking-wider">4. 🔘 Botão de Ação do Feed (Facebook)</label>
                            <button type="button" @click="aplicarWhatsAppPadrao()" class="text-xs text-blue-700 hover:underline font-semibold">
                                Restaurar WhatsApp Padrão
                            </button>
                        </div>
                        
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                            <div>
                                <label class="block text-xs text-gray-700 mb-1 font-medium">Tipo do Botão</label>
                                <select name="cta_tipo" x-model="ctaTipo" class="w-full text-sm border-gray-300 rounded-lg bg-white">
                                    <option value="LEARN_MORE">Saiba Mais / Chamar no WhatsApp (Padrão)</option>
                                    <option value="BOOK">Agendar Agora</option>
                                    <option value="ORDER">Fazer Pedido / Solicitar</option>
                                    <option value="SIGN_UP">Cadastre-se</option>
                                    <option value="CALL">Ligar</option>
                                    <option value="NENHUM">Sem Botão</option>
                                </select>
                            </div>
                            <div x-show="ctaTipo !== 'NENHUM'">
                                <label class="block text-xs text-gray-700 mb-1 font-medium">Link de Destino (WhatsApp)</label>
                                <input type="url" name="cta_url" x-model="ctaUrl" placeholder="https://api.whatsapp.com/..." class="w-full text-sm border-gray-300 rounded-lg bg-white">
                            </div>
                        </div>
                        <p class="text-[11px] text-blue-700">
                            💡 No Facebook, o botão aparece diretamente abaixo da foto convidando para o WhatsApp da Frete Rio. No Instagram, a conversão é feita pela chamada nos comentários (Comment-to-DM).
                        </p>
                    </div>

                    {{-- 5. Automação de Comentários (Comment-to-DM) --}}
                    <div class="p-4 bg-gradient-to-r from-emerald-50 via-teal-50 to-green-50 border border-green-200 rounded-xl space-y-4 shadow-sm">
                        <div class="flex items-center justify-between flex-wrap gap-2">
                            <div class="flex items-center gap-2">
                                <span class="text-lg">💬</span>
                                <div>
                                    <h4 class="text-xs font-bold text-gray-900 uppercase tracking-wider">5. Automação de Comentários (Comment-to-DM) & Lucanban</h4>
                                    <p class="text-[11px] text-gray-600">Quem comentar recebe resposta pública imediata e o link no Direct, virando oportunidade no Lucanban.</p>
                                </div>
                            </div>
                            <span class="text-[11px] font-bold bg-green-600 text-white px-2.5 py-1 rounded-md shadow-xs">Alta Conversão</span>
                        </div>

                        <div>
                            <label class="block text-xs font-semibold text-gray-700 mb-1.5">Modo do Gatilho *</label>
                            <div class="grid grid-cols-1 sm:grid-cols-3 gap-2">
                                <label :class="gatilho === 'palavra_chave' ? 'border-green-600 bg-white font-bold text-green-800 ring-2 ring-green-500' : 'border-gray-200 bg-white/70 text-gray-700 hover:bg-white'" class="border rounded-xl p-2.5 cursor-pointer text-xs flex items-center gap-2 transition">
                                    <input type="radio" name="modo_gatilho" value="palavra_chave" x-model="gatilho" class="sr-only">
                                    <span>🔑</span>
                                    <span>Palavra-Chave Específica (Recomendado)</span>
                                </label>
                                <label :class="gatilho === 'qualquer_comentario' ? 'border-green-600 bg-white font-bold text-green-800 ring-2 ring-green-500' : 'border-gray-200 bg-white/70 text-gray-700 hover:bg-white'" class="border rounded-xl p-2.5 cursor-pointer text-xs flex items-center gap-2 transition">
                                    <input type="radio" name="modo_gatilho" value="qualquer_comentario" x-model="gatilho" class="sr-only">
                                    <span>⚡</span>
                                    <span>Qualquer Comentário</span>
                                </label>
                                <label :class="gatilho === 'nenhum' ? 'border-gray-400 bg-white font-bold text-gray-800' : 'border-gray-200 bg-white/70 text-gray-700 hover:bg-white'" class="border rounded-xl p-2.5 cursor-pointer text-xs flex items-center gap-2 transition">
                                    <input type="radio" name="modo_gatilho" value="nenhum" x-model="gatilho" class="sr-only">
                                    <span>🚫</span>
                                    <span>Desativado</span>
                                </label>
                            </div>
                        </div>

                        <div x-show="gatilho !== 'nenhum'" x-transition class="space-y-3 pt-2 border-t border-green-200/60">
                            <div x-show="gatilho === 'palavra_chave'">
                                <label class="block text-xs font-semibold text-gray-700 mb-1">Palavras-Chave de Ativação (separadas por vírgula)</label>
                                <input type="text" name="palavras_chave_texto" x-model="palavrasChave" placeholder="QUERO, FRETE, ORÇAMENTO, VALOR, SIM" class="w-full text-sm border-gray-300 rounded-lg bg-white uppercase font-mono">
                                <div class="flex items-center gap-1.5 mt-1 text-[11px] text-gray-500">
                                    <span>Sugestões rápidas:</span>
                                    <button type="button" @click="palavrasChave = 'QUERO, FRETE, ORÇAMENTO'" class="text-green-700 underline font-semibold">QUERO, FRETE, ORÇAMENTO</button>
                                </div>
                            </div>

                            <div>
                                <label class="block text-xs font-semibold text-gray-700 mb-1">Resposta Pública ao Comentário</label>
                                <input type="text" name="resposta_publica_comentario" x-model="respostaPublica" placeholder="Ex: Acabei de te enviar no direct os detalhes e valores com desconto! 🚚✨ Dá uma olhadinha lá!" class="w-full text-sm border-gray-300 rounded-lg bg-white">
                                <p class="text-[11px] text-gray-500 mt-0.5">Essa resposta será comentada publicamente pelo perfil da empresa logo após a pessoa interagir.</p>
                            </div>

                            <div>
                                <label class="block text-xs font-semibold text-gray-700 mb-1">Mensagem Privada no Direct (com Link do WhatsApp)</label>
                                <textarea name="mensagem_direct" x-model="mensagemDirect" rows="3" placeholder="Mensagem acolhedora que cairá no Direct do cliente com o link do WhatsApp..." class="w-full text-sm border-gray-300 rounded-lg bg-white"></textarea>
                                <div class="flex items-center justify-between text-[11px] text-gray-500 mt-0.5">
                                    <span>O lead que receber o direct entra automaticamente no fluxo do Lucanban.</span>
                                    <button type="button" @click="inserirLinkDirect()" class="text-green-700 font-semibold hover:underline">
                                        + Inserir Link WhatsApp
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>

                    {{-- 6. Agendamento e Data/Hora --}}
                    <div class="pt-2 border-t border-gray-100 space-y-3">
                        <div class="flex items-center justify-between">
                            <label class="text-xs font-bold text-gray-700 uppercase tracking-wider">6. Programação da Postagem *</label>
                            <label class="flex items-center gap-2 cursor-pointer text-xs font-semibold text-gray-700 bg-gray-50 hover:bg-gray-100 px-3 py-1.5 rounded-lg border border-gray-200">
                                <input type="checkbox" name="publicar_imediato" value="1" x-model="publicarImediato" class="rounded text-green-600 focus:ring-green-500">
                                Publicar Imediatamente
                            </label>
                        </div>

                        <div x-show="!publicarImediato" x-transition>
                            <input type="datetime-local" name="data_agendada" x-model="dataAgendada" class="w-full text-sm border-gray-300 rounded-lg">
                            <p class="text-xs text-gray-400 mt-1">O robô de publicação da Lead Certo enviará o post para os canais Meta selecionados na data e horário configurados.</p>
                        </div>
                    </div>

                    {{-- Botões de Submissão --}}
                    <div class="pt-4 border-t border-gray-100 flex items-center justify-end gap-3">
                        <a href="{{ route('meta-posts.index') }}" class="px-5 py-2.5 text-sm font-medium text-gray-600 hover:text-gray-800">
                            Cancelar
                        </a>
                        <button type="submit" class="px-6 py-2.5 bg-green-600 hover:bg-green-700 text-white text-sm font-bold rounded-xl shadow-md transition flex items-center gap-2">
                            <span x-text="publicarImediato ? '🚀 Publicar Agora no Facebook & Instagram' : '📅 Confirmar Agendamento'"></span>
                        </button>
                    </div>

                </div>
            </form>
        </div>

        {{-- Lado Direito: Live Preview em Tempo Real (5 Colunas) --}}
        <div class="lg:col-span-5 sticky top-6 space-y-4">
            
            {{-- Seletor de Abas de Preview (Facebook vs Instagram) --}}
            <div class="flex items-center justify-between bg-white p-1.5 rounded-xl border border-gray-200 shadow-sm">
                <div class="flex items-center gap-1">
                    <button type="button" @click="abaPreview = 'facebook'" :class="abaPreview === 'facebook' ? 'bg-blue-600 text-white font-bold' : 'text-gray-600 hover:bg-gray-100'" class="px-3 py-1.5 text-xs rounded-lg transition flex items-center gap-1.5">
                        <span>📘</span> Facebook Feed
                    </button>
                    <button type="button" @click="abaPreview = 'instagram'" :class="abaPreview === 'instagram' ? 'bg-pink-600 text-white font-bold' : 'text-gray-600 hover:bg-gray-100'" class="px-3 py-1.5 text-xs rounded-lg transition flex items-center gap-1.5">
                        <span>📷</span> Instagram Feed
                    </button>
                </div>
                <span class="text-[11px] font-bold text-green-700 bg-green-50 px-2 py-0.5 rounded border border-green-200">
                    Live Preview
                </span>
            </div>

            {{-- 1. Mockup Facebook --}}
            <div x-show="abaPreview === 'facebook'" x-transition class="bg-white rounded-2xl border border-gray-200 shadow-lg overflow-hidden max-w-sm mx-auto">
                {{-- Header da Página --}}
                <div class="p-3.5 border-b border-gray-100 flex items-center justify-between">
                    <div class="flex items-center gap-2.5">
                        <div class="w-10 h-10 rounded-full bg-blue-600 text-white font-bold flex items-center justify-center text-sm shadow-sm">
                            FR
                        </div>
                        <div>
                            <p class="text-xs font-bold text-gray-900 leading-tight">Frete Rio</p>
                            <p class="text-[10px] text-gray-500 flex items-center gap-1">
                                <span>Agora</span> • <span>🌐</span>
                            </p>
                        </div>
                    </div>
                    <div class="text-gray-400 text-xs">•••</div>
                </div>

                {{-- Texto do Post --}}
                <div class="p-3.5 text-xs text-gray-800 whitespace-pre-line leading-relaxed" x-text="texto || 'O texto da publicação aparecerá aqui em tempo real...' ">
                </div>

                {{-- Imagem do Post --}}
                <template x-if="imagemUrl">
                    <img :src="imagemUrl" class="w-full h-52 object-cover bg-gray-100">
                </template>
                <template x-if="!imagemUrl">
                    <div class="w-full h-44 bg-gray-100 flex flex-col items-center justify-center text-gray-400 text-xs p-4 text-center">
                        <svg class="w-8 h-8 mb-1 opacity-50" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
                        Insira ou selecione uma imagem para ver a prévia
                    </div>
                </template>

                {{-- Faixa de CTA do Facebook --}}
                <template x-if="ctaTipo !== 'NENHUM'">
                    <div class="p-3 bg-gray-50 border-t border-gray-100 flex items-center justify-between">
                        <div class="truncate mr-2">
                            <p class="text-[10px] text-gray-500 uppercase tracking-wide">api.whatsapp.com</p>
                            <p class="text-xs font-bold text-gray-900 truncate">Fale com a Frete Rio no WhatsApp</p>
                        </div>
                        <button type="button" class="px-3 py-1.5 bg-gray-200 hover:bg-gray-300 text-gray-800 font-semibold text-xs rounded-lg transition whitespace-nowrap">
                            <span x-text="labelCta(ctaTipo)"></span>
                        </button>
                    </div>
                </template>

                {{-- Footer de Interações --}}
                <div class="px-4 py-2 bg-white border-t border-gray-100 flex items-center justify-between text-xs text-gray-500">
                    <span class="flex items-center gap-1">👍 💬 14 curtidas</span>
                    <span>3 comentários</span>
                </div>
            </div>

            {{-- 2. Mockup Instagram --}}
            <div x-show="abaPreview === 'instagram'" x-transition class="bg-white rounded-2xl border border-gray-200 shadow-lg overflow-hidden max-w-sm mx-auto">
                {{-- Topo da Conta --}}
                <div class="p-3 border-b border-gray-100 flex items-center justify-between">
                    <div class="flex items-center gap-2.5">
                        <div class="w-8 h-8 rounded-full bg-gradient-to-tr from-amber-500 via-pink-600 to-purple-600 p-[1.5px]">
                            <div class="w-full h-full bg-white rounded-full flex items-center justify-center text-[10px] font-bold text-gray-800">
                                FR
                            </div>
                        </div>
                        <span class="text-xs font-bold text-gray-900">frete.rio.br</span>
                    </div>
                    <div class="text-gray-500 text-xs font-bold">•••</div>
                </div>

                {{-- Foto Instagram (Quadrada) --}}
                <template x-if="imagemUrl">
                    <img :src="imagemUrl" class="w-full h-64 object-cover bg-gray-100">
                </template>
                <template x-if="!imagemUrl">
                    <div class="w-full h-56 bg-gray-100 flex flex-col items-center justify-center text-gray-400 text-xs p-4 text-center">
                        <svg class="w-8 h-8 mb-1 opacity-50" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
                        Insira ou escolha uma imagem
                    </div>
                </template>

                {{-- Ícones do Instagram --}}
                <div class="p-3 pb-1 flex items-center justify-between text-gray-800">
                    <div class="flex items-center gap-3 text-lg">
                        <span>❤️</span>
                        <span>💬</span>
                        <span>✈️</span>
                    </div>
                    <span class="text-lg">🔖</span>
                </div>

                {{-- Legenda Instagram --}}
                <div class="p-3 pt-1 text-xs text-gray-800 leading-relaxed">
                    <p class="whitespace-pre-line">
                        <strong class="font-bold mr-1">frete.rio.br</strong>
                        <span x-text="texto || 'Sua legenda do Instagram aparecerá aqui com emojis e hashtags...' "></span>
                    </p>
                    <p class="text-[10px] text-gray-400 uppercase mt-2">HÁ 2 MINUTOS</p>
                </div>
            </div>

            {{-- 3. Demonstração Interativa do Fluxo de Comentário (Comment-to-DM) --}}
            <div x-show="gatilho !== 'nenhum'" x-transition class="bg-gradient-to-br from-emerald-50 to-teal-50 rounded-2xl border border-emerald-200 p-4 shadow-sm space-y-3">
                <div class="flex items-center justify-between">
                    <span class="text-[11px] font-bold text-emerald-900 uppercase tracking-wider flex items-center gap-1.5">
                        <span>⚡</span> Simulação: Comentário → Direct → Kanban
                    </span>
                    <span class="text-[10px] bg-emerald-200 text-emerald-900 px-2 py-0.5 rounded font-bold">Automático</span>
                </div>

                <div class="space-y-2 text-xs">
                    {{-- 1. Lead comenta --}}
                    <div class="bg-white p-2.5 rounded-xl border border-emerald-100 shadow-xs flex items-start gap-2">
                        <div class="w-6 h-6 rounded-full bg-gray-300 text-[10px] font-bold flex items-center justify-center flex-shrink-0">
                            CL
                        </div>
                        <div>
                            <p class="font-bold text-gray-900 text-[11px]">Cliente Potencial <span class="font-normal text-gray-400 text-[10px]">comentou no post:</span></p>
                            <p class="text-emerald-700 font-bold bg-emerald-50 px-1.5 py-0.5 rounded inline-block mt-0.5" x-text="palavrasChave ? palavrasChave.split(',')[0].trim() : 'QUERO'"></p>
                        </div>
                    </div>

                    {{-- 2. Página responde em público --}}
                    <div class="bg-white/80 p-2.5 rounded-xl border border-emerald-100 shadow-xs flex items-start gap-2 ml-4">
                        <div class="w-6 h-6 rounded-full bg-green-600 text-white text-[9px] font-bold flex items-center justify-center flex-shrink-0">
                            FR
                        </div>
                        <div>
                            <p class="font-bold text-gray-900 text-[11px]">Frete Rio <span class="font-normal text-gray-400 text-[10px]">respondeu:</span></p>
                            <p class="text-gray-700 text-[11px] mt-0.5" x-text="respostaPublica || 'Acabei de te enviar no direct os detalhes! 🚚✨'"></p>
                        </div>
                    </div>

                    {{-- 3. Mensagem privada no Direct --}}
                    <div class="bg-emerald-600 text-white p-2.5 rounded-xl shadow-xs text-[11px] space-y-1">
                        <div class="flex items-center justify-between text-[10px] font-bold text-emerald-100 border-b border-emerald-500/50 pb-1">
                            <span>📩 Direct Enviado Instantaneamente</span>
                            <span>WhatsApp Conectado</span>
                        </div>
                        <p class="leading-relaxed whitespace-pre-line" x-text="mensagemDirect || 'Olá! Segue o link para fecharmos no WhatsApp...'"></p>
                    </div>
                </div>
            </div>

        </div>

    </div>

    {{-- MODAL 1: BANCO DE IMAGENS DA EMPRESA --}}
    <div x-show="modalImagens" style="display: none;" class="fixed inset-0 z-50 overflow-y-auto" aria-labelledby="modal-title" role="dialog" aria-modal="true">
        <div class="flex items-center justify-center min-h-screen pt-4 px-4 pb-20 text-center sm:block sm:p-0">
            <div x-show="modalImagens" @click="modalImagens = false" class="fixed inset-0 bg-gray-900/60 backdrop-blur-xs transition-opacity"></div>
            <span class="hidden sm:inline-block sm:align-middle sm:h-screen" aria-hidden="true">&#8203;</span>

            <div x-show="modalImagens" class="inline-block align-bottom bg-white rounded-2xl text-left overflow-hidden shadow-2xl transform transition-all sm:my-8 sm:align-middle sm:max-w-4xl sm:w-full border border-gray-200">
                <div class="bg-white px-6 pt-5 pb-4 sm:p-6 sm:pb-4 border-b border-gray-100 flex items-center justify-between">
                    <div>
                        <h3 class="text-lg font-bold text-gray-900">🖼️ Banco de Imagens da Empresa</h3>
                        <p class="text-xs text-gray-500">Selecione uma das fotos reais do acervo da Frete Rio para utilizar na postagem.</p>
                    </div>
                    <div class="flex items-center gap-2">
                        <a href="{{ route('admin.gmb-posts.imagens') }}" target="_blank" class="text-xs text-green-700 hover:underline font-semibold flex items-center gap-1">
                            + Gerenciar Galeria
                        </a>
                        <button type="button" @click="modalImagens = false" class="text-gray-400 hover:text-gray-600 text-lg font-bold">✕</button>
                    </div>
                </div>

                <div class="p-6 max-h-[65vh] overflow-y-auto">
                    @if(isset($imagensGaleria) && $imagensGaleria->isNotEmpty())
                        <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 gap-4">
                            @foreach($imagensGaleria as $foto)
                                <div @click="escolherImagem('{{ $foto->imagem_url }}')" class="group relative rounded-xl overflow-hidden border border-gray-200 hover:border-green-500 cursor-pointer shadow-sm hover:shadow-md transition">
                                    <img src="{{ $foto->imagem_url }}" alt="{{ $foto->titulo }}" class="w-full h-32 object-cover group-hover:scale-105 transition duration-300">
                                    <div class="p-2 bg-white text-[11px] truncate font-medium text-gray-700">
                                        {{ $foto->titulo ?: 'Foto Frete Rio' }}
                                    </div>
                                    <div class="absolute inset-0 bg-green-600/20 opacity-0 group-hover:opacity-100 transition flex items-center justify-center">
                                        <span class="bg-green-600 text-white text-xs font-bold px-2 py-1 rounded-md shadow">Usar Foto</span>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    @else
                        <div class="text-center py-10 text-gray-500">
                            <p class="text-sm">Nenhuma imagem cadastrada no banco de imagens ainda.</p>
                            <a href="{{ route('admin.gmb-posts.imagens') }}" class="inline-block mt-3 px-4 py-2 bg-green-600 text-white rounded-lg text-xs font-bold">Fazer Upload de Fotos</a>
                        </div>
                    @endif
                </div>

                <div class="bg-gray-50 px-4 py-3 sm:px-6 sm:flex sm:flex-row-reverse border-t border-gray-100">
                    <button type="button" @click="modalImagens = false" class="w-full inline-flex justify-center rounded-lg border border-gray-300 shadow-sm px-4 py-2 bg-white text-sm font-medium text-gray-700 hover:bg-gray-50 sm:w-auto">
                        Fechar
                    </button>
                </div>
            </div>
        </div>
    </div>

    {{-- MODAL 2: BANCO DE TEXTOS / TEMPLATES --}}
    <div x-show="modalTemplates" style="display: none;" class="fixed inset-0 z-50 overflow-y-auto" aria-labelledby="modal-title" role="dialog" aria-modal="true">
        <div class="flex items-center justify-center min-h-screen pt-4 px-4 pb-20 text-center sm:block sm:p-0">
            <div x-show="modalTemplates" @click="modalTemplates = false" class="fixed inset-0 bg-gray-900/60 backdrop-blur-xs transition-opacity"></div>
            <span class="hidden sm:inline-block sm:align-middle sm:h-screen" aria-hidden="true">&#8203;</span>

            <div x-show="modalTemplates" class="inline-block align-bottom bg-white rounded-2xl text-left overflow-hidden shadow-2xl transform transition-all sm:my-8 sm:align-middle sm:max-w-4xl sm:w-full border border-gray-200">
                <div class="bg-white px-6 pt-5 pb-4 sm:p-6 sm:pb-4 border-b border-gray-100 flex items-center justify-between">
                    <div>
                        <h3 class="text-lg font-bold text-gray-900">📝 Banco de Textos & Templates</h3>
                        <p class="text-xs text-gray-500">Selecione um dos modelos prontos para preencher sua legenda instantaneamente.</p>
                    </div>
                    <div class="flex items-center gap-2">
                        <a href="{{ route('admin.gmb-posts.templates') }}" target="_blank" class="text-xs text-green-700 hover:underline font-semibold flex items-center gap-1">
                            + Gerenciar Templates
                        </a>
                        <button type="button" @click="modalTemplates = false" class="text-gray-400 hover:text-gray-600 text-lg font-bold">✕</button>
                    </div>
                </div>

                <div class="p-6 max-h-[65vh] overflow-y-auto space-y-4">
                    @if(isset($templatesTexto) && $templatesTexto->isNotEmpty())
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            @foreach($templatesTexto as $tpl)
                                <div class="p-4 border border-gray-200 rounded-xl hover:border-green-500 transition space-y-2.5 bg-gray-50/50 flex flex-col justify-between">
                                    <div>
                                        <div class="flex items-center justify-between">
                                            <span class="text-xs font-bold text-gray-900">{{ $tpl->titulo_template }}</span>
                                            <span class="text-[10px] bg-gray-200 text-gray-700 px-2 py-0.5 rounded font-mono uppercase">{{ $tpl->categoria }}</span>
                                        </div>
                                        <p class="text-xs text-gray-600 mt-2 line-clamp-4 whitespace-pre-line leading-relaxed">
                                            {{ $tpl->texto_template }}
                                        </p>
                                    </div>
                                    <button type="button" @click="aplicarTemplate(@js($tpl->texto_template))" class="w-full py-1.5 bg-green-600 hover:bg-green-700 text-white rounded-lg text-xs font-bold transition shadow-xs">
                                        Usar este Template
                                    </button>
                                </div>
                            @endforeach
                        </div>
                    @else
                        {{-- Modelos Padrão Fallback para Frete Rio --}}
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div class="p-4 border border-gray-200 rounded-xl hover:border-green-500 transition space-y-2.5 bg-gray-50/50 flex flex-col justify-between">
                                <div>
                                    <div class="flex items-center justify-between">
                                        <span class="text-xs font-bold text-gray-900">Frete Rápido com Orçamento Imediato</span>
                                        <span class="text-[10px] bg-green-100 text-green-800 px-2 py-0.5 rounded font-bold">RECOMENDADO</span>
                                    </div>
                                    <p class="text-xs text-gray-600 mt-2 whitespace-pre-line leading-relaxed">
                                        🚚 Precisa transportar móveis ou fazer sua mudança no Rio sem estresse? 

Aqui na Frete Rio cuidamos de tudo com embalagem protetora, pontualidade e veículos adequados!

👉 Comente "QUERO" aqui embaixo que te envio nosso orçamento com desconto direto no Direct agora mesmo! ⚡

#FreteRJ #MudançaRio #TransporteRJ
                                    </p>
                                </div>
                                <button type="button" @click="aplicarTemplate('🚚 Precisa transportar móveis ou fazer sua mudança no Rio sem estresse?\n\nAqui na Frete Rio cuidamos de tudo com embalagem protetora, pontualidade e veículos adequados!\n\n👉 Comente \"QUERO\" aqui embaixo que te envio nosso orçamento com desconto direto no Direct agora mesmo! ⚡\n\n#FreteRJ #MudançaRio #TransporteRJ')" class="w-full py-1.5 bg-green-600 hover:bg-green-700 text-white rounded-lg text-xs font-bold transition shadow-xs">
                                    Usar este Template
                                </button>
                            </div>

                            <div class="p-4 border border-gray-200 rounded-xl hover:border-green-500 transition space-y-2.5 bg-gray-50/50 flex flex-col justify-between">
                                <div>
                                    <div class="flex items-center justify-between">
                                        <span class="text-xs font-bold text-gray-900">Mudança Residencial Completa</span>
                                        <span class="text-[10px] bg-blue-100 text-blue-800 px-2 py-0.5 rounded font-bold">MUDANÇA</span>
                                    </div>
                                    <p class="text-xs text-gray-600 mt-2 whitespace-pre-line leading-relaxed">
                                        📦 Vai mudar de casa ou apartamento no Rio de Janeiro?

Deixe o peso com a gente! Nossa equipe é treinada para carregar e proteger cada item do seu patrimônio com todo o zelo que você merece.

💬 Comente "ORÇAMENTO" para receber a tabela de valores no seu Direct agora!

#MudancaRJ #FreteZonaSul #FreteBarra
                                    </p>
                                </div>
                                <button type="button" @click="aplicarTemplate('📦 Vai mudar de casa ou apartamento no Rio de Janeiro?\n\nDeixe o peso com a gente! Nossa equipe é treinada para carregar e proteger cada item do seu patrimônio com todo o zelo que você merece.\n\n💬 Comente \"ORÇAMENTO\" para receber a tabela de valores no seu Direct agora!\n\n#MudancaRJ #FreteZonaSul #FreteBarra')" class="w-full py-1.5 bg-green-600 hover:bg-green-700 text-white rounded-lg text-xs font-bold transition shadow-xs">
                                    Usar este Template
                                </button>
                            </div>
                        </div>
                    @endif
                </div>

                <div class="bg-gray-50 px-4 py-3 sm:px-6 sm:flex sm:flex-row-reverse border-t border-gray-100">
                    <button type="button" @click="modalTemplates = false" class="w-full inline-flex justify-center rounded-lg border border-gray-300 shadow-sm px-4 py-2 bg-white text-sm font-medium text-gray-700 hover:bg-gray-50 sm:w-auto">
                        Fechar
                    </button>
                </div>
            </div>
        </div>
    </div>

    {{-- MODAL 3: BANCO DE CONTEÚDOS (conteúdo completo: texto+imagem+CTA+gatilho de uma vez) --}}
    <div x-show="modalConteudos" style="display: none;" class="fixed inset-0 z-50 overflow-y-auto" aria-labelledby="modal-title" role="dialog" aria-modal="true">
        <div class="flex items-center justify-center min-h-screen pt-4 px-4 pb-20 text-center sm:block sm:p-0">
            <div x-show="modalConteudos" @click="modalConteudos = false" class="fixed inset-0 bg-gray-900/60 backdrop-blur-xs transition-opacity"></div>
            <span class="hidden sm:inline-block sm:align-middle sm:h-screen" aria-hidden="true">&#8203;</span>

            <div x-show="modalConteudos" class="inline-block align-bottom bg-white rounded-2xl text-left overflow-hidden shadow-2xl transform transition-all sm:my-8 sm:align-middle sm:max-w-4xl sm:w-full border border-gray-200">
                <div class="bg-white px-6 pt-5 pb-4 sm:p-6 sm:pb-4 border-b border-gray-100 flex items-center justify-between">
                    <div>
                        <h3 class="text-lg font-bold text-gray-900">📚 Banco de Conteúdos</h3>
                        <p class="text-xs text-gray-500">Escolha um conteúdo pronto — texto, imagem, botão e gatilho de comentário são preenchidos de uma vez.</p>
                    </div>
                    <div class="flex items-center gap-2">
                        <a href="{{ route('meta-posts.conteudos.index') }}" target="_blank" class="text-xs text-green-700 hover:underline font-semibold flex items-center gap-1">
                            + Gerenciar Conteúdos
                        </a>
                        <button type="button" @click="modalConteudos = false" class="text-gray-400 hover:text-gray-600 text-lg font-bold">✕</button>
                    </div>
                </div>

                <div class="p-6 max-h-[65vh] overflow-y-auto space-y-3">
                    @if($conteudosSalvos->isNotEmpty())
                        @foreach($conteudosSalvos as $conteudo)
                            <div @click="aplicarConteudo(@json($conteudo))" class="p-4 border border-gray-200 rounded-xl hover:border-indigo-500 transition cursor-pointer bg-gray-50/50">
                                <div class="flex items-center justify-between">
                                    <span class="text-xs font-bold text-gray-900">{{ $conteudo->titulo }}</span>
                                    <span class="text-[10px] bg-gray-200 text-gray-700 px-2 py-0.5 rounded font-mono uppercase">{{ $conteudo->categoria }}</span>
                                </div>
                                <p class="text-xs text-gray-600 mt-2 line-clamp-3 whitespace-pre-line leading-relaxed">{{ $conteudo->texto }}</p>
                            </div>
                        @endforeach
                    @else
                        <div class="text-center py-10 text-gray-500">
                            <p class="text-sm">Nenhum conteúdo salvo ainda.</p>
                            <a href="{{ route('meta-posts.conteudos.index') }}" class="inline-block mt-3 px-4 py-2 bg-indigo-600 text-white rounded-lg text-xs font-bold">Criar Conteúdo</a>
                        </div>
                    @endif
                </div>

                <div class="bg-gray-50 px-4 py-3 sm:px-6 sm:flex sm:flex-row-reverse border-t border-gray-100">
                    <button type="button" @click="modalConteudos = false" class="w-full inline-flex justify-center rounded-lg border border-gray-300 shadow-sm px-4 py-2 bg-white text-sm font-medium text-gray-700 hover:bg-gray-50 sm:w-auto">
                        Fechar
                    </button>
                </div>
            </div>
        </div>
    </div>

</div>

<script>
function metaPostForm() {
    return {
        canal: '{{ old('canal_alvo', 'ambos') }}',
        metaPaginaId: '{{ old('meta_pagina_id', $paginas->first()?->id ?? '') }}',
        metaContaIgId: '{{ old('meta_conta_instagram_id', $contasInstagram->first()?->id ?? '') }}',
        texto: @js(old('texto', "🚚 Precisa de Frete ou Mudança no Rio de Janeiro com agilidade e preço justo?\n\nNa Frete Rio cuidamos de tudo com pontualidade e proteção para seus pertences!\n\n👉 Comente \"QUERO\" aqui embaixo para receber nosso orçamento com desconto exclusivo no Direct! ⚡\n\n#FreteRJ #MudançaRio #TransporteRJ")),
        imagemUrl: @js(old('imagem_url', ($imagensGaleria->first()?->imagem_url ?? ''))),
        ctaTipo: '{{ old('cta_tipo', 'LEARN_MORE') }}',
        ctaUrl: @js(old('cta_url', 'https://api.whatsapp.com/send/?phone=5521981813106&text=Ol%C3%A1%2C+gostaria+de+um+or%C3%A7amento+de+frete+no+Rio+de+Janeiro%21+%28Vi+no+Facebook%29&type=phone_number&app_absent=0')),
        gatilho: '{{ old('modo_gatilho', 'palavra_chave') }}',
        palavrasChave: @js(old('palavras_chave_texto', 'QUERO, FRETE, ORÇAMENTO')),
        respostaPublica: @js(old('resposta_publica_comentario', 'Acabei de te enviar todos os detalhes e valores com desconto no seu Direct! 🚚✨ Dá uma olhadinha lá!')),
        mensagemDirect: @js(old('mensagem_direct', "Olá! Vi que você comentou no nosso post sobre fretes e mudanças! 📦🚚\n\nFale diretamente com nossa equipe no WhatsApp para tirar suas dúvidas e garantir seu desconto:\nhttps://api.whatsapp.com/send/?phone=5521981813106&text=Ol%C3%A1%2C+gostaria+de+um+or%C3%A7amento+de+frete+no+Rio+de+Janeiro%21+%28Vi+no+Instagram%29&type=phone_number&app_absent=0")),
        publicarImediato: {{ old('publicar_imediato', '0') == '1' ? 'true' : 'false' }},
        dataAgendada: '{{ old('data_agendada', now()->addHour()->format('Y-m-d\TH:i')) }}',
        abaPreview: 'facebook',
        modalImagens: false,
        modalTemplates: false,
        modalConteudos: false,
        conteudoId: @js(old('meta_post_conteudo_id', '')),

        // IA Assistant State
        iaObjetivo: 'Atrair novos clientes para fretes e mudanças no RJ',
        iaPalavra: 'QUERO',
        iaTema: '',
        carregandoIa: false,
        dicaEngajamento: '',

        labelCta(tipo) {
            const mapa = {
                'LEARN_MORE': 'Saiba Mais',
                'BOOK': 'Agendar',
                'ORDER': 'Fazer Pedido',
                'SIGN_UP': 'Cadastre-se',
                'CALL': 'Ligar',
                'SHOP': 'Comprar'
            };
            return mapa[tipo] || 'Saiba Mais';
        },

        aplicarWhatsAppPadrao() {
            this.ctaTipo = 'LEARN_MORE';
            this.ctaUrl = 'https://api.whatsapp.com/send/?phone=5521981813106&text=Ol%C3%A1%2C+gostaria+de+um+or%C3%A7amento+de+frete+no+Rio+de+Janeiro%21+%28Vi+no+Facebook%29&type=phone_number&app_absent=0';
        },

        inserirLinkDirect() {
            const link = 'https://api.whatsapp.com/send/?phone=5521981813106&text=Ol%C3%A1%2C+gostaria+de+um+or%C3%A7amento+de+frete+no+Rio+de+Janeiro%21+%28Vi+no+Direct%29&type=phone_number&app_absent=0';
            this.mensagemDirect += '\n\n' + link;
        },

        adicionarHashtags() {
            const tags = '\n\n#FreteRJ #MudançaRio #TransporteRJ #FreteBarra #FreteZonaSul';
            if (!this.texto.includes('#FreteRJ')) {
                this.texto += tags;
            }
        },

        carregarImagemLocal(e) {
            if (e.target.files && e.target.files[0]) {
                this.imagemUrl = URL.createObjectURL(e.target.files[0]);
            }
        },

        escolherImagem(url) {
            this.imagemUrl = url;
            this.modalImagens = false;
        },

        aplicarTemplate(texto) {
            this.texto = texto;
            this.modalTemplates = false;
        },

        aplicarConteudo(conteudo) {
            this.conteudoId = conteudo.id;
            this.texto = conteudo.texto;
            this.imagemUrl = conteudo.imagem_url || this.imagemUrl;
            this.ctaTipo = conteudo.cta_tipo || 'NENHUM';
            this.ctaUrl = conteudo.cta_url || '';
            this.gatilho = conteudo.modo_gatilho || 'nenhum';
            this.palavrasChave = (conteudo.palavras_chave || []).join(', ');
            this.respostaPublica = conteudo.resposta_publica_comentario || '';
            this.mensagemDirect = conteudo.mensagem_direct || '';
            this.modalConteudos = false;
        },

        async gerarComIa() {
            this.carregandoIa = true;
            this.dicaEngajamento = '';

            try {
                const res = await fetch('{{ route("meta-posts.gerar-ia") }}', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                        'Accept': 'application/json'
                    },
                    body: JSON.stringify({
                        canal_alvo: this.canal,
                        objetivo: this.iaObjetivo,
                        tema: this.iaTema,
                        palavra: this.iaPalavra
                    })
                });

                const json = await res.json();

                if (json.success && json.data) {
                    if (json.data.texto) this.texto = json.data.texto;
                    if (json.data.palavra_chave) {
                        this.palavrasChave = json.data.palavra_chave;
                        this.gatilho = 'palavra_chave';
                    }
                    if (json.data.resposta_publica_comentario) this.respostaPublica = json.data.resposta_publica_comentario;
                    if (json.data.mensagem_direct) this.mensagemDirect = json.data.mensagem_direct;
                    if (json.data.dica_engajamento) this.dicaEngajamento = json.data.dica_engajamento;
                } else {
                    alert(json.message || 'Não foi possível gerar a copy no momento.');
                }
            } catch (err) {
                alert('Erro de comunicação com o servidor ao chamar a IA.');
            } finally {
                this.carregandoIa = false;
            }
        }
    };
}
</script>
@endsection
