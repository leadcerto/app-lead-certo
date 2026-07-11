@extends('layouts.app')

@section('title', 'Configurações do Kanban')

@section('content')
<div class="max-w-4xl mx-auto" x-data="kanbanConfig()" x-init="carregar()">

    <div class="flex items-center gap-3 mb-6">
        <a href="{{ route('kanban') }}"
           class="text-gray-400 hover:text-gray-600 p-1 rounded-lg hover:bg-gray-100 transition-colors">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/>
            </svg>
        </a>
        <div>
            <h1 class="text-2xl font-bold text-gray-900">Configurações do Kanban</h1>
            <p class="text-sm text-gray-500 mt-0.5">Sequências e Agente de IA por coluna</p>
        </div>
    </div>

    {{-- Tabs de colunas --}}
    <div class="flex gap-1 bg-gray-100 p-1 rounded-xl mb-6 overflow-x-auto">
        <template x-for="col in colunas" :key="col.key">
            <button @click="abaAtiva = col.key; carregarIa(col.key)"
                    class="flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-sm font-medium transition-all whitespace-nowrap flex-shrink-0"
                    :class="abaAtiva === col.key
                        ? 'bg-white shadow text-gray-900'
                        : 'text-gray-500 hover:text-gray-700'">
                <span x-text="col.emoji"></span>
                <span x-text="col.label"></span>
                <span class="text-xs rounded-full px-1.5 font-semibold"
                      :style="abaAtiva === col.key ? 'background:#dcfce7;color:#15803d' : 'background:#e5e7eb;color:#6b7280'"
                      x-text="contarSeqs(col.key)"></span>
            </button>
        </template>
    </div>

    {{-- Conteúdo da aba --}}
    <template x-for="col in colunas" :key="col.key">
        <div x-show="abaAtiva === col.key" style="display:none" class="space-y-5">

            {{-- 1. Cabeçalho + Objetivo da coluna --}}
            <div class="bg-white border border-gray-200 rounded-2xl p-5 shadow-sm">
                <div class="mb-4">
                    <h2 class="font-bold text-gray-800 text-base"
                        x-text="col.emoji + ' Coluna: ' + col.label"></h2>
                    <p class="text-xs text-gray-400 mt-0.5" x-text="col.desc"></p>
                </div>
                <div>
                    <label class="block text-xs font-semibold text-gray-500 mb-1.5">Objetivo desta etapa</label>
                    <textarea
                        @input="objetivo[col.key] = $event.target.value; objetivoAlterado[col.key] = true"
                        :value="objetivo[col.key] ?? ''"
                        :placeholder="col.objetivoEx"
                        rows="2"
                        class="w-full text-sm border border-gray-200 rounded-xl p-3 focus:outline-none focus:ring-2 focus:ring-gray-400 resize-none bg-gray-50"
                    ></textarea>
                    <div class="flex justify-end mt-2">
                        <button @click="salvarObjetivo(col.key)"
                                :disabled="!objetivoAlterado[col.key]"
                                class="text-xs bg-gray-700 hover:bg-gray-900 disabled:opacity-40 text-white px-3 py-1.5 rounded-lg transition-colors">
                            <span x-show="!objetivoSalvo[col.key]">Salvar objetivo</span>
                            <span x-show="objetivoSalvo[col.key]">✓ Salvo</span>
                        </button>
                    </div>
                </div>
            </div>

            {{-- 2. Seção: Sequências --}}
            <div>
                <div class="flex items-center justify-between gap-2 mb-2">
                    <div class="flex items-center gap-2">
                        <svg class="w-4 h-4 text-green-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 10h.01M12 10h.01M16 10h.01M9 16H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-5l-3 3-3-3z"/>
                        </svg>
                        <span class="text-xs font-semibold text-gray-600 uppercase tracking-wide">Sequência de Mensagens</span>
                    </div>
                    <button @click="novaSequencia(col.key)"
                            class="flex-shrink-0 bg-green-600 hover:bg-green-700 text-white text-xs font-medium px-3 py-1.5 rounded-lg transition-colors">
                        + Sequência
                    </button>
                </div>
                <p class="text-xs text-gray-400 ml-6 mb-1.5">Mensagens automáticas enviadas ao lead ao entrar nesta coluna. Se o lead responder, as pendentes são canceladas.</p>
                <p class="text-xs text-gray-400 ml-6 mb-3">
                    <span class="font-medium text-gray-500">Variáveis disponíveis:</span>
                    <code class="mx-0.5 rounded bg-gray-100 px-1 font-mono text-gray-600">{nome}</code>
                    <code class="mx-0.5 rounded bg-gray-100 px-1 font-mono text-gray-600">{empresa}</code>
                    <code class="mx-0.5 rounded bg-gray-100 px-1 font-mono text-gray-600">{endereco_saida}</code>
                    <code class="mx-0.5 rounded bg-gray-100 px-1 font-mono text-gray-600">{endereco_destino}</code>
                    <code class="mx-0.5 rounded bg-gray-100 px-1 font-mono text-gray-600">{data_hoje}</code>
                    <code class="mx-0.5 rounded bg-gray-100 px-1 font-mono text-gray-600">{dia_semana}</code>
                </p>
            </div>

            <div class="space-y-3">
                <template x-if="seqsPorColuna(col.key).length === 0">
                    <div class="text-center py-10 text-gray-400 text-sm border-2 border-dashed border-gray-200 rounded-xl">
                        Nenhuma sequência nesta coluna.
                    </div>
                </template>

                <template x-for="seq in seqsPorColuna(col.key)" :key="seq.id">
                    <div class="bg-white border border-gray-200 rounded-2xl overflow-hidden shadow-sm">

                        {{-- Cabeçalho --}}
                        <div class="px-5 py-3 flex items-center gap-3">
                            <button @click="toggleSeq(seq.id)"
                                    class="flex-1 flex items-center gap-2 text-left min-w-0">
                                <svg class="w-4 h-4 text-gray-400 flex-shrink-0 transition-transform duration-150"
                                     :class="aberto === seq.id ? 'rotate-90' : ''"
                                     fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
                                </svg>
                                <div class="min-w-0">
                                    <div class="flex items-center gap-2">
                                        <span class="text-sm font-semibold text-gray-800" x-text="seq.nome"></span>
                                        <span x-show="!seq.ativo"
                                              class="text-xs px-1.5 py-0.5 rounded-full"
                                              style="background:#f3f4f6;color:#9ca3af">inativa</span>
                                    </div>
                                    <p class="text-xs text-gray-400 truncate italic"
                                       x-show="!editandoDescricao[seq.id]"
                                       x-text="seq.descricao || 'Sem objetivo definido — clique para editar'"
                                       @click.stop="iniciarEditarDescricao(seq)"
                                       style="cursor:text"></p>
                                    <div x-show="editandoDescricao[seq.id]" @click.stop class="flex items-center gap-2 mt-0.5">
                                        <input type="text"
                                               :value="seq.descricao"
                                               @input="seq.descricao = $event.target.value"
                                               @keydown.enter="salvarDescricao(seq)"
                                               @keydown.escape="editandoDescricao[seq.id] = false"
                                               @blur="salvarDescricao(seq)"
                                               class="flex-1 text-xs border border-green-300 rounded px-2 py-0.5 focus:outline-none focus:ring-1 focus:ring-green-500"
                                               placeholder="Qual o objetivo desta sequência?"
                                               x-ref="descricaoInput">
                                    </div>
                                </div>
                                <span class="ml-auto flex-shrink-0 text-xs text-gray-400"
                                      x-text="seq.mensagens_count + ' msg'"></span>
                            </button>

                            <div class="flex items-center gap-1.5 flex-shrink-0">
                                <label class="flex items-center gap-1 cursor-pointer">
                                    <input type="checkbox" :checked="seq.ativo"
                                           @change="toggleAtivo(seq)"
                                           class="w-3.5 h-3.5 accent-green-600">
                                    <span class="text-xs text-gray-400">ativa</span>
                                </label>
                                <button @click="editarSeq(seq)"
                                        class="text-gray-400 hover:text-gray-600 p-1">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                              d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/>
                                    </svg>
                                </button>
                                <button @click="excluirSeq(seq.id)"
                                        class="text-red-300 hover:text-red-500 p-1">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                              d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                                    </svg>
                                </button>
                            </div>
                        </div>

                        {{-- Mensagens --}}
                        <div x-show="aberto === seq.id" style="display:none"
                             class="border-t border-gray-100 bg-gray-50 px-5 py-4 space-y-3">

                            <template x-if="(mensagensPor[seq.id] || []).length === 0">
                                <p class="text-sm text-gray-400 text-center py-4">Nenhuma mensagem ainda.</p>
                            </template>

                            <template x-for="(msg, idx) in (mensagensPor[seq.id] || [])" :key="msg.id">
                                <div class="bg-white border border-gray-200 rounded-xl p-4">
                                    <div class="flex items-start gap-3">
                                        <span class="w-6 h-6 rounded-full text-xs font-bold flex items-center justify-center flex-shrink-0"
                                              style="background:#dcfce7;color:#16a34a"
                                              x-text="idx + 1"></span>
                                        <div class="flex-1 min-w-0">
                                            <template x-if="editandoMsgId !== msg.id">
                                                <div>
                                                    <template x-if="msg.imagem_url">
                                                        <img :src="msg.imagem_url" class="mb-2 max-h-24 rounded-lg object-cover border border-gray-200">
                                                    </template>
                                                    <p class="text-sm text-gray-800 whitespace-pre-wrap break-words"
                                                       x-text="msg.conteudo || '(só imagem)'"></p>
                                                    <div class="mt-1 flex items-center gap-3 flex-wrap">
                                                        <span class="text-xs text-gray-400"
                                                              x-text="msg.delay_segundos === 0 ? 'Envio imediato' : 'Aguarda ' + formatDelay(msg.delay_segundos)"></span>
                                                        <label class="flex items-center gap-1 cursor-pointer">
                                                            <input type="checkbox" :checked="msg.ativo"
                                                                   @change="toggleAtivoMsg(seq.id, msg)"
                                                                   class="w-3 h-3 accent-green-600">
                                                            <span class="text-xs text-gray-400">ativa</span>
                                                        </label>
                                                        <template x-if="msg.obrigatorio">
                                                            <span class="text-xs text-red-600 font-semibold">⚠ envio obrigatório</span>
                                                        </template>
                                                    </div>
                                                    <template x-if="(msg.button_settings || []).length">
                                                        <div class="mt-1.5 flex flex-wrap gap-1">
                                                            <template x-for="b in msg.button_settings" :key="b.text + b.action">
                                                                <span class="text-xs bg-purple-50 text-purple-600 border border-purple-200 px-1.5 py-0.5 rounded" x-text="b.text"></span>
                                                            </template>
                                                        </div>
                                                    </template>
                                                </div>
                                            </template>
                                            <template x-if="editandoMsgId === msg.id">
                                                <div class="space-y-2">
                                                    <template x-if="editMsgImagemPreview || msg.imagem_url">
                                                        <div class="relative inline-block">
                                                            <img :src="editMsgImagemPreview || msg.imagem_url"
                                                                 class="max-h-24 rounded-lg object-cover border border-gray-200">
                                                            <button @click="removerImagemMsg(msg)"
                                                                    class="absolute -top-1 -right-1 w-4 h-4 bg-red-500 text-white rounded-full text-xs flex items-center justify-center">✕</button>
                                                        </div>
                                                    </template>
                                                    <textarea x-model="editMsgConteudo" rows="3"
                                                              class="w-full text-sm border border-gray-300 rounded-lg p-2 focus:outline-none focus:ring-2 focus:ring-green-500"
                                                              placeholder="Texto da mensagem..."></textarea>

                                                    @include('kanban.partials._botoes-editor', ['arrayExpr' => 'editMsgBotoes'])

                                                    <div class="flex items-center gap-3 flex-wrap">
                                                        <label class="flex items-center gap-1.5 cursor-pointer text-xs text-gray-500 hover:text-green-600 border border-gray-200 rounded-lg px-2 py-1.5">
                                                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                                                            </svg>
                                                            Imagem
                                                            <input type="file" accept="image/*" class="hidden" @change="selecionarImagemMsg($event)">
                                                        </label>
                                                        <div class="flex items-center gap-1.5">
                                                            <span class="text-xs text-gray-500">Aguarda</span>
                                                            <input type="number" x-model.number="editMsgDelay" min="0"
                                                                   class="w-16 text-xs border border-gray-300 rounded px-2 py-1">
                                                            <select x-model="editMsgDelayUnidade"
                                                                    class="text-xs border border-gray-300 rounded px-1.5 py-1 bg-white text-gray-700">
                                                                <option value="seg">seg</option>
                                                                <option value="min">min</option>
                                                                <option value="hora">hora</option>
                                                            </select>
                                                        </div>
                                                        <label class="flex items-center gap-1.5 cursor-pointer text-xs text-gray-500" title="Envia mesmo se o lead já tiver respondido e a sequência normalmente seria cancelada">
                                                            <input type="checkbox" x-model="editMsgObrigatorio" class="w-3.5 h-3.5 accent-red-600">
                                                            Envio obrigatório
                                                        </label>
                                                    </div>
                                                    <div class="flex gap-2">
                                                        <button @click="salvarMsg(seq.id, msg)"
                                                                class="text-xs bg-green-600 text-white px-3 py-1.5 rounded-lg hover:bg-green-700">Salvar</button>
                                                        <button @click="cancelarMsg()"
                                                                class="text-xs text-gray-500 hover:text-gray-700 px-2 py-1.5">Cancelar</button>
                                                    </div>
                                                </div>
                                            </template>
                                        </div>
                                        <template x-if="editandoMsgId !== msg.id">
                                            <div class="flex items-center gap-1 flex-shrink-0">
                                                <button @click="iniciarEditarMsg(msg)" class="text-gray-400 hover:text-gray-600 p-1">
                                                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                                              d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/>
                                                    </svg>
                                                </button>
                                                <button @click="excluirMsg(seq.id, msg.id)" class="text-red-300 hover:text-red-500 p-1">
                                                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                                              d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                                                    </svg>
                                                </button>
                                            </div>
                                        </template>
                                    </div>
                                </div>
                            </template>

                            {{-- Adicionar mensagem --}}
                            <div class="bg-white border border-dashed border-gray-300 rounded-xl p-4">
                                <div class="flex items-center justify-between mb-3">
                                    <p class="text-xs font-semibold text-gray-500">Adicionar mensagem</p>
                                    <div class="flex flex-wrap gap-1">
                                        <span class="text-xs text-gray-400 mr-1">Variáveis:</span>
                                        <template x-for="v in ['{nome}','{empresa}','{endereco_saida}','{endereco_destino}','{data_hoje}','{dia_semana}']" :key="v">
                                            <button type="button"
                                                    @click="novoConteudo[seq.id] = (novoConteudo[seq.id] || '') + v"
                                                    class="rounded bg-gray-100 px-1.5 py-0.5 font-mono text-xs text-gray-600 hover:bg-green-100 hover:text-green-700 transition-colors"
                                                    x-text="v"></button>
                                        </template>
                                    </div>
                                </div>
                                <template x-if="novaImagemPreview[seq.id]">
                                    <div class="relative inline-block mb-2">
                                        <img :src="novaImagemPreview[seq.id]" class="max-h-24 rounded-lg object-cover border border-gray-200">
                                        <button @click="novaImagem[seq.id] = null; novaImagemPreview[seq.id] = null"
                                                class="absolute -top-1 -right-1 w-4 h-4 bg-red-500 text-white rounded-full text-xs flex items-center justify-center">✕</button>
                                    </div>
                                </template>
                                <textarea @input="novoConteudo[seq.id] = $event.target.value"
                                          :value="novoConteudo[seq.id] || ''"
                                          rows="2"
                                          placeholder="Texto... Variáveis: {nome} {empresa} {endereco_saida} {endereco_destino} {data_hoje} {dia_semana}"
                                          class="w-full text-sm border border-gray-300 rounded-lg p-2 focus:outline-none focus:ring-2 focus:ring-green-500"></textarea>

                                @include('kanban.partials._botoes-editor', ['arrayExpr' => 'novoBotoes[seq.id]'])

                                <div class="mt-2 flex items-center gap-3 flex-wrap">
                                    <label class="flex items-center gap-1.5 cursor-pointer text-xs text-gray-500 hover:text-green-600 border border-gray-200 rounded-lg px-2 py-1.5">
                                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                                        </svg>
                                        Imagem
                                        <input type="file" accept="image/*" class="hidden"
                                               @change="selecionarNovaImagem($event, seq.id)">
                                    </label>
                                    <div class="flex items-center gap-1.5">
                                        <span class="text-xs text-gray-500">Aguarda</span>
                                        <input type="number"
                                               :value="novoDelay[seq.id] || 0"
                                               @input="novoDelay[seq.id] = parseInt($event.target.value) || 0"
                                               min="0"
                                               class="w-16 text-xs border border-gray-300 rounded px-2 py-1">
                                        <select :value="novoDelayUnidade[seq.id] || 'min'"
                                                @change="novoDelayUnidade[seq.id] = $event.target.value"
                                                class="text-xs border border-gray-300 rounded px-1.5 py-1 bg-white text-gray-700">
                                            <option value="seg">seg</option>
                                            <option value="min">min</option>
                                            <option value="hora">hora</option>
                                        </select>
                                    </div>
                                    <label class="flex items-center gap-1.5 cursor-pointer text-xs text-gray-500" title="Envia mesmo se o lead já tiver respondido e a sequência normalmente seria cancelada">
                                        <input type="checkbox"
                                               :checked="novoObrigatorio[seq.id] || false"
                                               @change="novoObrigatorio[seq.id] = $event.target.checked"
                                               class="w-3.5 h-3.5 accent-red-600">
                                        Envio obrigatório
                                    </label>
                                    <button @click="adicionarMsg(seq.id)"
                                            class="ml-auto text-xs bg-green-600 text-white px-4 py-1.5 rounded-lg hover:bg-green-700">
                                        Adicionar
                                    </button>
                                </div>
                            </div>

                            {{-- ✨ Card — Aplicar Variáveis com IA --}}
                            <div x-show="(mensagensPor[seq.id] || []).some(m => m.conteudo)"
                                 style="display:none"
                                 class="rounded-2xl overflow-hidden border border-indigo-200">

                                {{-- Topo: título + descrição + chips --}}
                                <div class="bg-indigo-50 px-5 pt-4 pb-3">
                                    <div class="flex items-center gap-2 mb-2">
                                        <svg class="w-4 h-4 text-indigo-500 flex-shrink-0" viewBox="0 0 24 24" fill="currentColor">
                                            <path d="M12 2l2.4 7.4H22l-6.2 4.5 2.4 7.4L12 17l-6.2 4.3 2.4-7.4L2 9.4h7.6L12 2z"/>
                                        </svg>
                                        <span class="text-sm font-bold text-indigo-900">Aplicar Variáveis com IA</span>
                                        <span class="text-xs font-semibold text-indigo-500 bg-indigo-100 px-2 py-0.5 rounded-full">Exclusivo</span>
                                    </div>
                                    <p class="text-xs text-indigo-800 leading-relaxed mb-2.5">
                                        A IA lê cada mensagem e insere variáveis onde ficam <strong>100% naturais</strong> — saudação por horário, nome do lead, aberturas casuais, gatilhos e CTAs. Cada lead recebe uma versão única.
                                    </p>
                                    <div class="flex flex-wrap gap-1.5">
                                        <code class="bg-white border border-indigo-200 text-indigo-600 text-xs px-1.5 py-0.5 rounded font-mono">{saudacao_tempo}</code>
                                        <code class="bg-white border border-indigo-200 text-indigo-600 text-xs px-1.5 py-0.5 rounded font-mono">{nome}</code>
                                        <code class="bg-white border border-indigo-200 text-indigo-600 text-xs px-1.5 py-0.5 rounded font-mono">{abertura_casual}</code>
                                        <code class="bg-white border border-indigo-200 text-indigo-600 text-xs px-1.5 py-0.5 rounded font-mono">{tempo_passado}</code>
                                        <code class="bg-white border border-gray-200 text-gray-400 text-xs px-1.5 py-0.5 rounded font-mono">+ 7 mais</code>
                                    </div>
                                </div>

                                {{-- Botão de ação — destaque total --}}
                                <button @click="sugerirVariaveis(seq)"
                                        :disabled="analisandoSeqId === seq.id"
                                        :style="{
                                            width:'100%',
                                            display:'flex',
                                            alignItems:'center',
                                            justifyContent:'center',
                                            gap:'10px',
                                            background: analisandoSeqId === seq.id ? '#6366f1' : '#4f46e5',
                                            color:'white',
                                            fontWeight:'600',
                                            fontSize:'0.875rem',
                                            padding:'14px 20px',
                                            border:'none',
                                            cursor: analisandoSeqId === seq.id ? 'wait' : 'pointer',
                                            opacity: analisandoSeqId === seq.id ? '0.85' : '1',
                                            transition:'background .15s, opacity .15s'
                                        }"
                                        onmouseenter="if(!this.disabled)this.style.background='#4338ca'"
                                        onmouseleave="if(!this.disabled)this.style.background='#4f46e5'">
                                    <svg x-show="analisandoSeqId !== seq.id" style="width:16px;height:16px;flex-shrink:0" viewBox="0 0 24 24" fill="currentColor">
                                        <path d="M12 2l2.4 7.4H22l-6.2 4.5 2.4 7.4L12 17l-6.2 4.3 2.4-7.4L2 9.4h7.6L12 2z"/>
                                    </svg>
                                    <svg x-show="analisandoSeqId === seq.id" style="width:16px;height:16px;flex-shrink:0;display:none" class="animate-spin" fill="none" viewBox="0 0 24 24">
                                        <circle style="opacity:.3" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                                        <path style="opacity:.8" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"/>
                                    </svg>
                                    <span x-text="analisandoSeqId === seq.id ? 'IA analisando suas mensagens...' : 'Analisar mensagens e sugerir variáveis'"></span>
                                    <svg x-show="analisandoSeqId !== seq.id" style="width:16px;height:16px;flex-shrink:0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M9 5l7 7-7 7"/>
                                    </svg>
                                </button>

                                {{-- Aviso âmbar --}}
                                <div class="bg-amber-50 border-t border-amber-200 px-5 py-3 flex items-start gap-2.5">
                                    <svg class="w-4 h-4 text-amber-500 flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
                                    </svg>
                                    <div>
                                        <p class="text-xs font-bold text-amber-900">Use somente depois de elaborar e testar todas as mensagens com cuidado</p>
                                        <p class="text-xs text-amber-800 mt-0.5 leading-relaxed">Esta ferramenta propõe alterações geradas por IA. Antes de qualquer mudança ser salva, você verá um preview completo de cada mensagem — e decide o que aplicar.</p>
                                    </div>
                                </div>
                            </div>

                        </div>
                    </div>
                </template>
            </div>

            {{-- 3. Seção: Agente de IA --}}
            <div>
                <div class="flex items-center gap-2 mb-1">
                    <svg class="w-4 h-4 text-purple-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.663 17h4.673M12 3v1m6.364 1.636l-.707.707M21 12h-1M4 12H3m3.343-5.657l-.707-.707m2.828 9.9a5 5 0 117.072 0l-.548.547A3.374 3.374 0 0014 18.469V19a2 2 0 11-4 0v-.531c0-.895-.356-1.754-.988-2.386l-.548-.547z"/>
                    </svg>
                    <span class="text-xs font-semibold text-gray-600 uppercase tracking-wide">Agente de IA</span>
                </div>

                {{-- Objetivo inline igual às sequências --}}
                <div class="ml-6 mb-3">
                    <p class="text-xs text-gray-400 italic"
                       x-show="!editandoIaObjetivo[col.key]"
                       x-text="iaObjetivo[col.key] || 'Sem objetivo definido — clique para descrever o que este agente faz aqui'"
                       @click="editandoIaObjetivo[col.key] = true"
                       style="cursor:text"></p>
                    <div x-show="editandoIaObjetivo[col.key]" class="flex items-center gap-2">
                        <input type="text"
                               :value="iaObjetivo[col.key] ?? ''"
                               @input="iaObjetivo[col.key] = $event.target.value"
                               @keydown.enter="salvarIaObjetivo(col.key)"
                               @keydown.escape="editandoIaObjetivo[col.key] = false"
                               @blur="salvarIaObjetivo(col.key)"
                               class="flex-1 text-xs border border-purple-300 rounded px-2 py-0.5 focus:outline-none focus:ring-1 focus:ring-purple-500"
                               :placeholder="col.objetivoEx">
                    </div>
                </div>

                <div class="bg-white border border-gray-200 rounded-2xl overflow-hidden shadow-sm">
                    {{-- Tópicos de orientação (colapsável) --}}
                    <div x-data="{ dicasAbertas: false }" class="border-b border-gray-100">
                        <button @click="dicasAbertas = !dicasAbertas"
                                class="w-full px-5 py-3 flex items-center justify-between text-left hover:bg-gray-50 transition-colors">
                            <span class="text-xs font-semibold text-purple-600 flex items-center gap-1.5">
                                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                                </svg>
                                O que informar para a IA funcionar bem nesta etapa
                            </span>
                            <svg class="w-4 h-4 text-gray-400 transition-transform duration-150"
                                 :class="dicasAbertas ? 'rotate-180' : ''"
                                 fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
                            </svg>
                        </button>
                        <div x-show="dicasAbertas" style="display:none" class="px-5 pb-4 bg-purple-50">
                            <div class="grid grid-cols-2 gap-x-8 gap-y-1 pt-3">
                                <template x-for="dica in col.dicas" :key="dica">
                                    <div class="flex items-start gap-1.5 text-xs text-purple-700 py-0.5">
                                        <span class="mt-0.5 flex-shrink-0" style="color:#9333ea">•</span>
                                        <span x-text="dica"></span>
                                    </div>
                                </template>
                            </div>
                        </div>
                    </div>

                    <div class="px-5 py-4">

                        {{-- Dica compacta: tokens --}}
                        <div class="mb-3 p-3 bg-gray-50 border border-gray-200 rounded-xl">
                            <p class="text-xs font-semibold text-gray-500 mb-2">Tokens que movem o card automaticamente:</p>
                            <div class="flex flex-wrap gap-1.5">
                                <code class="text-xs bg-blue-50 text-blue-700 border border-blue-200 px-2 py-0.5 rounded font-mono cursor-default" title="Move para a coluna Novo Lead">[LEAD_NOVO]</code>
                                <code class="text-xs bg-green-50 text-green-700 border border-green-200 px-2 py-0.5 rounded font-mono cursor-default" title="Move para Em Atendimento (lead respondeu)">[EM_ATENDIMENTO]</code>
                                <code class="text-xs bg-yellow-50 text-yellow-700 border border-yellow-200 px-2 py-0.5 rounded font-mono cursor-default" title="Move para Aguardando Orçamento (dados completos)">[AGUARDANDO_ORCAMENTO]</code>
                                <code class="text-xs bg-orange-50 text-orange-700 border border-orange-200 px-2 py-0.5 rounded font-mono cursor-default" title="Move para Aguardando Lead (proposta enviada, esperando retorno)">[AGUARDANDO_LEAD]</code>
                                <code class="text-xs bg-pink-50 text-pink-700 border border-pink-200 px-2 py-0.5 rounded font-mono cursor-default" title="Move para Pagamento (orçamento aprovado, aguardando sinal)">[PAGAMENTO]</code>
                                <code class="text-xs bg-purple-50 text-purple-700 border border-purple-200 px-2 py-0.5 rounded font-mono cursor-default" title="Move para Serviço Agendado (sinal pago, serviço confirmado)">[SERVICO_AGENDADO]</code>
                                <code class="text-xs bg-red-50 text-red-700 border border-red-200 px-2 py-0.5 rounded font-mono cursor-default" title="Encerra o atendimento (lead desistiu ou não responde)">[ENCERRADO]</code>
                            </div>
                            <p class="text-xs text-gray-400 mt-2">Inclua um token no final da resposta da IA para mover o card. Use apenas um por mensagem.</p>
                        </div>

                        <textarea
                            @input="iaContexto[col.key] = $event.target.value; iaAlterado[col.key] = true"
                            :value="iaContexto[col.key] ?? ''"
                            :placeholder="col.iaPlaceholder"
                            rows="10"
                            class="w-full text-sm border border-gray-200 rounded-xl p-3 focus:outline-none focus:ring-2 focus:ring-purple-400 resize-none bg-gray-50"
                        ></textarea>

                        <div class="mt-3 flex items-center justify-between">
                            <div class="flex items-center gap-4">
                                <label class="flex items-center gap-1.5 cursor-pointer">
                                    <input type="checkbox"
                                           :checked="iaAtivo[col.key]"
                                           @change="iaAtivo[col.key] = $event.target.checked; iaAlterado[col.key] = true"
                                           class="w-3.5 h-3.5 accent-purple-600">
                                    <span class="text-xs text-gray-500">Agente ativo nesta coluna</span>
                                </label>
                                <div class="flex items-center gap-1.5">
                                    <span class="text-xs text-gray-500">Aguardar</span>
                                    <input type="number" min="0"
                                           :value="iaDelay[col.key] ?? 45"
                                           @input="iaDelay[col.key] = parseInt($event.target.value) || 0; iaAlterado[col.key] = true"
                                           class="w-16 text-xs border border-gray-300 rounded px-2 py-1">
                                    <select :value="iaDelayUnidade[col.key] || 'seg'"
                                            @change="iaDelayUnidade[col.key] = $event.target.value; iaAlterado[col.key] = true"
                                            class="text-xs border border-gray-300 rounded px-1.5 py-1 bg-white text-gray-700">
                                        <option value="seg">seg</option>
                                        <option value="min">min</option>
                                        <option value="hora">hora</option>
                                    </select>
                                    <span class="text-xs text-gray-500">antes de responder</span>
                                </div>
                            </div>
                            <div class="flex items-center gap-2">
                                <span x-show="iaSalvando[col.key]" class="text-xs text-gray-400">Salvando...</span>
                                <span x-show="iaSalvo[col.key]" class="text-xs text-green-600">✓ Salvo</span>
                                <button @click="salvarIa(col.key)"
                                        :disabled="!iaAlterado[col.key]"
                                        class="text-sm bg-purple-600 hover:bg-purple-700 disabled:opacity-40 text-white px-4 py-1.5 rounded-lg transition-colors">
                                    Salvar
                                </button>
                            </div>
                        </div>

                        <div class="mt-3 pt-3 border-t border-gray-100">
                            <p class="text-xs font-semibold text-gray-500 mb-2">Estágios de silêncio (reengajamento automático, roda a cada 5min entre 8h-20h)</p>
                            <div class="flex flex-wrap items-center gap-4">
                                <div class="flex items-center gap-1.5">
                                    <span class="text-xs text-gray-500">1 · toque suave</span>
                                    <input type="number" min="1"
                                           :value="estagio1Delay[col.key] ?? 1"
                                           @input="estagio1Delay[col.key] = parseInt($event.target.value) || 0; iaAlterado[col.key] = true"
                                           class="w-14 text-xs border border-gray-300 rounded px-2 py-1">
                                    <select :value="estagio1DelayUnidade[col.key] || 'hora'"
                                            @change="estagio1DelayUnidade[col.key] = $event.target.value; iaAlterado[col.key] = true"
                                            class="text-xs border border-gray-300 rounded px-1.5 py-1 bg-white text-gray-700">
                                        <option value="seg">seg</option>
                                        <option value="min">min</option>
                                        <option value="hora">hora</option>
                                    </select>
                                </div>
                                <div class="flex items-center gap-1.5">
                                    <span class="text-xs text-gray-500">2 · urgência sutil</span>
                                    <input type="number" min="1"
                                           :value="estagio2Delay[col.key] ?? 2"
                                           @input="estagio2Delay[col.key] = parseInt($event.target.value) || 0; iaAlterado[col.key] = true"
                                           class="w-14 text-xs border border-gray-300 rounded px-2 py-1">
                                    <select :value="estagio2DelayUnidade[col.key] || 'hora'"
                                            @change="estagio2DelayUnidade[col.key] = $event.target.value; iaAlterado[col.key] = true"
                                            class="text-xs border border-gray-300 rounded px-1.5 py-1 bg-white text-gray-700">
                                        <option value="seg">seg</option>
                                        <option value="min">min</option>
                                        <option value="hora">hora</option>
                                    </select>
                                </div>
                                <div class="flex items-center gap-1.5">
                                    <span class="text-xs text-gray-500">3 · encerramento</span>
                                    <input type="number" min="1"
                                           :value="estagio3Delay[col.key] ?? 6"
                                           @input="estagio3Delay[col.key] = parseInt($event.target.value) || 0; iaAlterado[col.key] = true"
                                           class="w-14 text-xs border border-gray-300 rounded px-2 py-1">
                                    <select :value="estagio3DelayUnidade[col.key] || 'hora'"
                                            @change="estagio3DelayUnidade[col.key] = $event.target.value; iaAlterado[col.key] = true"
                                            class="text-xs border border-gray-300 rounded px-1.5 py-1 bg-white text-gray-700">
                                        <option value="seg">seg</option>
                                        <option value="min">min</option>
                                        <option value="hora">hora</option>
                                    </select>
                                </div>
                            </div>
                            <div class="mt-2 p-3 bg-blue-50 border border-blue-200 rounded-xl">
                                <p class="text-xs font-semibold text-blue-800 mb-1">Como configurar</p>
                                <p class="text-xs text-blue-700 leading-relaxed">
                                    Quando o lead para de responder, o sistema tenta reengajar automaticamente em 3 níveis, cada vez mais direto. Os campos acima definem <strong>quanto tempo de silêncio</strong> (contado desde a última mensagem da conversa, sua ou do lead) é preciso pra cada nível disparar. <strong>O estágio 2 precisa ser maior que o 1, e o 3 maior que o 2</strong> — senão a ordem fica confusa.
                                </p>
                                <ul class="text-xs text-blue-700 mt-1.5 space-y-0.5 list-disc list-inside">
                                    <li><strong>1 · toque suave</strong> — mensagem leve perguntando se o lead teve alguma dificuldade ou prefere responder por áudio.</li>
                                    <li><strong>2 · urgência sutil</strong> — avisa que a agenda está ficando concorrida e pergunta se o interesse ainda é atual.</li>
                                    <li><strong>3 · encerramento</strong> — se despede educadamente e pode encerrar o atendimento (mesmo token <code class="bg-white px-1 rounded">[ENCERRADO]</code> explicado acima).</li>
                                </ul>
                                <p class="text-xs text-blue-700 mt-1.5">
                                    O texto exato de cada mensagem fica a critério da IA, com base nas instruções escritas na caixa de texto grande acima (o mesmo campo "AGENTE DE IA" desta coluna). Se quiser controlar o que ela diz em cada estágio, inclua no texto trechos como "No Estágio 1, diga...", "No Estágio 2, diga...", "No Estágio 3, diga..." — se não escrever nada específico, a IA usa um tom padrão adequado a cada nível.
                                </p>
                                <p class="text-xs text-blue-700 mt-1.5">
                                    Isso roda sozinho a cada 5 minutos, só em horário comercial (8h às 20h), e reinicia do zero sempre que o lead responder de novo. Depois de ajustar os tempos, clique em <strong>Salvar</strong> (botão logo acima).
                                </p>
                            </div>
                        </div>

                        <div class="mt-3 pt-3 border-t border-gray-100">
                            <label class="flex items-center gap-2 mb-2 cursor-pointer">
                                <input type="checkbox"
                                       :checked="autoMoverAtivo[col.key]"
                                       @change="autoMoverAtivo[col.key] = $event.target.checked; iaAlterado[col.key] = true"
                                       class="w-3.5 h-3.5 accent-red-600">
                                <span class="text-xs font-semibold text-gray-500">Transferência automática por silêncio</span>
                            </label>
                            <template x-if="autoMoverAtivo[col.key]">
                                <div>
                                    <div class="flex flex-wrap items-center gap-2 mb-2">
                                        <span class="text-xs text-gray-500">Depois de</span>
                                        <input type="number" min="1"
                                               :value="autoMoverDelay[col.key] ?? 3"
                                               @input="autoMoverDelay[col.key] = parseInt($event.target.value) || 0; iaAlterado[col.key] = true"
                                               class="w-14 text-xs border border-gray-300 rounded px-2 py-1">
                                        <select :value="autoMoverDelayUnidade[col.key] || 'dia'"
                                                @change="autoMoverDelayUnidade[col.key] = $event.target.value; iaAlterado[col.key] = true"
                                                class="text-xs border border-gray-300 rounded px-1.5 py-1 bg-white text-gray-700">
                                            <option value="seg">seg</option>
                                            <option value="min">min</option>
                                            <option value="hora">hora</option>
                                            <option value="dia">dia</option>
                                        </select>
                                        <span class="text-xs text-gray-500">de silêncio, mover para</span>
                                        <select :value="autoMoverDestino[col.key] || 'encerrado'"
                                                @change="autoMoverDestino[col.key] = $event.target.value; iaAlterado[col.key] = true"
                                                class="text-xs border border-gray-300 rounded px-1.5 py-1 bg-white text-gray-700">
                                            <template x-for="c in colunas" :key="c.key">
                                                <option :value="c.key" x-text="c.label"></option>
                                            </template>
                                        </select>
                                    </div>
                                    <textarea :value="autoMoverMensagem[col.key] || ''"
                                              @input="autoMoverMensagem[col.key] = $event.target.value; iaAlterado[col.key] = true"
                                              rows="2"
                                              placeholder="Mensagem opcional pra avisar o lead antes de mover (ex: Por falta de comunicação, estamos encerrando seu atendimento e ficamos à disposição caso queira retomar o assunto). Deixe em branco pra mover sem avisar."
                                              class="w-full text-xs border border-gray-300 rounded-lg p-2 focus:outline-none focus:ring-2 focus:ring-red-400 resize-none"></textarea>
                                </div>
                            </template>
                            <div class="mt-2 p-3 bg-red-50 border border-red-200 rounded-xl">
                                <p class="text-xs font-semibold text-red-800 mb-1">Como configurar</p>
                                <p class="text-xs text-red-700 leading-relaxed">
                                    Se o lead ficar em silêncio pelo tempo configurado acima (contado desde a última mensagem da conversa), o sistema move o atendimento sozinho pra coluna escolhida — independente dos Estágios de silêncio acima. Se o destino for <strong>Encerrado</strong>, o sistema também marca como encerrado automaticamente e gera os relatórios de IA (mesmo efeito do botão Encerrar); se o lead responder depois, o atendimento reabre normalmente. Se preencher a mensagem, ela é enviada ao lead exatamente antes de mover — use <code class="bg-white px-1 rounded">{nome}</code> pra personalizar. Roda junto com os Estágios de silêncio (5 em 5 minutos, horário comercial).
                                </p>
                            </div>
                            <div class="flex items-center justify-end gap-2 mt-2">
                                <span x-show="iaSalvando[col.key]" class="text-xs text-gray-400">Salvando...</span>
                                <span x-show="iaSalvo[col.key]" class="text-xs text-green-600">✓ Salvo</span>
                                <button @click="salvarIa(col.key)"
                                        :disabled="!iaAlterado[col.key]"
                                        class="text-xs bg-red-600 hover:bg-red-700 disabled:opacity-40 text-white px-4 py-1.5 rounded-lg transition-colors">
                                    Salvar
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

        </div>
    </template>

    {{-- ✨ Modal: Preview Variáveis IA --}}
    <template x-if="modalVar">
        <div class="fixed inset-0 bg-black/60 z-50 flex items-center justify-center p-4" @keydown.escape.window="modalVar = false">
            <div class="bg-white rounded-2xl shadow-2xl w-full max-w-2xl max-h-[90vh] flex flex-col">

                {{-- Header --}}
                <div class="px-6 py-4 border-b border-gray-100 flex-shrink-0">
                    <div class="flex items-start justify-between gap-4">
                        <div>
                            <div class="flex items-center gap-2 mb-0.5">
                                <span class="text-lg">✨</span>
                                <h2 class="font-bold text-gray-900 text-base">Variáveis sugeridas pela IA</h2>
                            </div>
                            <p class="text-xs text-gray-500">
                                Sequência <span class="font-medium text-gray-700" x-text="varSeqNome"></span>
                                &nbsp;·&nbsp;
                                <span x-show="varSugestoes.some(s => s.alterado)">
                                    <span class="font-medium text-indigo-600"
                                          x-text="varSugestoes.filter(s => s.alterado).length"></span>
                                    de
                                    <span x-text="varSugestoes.length"></span>
                                    mensagens foram melhoradas
                                </span>
                                <span x-show="!varSugestoes.some(s => s.alterado)" class="text-gray-400">
                                    Nenhuma alteração necessária
                                </span>
                            </p>
                        </div>
                        <button @click="modalVar = false" class="text-gray-400 hover:text-gray-600 p-1 flex-shrink-0">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                            </svg>
                        </button>
                    </div>

                    {{-- Barra resumo --}}
                    <template x-if="varSugestoes.some(s => s.alterado)">
                        <div class="mt-3 flex items-center gap-2 text-xs">
                            <button @click="varSugestoes.forEach(s => { if(s.alterado) varSelecionados[s.id] = true })"
                                    class="text-indigo-600 hover:underline">Selecionar todas</button>
                            <span class="text-gray-300">·</span>
                            <button @click="varSugestoes.forEach(s => { varSelecionados[s.id] = false })"
                                    class="text-gray-400 hover:underline">Desmarcar todas</button>
                            <span class="ml-auto text-gray-500">
                                <span class="font-semibold text-indigo-600" x-text="varContarSelecionados()"></span>
                                selecionada(s) para aplicar
                            </span>
                        </div>
                    </template>
                </div>

                {{-- Corpo scrollável --}}
                <div class="overflow-y-auto flex-1 px-6 py-4 space-y-4">
                    <template x-for="(s, idx) in varSugestoes" :key="s.id">
                        <div class="rounded-xl border transition-all duration-150"
                             :class="s.alterado
                                ? (varSelecionados[s.id] ? 'border-indigo-300 bg-indigo-50/30' : 'border-gray-200 bg-white opacity-60')
                                : 'border-gray-100 bg-gray-50'">
                            <div class="px-4 py-2.5 border-b flex items-center gap-3"
                                 :class="s.alterado ? 'border-indigo-100' : 'border-gray-100'">
                                <span class="w-5 h-5 rounded-full text-xs font-bold flex items-center justify-center flex-shrink-0"
                                      :style="s.alterado ? 'background:#e0e7ff;color:#4f46e5' : 'background:#f3f4f6;color:#9ca3af'"
                                      x-text="idx + 1"></span>
                                <template x-if="s.alterado">
                                    <label class="flex items-center gap-2 cursor-pointer flex-1">
                                        <input type="checkbox" :checked="varSelecionados[s.id]"
                                               @change="varSelecionados[s.id] = $event.target.checked"
                                               class="w-4 h-4 rounded accent-indigo-600">
                                        <span class="text-xs font-semibold text-indigo-700">Variáveis encontradas — aplicar esta mensagem</span>
                                    </label>
                                </template>
                                <template x-if="!s.alterado">
                                    <span class="text-xs text-gray-400 flex-1">Sem sugestões — mensagem mantida igual</span>
                                </template>
                            </div>
                            <div class="p-4 grid grid-cols-2 gap-3" x-show="s.alterado">
                                <div>
                                    <p class="text-xs font-semibold text-gray-400 mb-1.5 uppercase tracking-wide">Antes</p>
                                    <div class="text-xs text-gray-600 bg-gray-50 rounded-lg p-3 border border-gray-200 whitespace-pre-wrap leading-relaxed min-h-[60px]"
                                         x-text="s.original"></div>
                                </div>
                                <div>
                                    <p class="text-xs font-semibold text-indigo-500 mb-1.5 uppercase tracking-wide">Depois ✨</p>
                                    <div class="text-xs text-gray-800 bg-white rounded-lg p-3 border-2 border-indigo-200 whitespace-pre-wrap leading-relaxed min-h-[60px]"
                                         x-html="destacarVariaveis(s.sugerido)"></div>
                                </div>
                            </div>
                        </div>
                    </template>

                    <template x-if="!varSugestoes.some(s => s.alterado)">
                        <div class="text-center py-8">
                            <div class="text-4xl mb-3">🎯</div>
                            <p class="text-sm font-medium text-gray-700">Suas mensagens já estão ótimas!</p>
                            <p class="text-xs text-gray-400 mt-1">A IA não encontrou oportunidades de melhoria nesta sequência.</p>
                        </div>
                    </template>
                </div>

                {{-- Footer --}}
                <div class="px-6 py-4 border-t border-gray-100 flex-shrink-0 flex items-center justify-between gap-3">
                    <button @click="modalVar = false"
                            class="text-sm text-gray-500 hover:text-gray-700 px-4 py-2 rounded-lg hover:bg-gray-100 transition-colors">
                        Cancelar
                    </button>
                    <template x-if="varSugestoes.some(s => s.alterado)">
                        <button @click="confirmarVariaveis()"
                                :disabled="varAplicando || varContarSelecionados() === 0"
                                class="flex items-center gap-2 text-sm bg-indigo-600 hover:bg-indigo-700 disabled:opacity-40 text-white px-5 py-2 rounded-xl font-medium transition-colors shadow-sm">
                            <span x-show="!varAplicando">
                                ✨ Aplicar
                                <span x-text="varContarSelecionados()"></span>
                                mensagem(ns) selecionada(s)
                            </span>
                            <span x-show="varAplicando" class="flex items-center gap-2">
                                <svg class="w-4 h-4 animate-spin" fill="none" viewBox="0 0 24 24">
                                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                                </svg>
                                Salvando...
                            </span>
                        </button>
                    </template>
                    <template x-if="!varSugestoes.some(s => s.alterado)">
                        <button @click="modalVar = false"
                                class="text-sm bg-gray-700 hover:bg-gray-900 text-white px-5 py-2 rounded-xl font-medium transition-colors">
                            Fechar
                        </button>
                    </template>
                </div>
            </div>
        </div>
    </template>

    {{-- Toast de feedback --}}
    <div x-show="toast.visivel"
         x-transition:enter="transition ease-out duration-300"
         x-transition:enter-start="opacity-0 translate-y-4"
         x-transition:enter-end="opacity-100 translate-y-0"
         x-transition:leave="transition ease-in duration-200"
         x-transition:leave-start="opacity-100 translate-y-0"
         x-transition:leave-end="opacity-0 translate-y-4"
         style="display:none;position:fixed;bottom:24px;right:24px;z-index:9999;max-width:360px"
         :style="{
             background: toast.tipo === 'erro' ? '#fef2f2' : (toast.tipo === 'aviso' ? '#fffbeb' : '#f0fdf4'),
             border: '1px solid ' + (toast.tipo === 'erro' ? '#fecaca' : (toast.tipo === 'aviso' ? '#fde68a' : '#bbf7d0')),
             borderRadius: '14px',
             padding: '14px 18px',
             boxShadow: '0 10px 30px rgba(0,0,0,.12)',
             display: toast.visivel ? 'flex' : 'none',
             alignItems: 'flex-start',
             gap: '10px'
         }">
        <span x-text="toast.tipo === 'erro' ? '❌' : (toast.tipo === 'aviso' ? '⚠️' : '✅')"
              style="font-size:1.1rem;flex-shrink:0;margin-top:1px"></span>
        <div style="flex:1">
            <p x-text="toast.texto"
               :style="{ fontSize:'0.85rem', fontWeight:'600', color: toast.tipo === 'erro' ? '#991b1b' : (toast.tipo === 'aviso' ? '#92400e' : '#14532d'), lineHeight:'1.4' }"></p>
        </div>
        <button @click="toast.visivel = false"
                style="color:#9ca3af;background:none;border:none;cursor:pointer;padding:0;flex-shrink:0">
            <svg style="width:16px;height:16px" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
            </svg>
        </button>
    </div>

    {{-- Modal sequência --}}
    <template x-if="modalAberto">
        <div class="fixed inset-0 bg-black/50 z-50 flex items-center justify-center p-4">
            <div class="bg-white rounded-2xl p-6 w-full max-w-md shadow-2xl">
                <h2 class="font-semibold text-gray-800 mb-4"
                    x-text="editando ? 'Editar sequência' : 'Nova sequência'"></h2>

                <div class="space-y-4">
                    <div>
                        <label class="block text-xs font-medium text-gray-600 mb-1">Nome *</label>
                        <input type="text" x-model="form.nome"
                               placeholder="Ex: Boas-vindas Lead Novo"
                               class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-green-500">
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-600 mb-1">Objetivo</label>
                        <textarea x-model="form.descricao" rows="2"
                                  placeholder="Qual o objetivo desta sequência?"
                                  class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-green-500"></textarea>
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-600 mb-1">Coluna</label>
                        <p class="text-sm text-gray-700 py-1.5 px-3 bg-gray-50 border border-gray-200 rounded-lg"
                           x-text="labelColuna(form.coluna_kanban)"></p>
                    </div>
                </div>

                <div class="flex gap-2 mt-5">
                    <button @click="fecharModal()"
                            class="flex-1 border border-gray-300 text-gray-600 py-2 rounded-lg text-sm hover:bg-gray-50">
                        Cancelar
                    </button>
                    <button @click="salvarSequencia()"
                            :disabled="!form.nome.trim()"
                            class="flex-1 bg-green-600 hover:bg-green-700 disabled:opacity-40 text-white py-2 rounded-lg text-sm transition-colors">
                        Salvar
                    </button>
                </div>
            </div>
        </div>
    </template>

</div>
@endsection

@push('scripts')
<script>
function kanbanConfig() {
    return {
        colunas: [
            {
                key: 'lead_novo', emoji: '🟢', label: 'Novo',
                desc: 'Lead acabou de entrar em contato pela primeira vez.',
                objetivoEx: 'Ex: Capturar o interesse inicial, coletar nome e tipo de serviço, e iniciar o relacionamento com simpatia.',
                seqObjetivoEx: 'Ex: Dar boas-vindas, confirmar recebimento da mensagem e informar que retornaremos em breve com um orçamento.',
                iaPlaceholder: 'Ex: Você é a assistente da Frete.Rio. O lead acabou de entrar em contato. Seu objetivo é coletar o nome, endereço de origem e destino, data da mudança e lista de itens. Seja simpático e objetivo. Não envie orçamento ainda.',
                dicas: [
                    'Nome da empresa e ramo de atuação',
                    'Serviços que você oferece (com detalhes)',
                    'Serviços que você NÃO faz',
                    'Área de atendimento (bairros, cidades)',
                    'Tom de voz (formal, descontraído, etc.)',
                    'Dados que a IA deve coletar do lead',
                    'O que a IA NÃO deve fazer nesta etapa',
                    'Perguntas frequentes e respostas padrão',
                ],
            },
            {
                key: 'em_atendimento', emoji: '🔵', label: 'Em Atendimento',
                desc: 'Lead respondeu — está em conversa ativa com a equipe ou com a IA.',
                objetivoEx: 'Ex: Entender as necessidades do lead, aprofundar informações e conduzir para o envio do orçamento.',
                iaPlaceholder: 'Ex: O lead já demonstrou interesse. Aprofunde as informações necessárias para o orçamento: metragem do imóvel, quantidade de cômodos, itens especiais (piano, safe, aquário). Seja consultivo e mostre experiência.',
                dicas: [
                    'Identidade do atendente (nome, empresa, tom)',
                    'Regras de estilo: tamanho das mensagens, uma pergunta por vez',
                    'Informações necessárias para fazer o orçamento',
                    'O que a IA NÃO deve fazer nesta etapa (ex: não dar preço)',
                    'Quando usar [AGUARDANDO_ORCAMENTO] (checklist completo)',
                    'Diferenciais do serviço que podem ser mencionados',
                    'Formas de pagamento aceitas',
                    'Situações em que deve transferir para humano',
                ],
            },
            {
                key: 'aguardando_orcamento', emoji: '🟡', label: 'Ag. Orçamento',
                desc: 'Aguardando a elaboração e envio do orçamento pelo time.',
                objetivoEx: 'Ex: Lead qualificado aguardando proposta. Manter o interesse aquecido enquanto o orçamento é preparado.',
                iaPlaceholder: 'Ex: O orçamento está sendo preparado pela equipe. Se o lead perguntar sobre prazo, informe que retornaremos em breve com os valores. Não cite preços sem ter o orçamento oficial aprovado.',
                dicas: [
                    'Prazo médio para envio do orçamento',
                    'O que está incluso no orçamento',
                    'Política de validade da proposta',
                    'Como o orçamento é enviado (PDF, link, etc.)',
                    'Mensagem de manutenção do interesse',
                    'Quando escalar para humano',
                ],
            },
            {
                key: 'aguardando_lead', emoji: '🟠', label: 'Ag. Lead',
                desc: 'Orçamento enviado — aguardando resposta ou decisão do lead.',
                objetivoEx: 'Ex: Acompanhar o lead com follow-up estratégico, contornar objeções e conduzir ao fechamento.',
                iaPlaceholder: 'Ex: O orçamento já foi enviado. Se o lead questionar o preço, reforce os diferenciais do serviço (seguro, equipe especializada, avaliações 5 estrelas). Ofereça formas de pagamento flexíveis. Não dê desconto sem autorização.',
                dicas: [
                    'Objeções mais comuns e como contorná-las',
                    'Gatilhos de urgência / escassez que podem ser usados',
                    'Desconto máximo que a IA pode oferecer (ou proibir)',
                    'Diferenciais vs. concorrência',
                    'Condições especiais de pagamento',
                    'Número de follow-ups e intervalo entre eles',
                    'Quando considerar o lead perdido',
                ],
            },
            {
                key: 'pagamento', emoji: '💳', label: 'Pagamento',
                desc: 'Orçamento aprovado — aguardando pagamento do sinal para confirmar o agendamento.',
                objetivoEx: 'Ex: Lead aprovou o orçamento. Enviar os dados para pagamento do sinal e confirmar o recebimento.',
                iaPlaceholder: 'Ex: O orçamento foi aprovado. Envie os dados de pagamento do sinal (Pix, link, boleto) e informe que o agendamento só é confirmado após a confirmação do pagamento.',
                dicas: [
                    'Formas de pagamento aceitas (Pix, cartão, boleto)',
                    'Valor do sinal exigido e como calcular',
                    'Prazo para pagamento após aprovação',
                    'O que acontece se o sinal não for pago',
                    'Como confirmar o recebimento do pagamento',
                    'Quando usar [SERVICO_AGENDADO] (após confirmação)',
                ],
            },
            {
                key: 'servico_agendado', emoji: '📅', label: 'Serv. Agendado',
                desc: 'Serviço confirmado e agendado na agenda.',
                objetivoEx: 'Ex: Confirmar detalhes do serviço, orientar o cliente sobre a preparação e garantir a satisfação pré-serviço.',
                iaPlaceholder: 'Ex: O serviço está agendado. Confirme data, horário e endereço completo. Oriente o cliente sobre como se preparar (encaixotar itens, desmontar móveis, liberar elevador). Informe que a equipe chegará no horário combinado.',
                dicas: [
                    'Checklist de preparação que o cliente deve seguir',
                    'Orientações sobre embalagem de itens frágeis',
                    'Política de cancelamento e reagendamento',
                    'Como a equipe se identifica no dia',
                    'Contato de emergência no dia do serviço',
                    'O que fazer se o cliente não estiver pronto',
                ],
            },
            {
                key: 'encerrado', emoji: '⚫', label: 'Encerrado',
                desc: 'Atendimento finalizado — fechado com ou sem venda.',
                objetivoEx: 'Ex: Registrar motivo do encerramento, coletar avaliação e abrir porta para futuras oportunidades.',
                iaPlaceholder: 'Ex: O atendimento foi encerrado. Agradeça o contato, solicite uma avaliação no Google e deixe a porta aberta para um próximo serviço. Se foi perda, registre o motivo com cordialidade.',
                dicas: [
                    'Mensagem de agradecimento padrão',
                    'Link / instrução para avaliação no Google',
                    'Motivos de perda mais comuns (para registrar)',
                    'Oferta de reengajamento futuro',
                    'Política de indicação / referral',
                ],
            },
        ],
        abaAtiva: 'lead_novo',
        lista: [],
        aberto: null,
        mensagensPor: {},
        modalAberto: false,
        editando: null,
        form: { nome: '', descricao: '', coluna_kanban: '' },

        novoConteudo: {},
        novoDelay: {},
        novoDelayUnidade: {},
        novaImagem: {},
        novaImagemPreview: {},
        novoBotoes: {},
        novoObrigatorio: {},

        editandoMsgId: null,
        editMsgConteudo: '',
        editMsgDelay: 0,
        editMsgDelayUnidade: 'min',
        editMsgImagem: null,
        editMsgImagemPreview: null,
        editMsgRemoverImagem: false,
        editMsgBotoes: [],
        editMsgObrigatorio: false,

        // Objetivo da coluna
        objetivo: {},
        objetivoAlterado: {},
        objetivoSalvo: {},

        // Edição inline de descrição/objetivo da sequência
        editandoDescricao: {},

        // Objetivo do agente de IA
        iaObjetivo: {},
        iaObjetivoAlterado: {},
        iaObjetivoSalvo: {},
        editandoIaObjetivo: {},

        // Contexto completo da IA
        iaContexto: {},
        iaAtivo: {},
        iaDelay: {},
        iaDelayUnidade: {},
        iaAlterado: {},
        iaSalvando: {},
        iaSalvo: {},
        iaCarregado: {},

        // Estágios de silêncio (reengajamento automático)
        estagio1Delay: {},
        estagio1DelayUnidade: {},
        estagio2Delay: {},
        estagio2DelayUnidade: {},
        estagio3Delay: {},
        estagio3DelayUnidade: {},

        // Transferência automática de coluna por silêncio
        autoMoverAtivo: {},
        autoMoverDelay: {},
        autoMoverDelayUnidade: {},
        autoMoverDestino: {},
        autoMoverMensagem: {},

        // ✨ Aplicar Variáveis IA
        analisandoSeqId: null,
        modalVar:        false,
        varSugestoes:    [],
        varSeqNome:      '',
        varSeqId:        null,
        varSelecionados: {},
        varAplicando:    false,

        // Toast de feedback
        toast: { visivel: false, tipo: 'ok', texto: '' },

        async carregar() {
            const res = await this.api('/api/painel/sequencias');
            if (res.ok) this.lista = await res.json();
            // Pré-carrega IA da aba inicial
            await this.carregarIa(this.abaAtiva);
        },

        seqsPorColuna(key) {
            return this.lista.filter(s => s.coluna_kanban === key);
        },

        contarSeqs(key) {
            return this.seqsPorColuna(key).length;
        },

        async toggleSeq(id) {
            if (this.aberto === id) { this.aberto = null; return; }
            this.aberto = id;
            if (!this.mensagensPor[id]) await this.carregarMsgs(id);
        },

        async carregarMsgs(seqId) {
            const res = await this.api(`/api/painel/sequencias/${seqId}/mensagens`);
            if (res.ok) this.mensagensPor[seqId] = await res.json();
        },

        novaSequencia(colunaKey) {
            this.editando = null;
            this.form = { nome: '', descricao: '', coluna_kanban: colunaKey };
            this.modalAberto = true;
        },

        editarSeq(seq) {
            this.editando = seq;
            this.form = { nome: seq.nome, descricao: seq.descricao || '', coluna_kanban: seq.coluna_kanban };
            this.modalAberto = true;
        },

        fecharModal() {
            this.modalAberto = false;
            this.editando = null;
        },

        async salvarSequencia() {
            if (!this.form.nome.trim()) return;
            let res;
            if (this.editando) {
                res = await this.api(`/api/painel/sequencias/${this.editando.id}`, 'PUT', this.form);
            } else {
                res = await this.api('/api/painel/sequencias', 'POST', this.form);
            }
            if (res.ok) { this.fecharModal(); await this.carregar(); }
        },

        async toggleAtivo(seq) {
            await this.api(`/api/painel/sequencias/${seq.id}`, 'PUT', { ativo: !seq.ativo });
            await this.carregar();
        },

        async excluirSeq(id) {
            if (!confirm('Excluir esta sequência e todas as suas mensagens?')) return;
            await this.api(`/api/painel/sequencias/${id}`, 'DELETE');
            if (this.aberto === id) this.aberto = null;
            await this.carregar();
        },

        selecionarNovaImagem(e, seqId) {
            const file = e.target.files[0]; if (!file) return;
            this.novaImagem[seqId] = file;
            this.novaImagemPreview[seqId] = URL.createObjectURL(file);
        },

        async adicionarMsg(seqId) {
            const conteudo = this.novoConteudo[seqId] || '';
            const imagem   = this.novaImagem[seqId];
            if (!conteudo.trim() && !imagem) return;

            const botoes = (this.novoBotoes[seqId] || []).filter(b => (b.text || '').trim() !== '');

            const fd = new FormData();
            fd.append('conteudo', conteudo);
            fd.append('delay_segundos', this.delayParaSegundos(this.novoDelay[seqId] || 0, this.novoDelayUnidade[seqId] || 'min'));
            if (imagem) fd.append('imagem', imagem);
            fd.append('button_settings', JSON.stringify(botoes));
            fd.append('obrigatorio', this.novoObrigatorio[seqId] ? '1' : '0');

            const res = await fetch(`/api/painel/sequencias/${seqId}/mensagens`, {
                method: 'POST',
                headers: { 'X-CSRF-TOKEN': '{{ csrf_token() }}' },
                body: fd,
            });
            if (res.ok) {
                this.novoConteudo[seqId] = '';
                this.novoDelay[seqId] = 0;
                this.novoDelayUnidade[seqId] = 'min';
                this.novaImagem[seqId] = null;
                this.novaImagemPreview[seqId] = null;
                this.novoBotoes[seqId] = [];
                this.novoObrigatorio[seqId] = false;
                await this.carregarMsgs(seqId);
                await this.carregar();
            } else {
                const erro = await res.json().catch(() => null);
                this.mostrarToast(erro?.message || 'Não foi possível adicionar a mensagem. Confira os botões preenchidos.', 'erro');
            }
        },

        iniciarEditarMsg(msg) {
            this.editandoMsgId    = msg.id;
            this.editMsgConteudo  = msg.conteudo;
            const d = this.segundosParaDisplay(msg.delay_segundos);
            this.editMsgDelay        = d.valor;
            this.editMsgDelayUnidade = d.unidade;
            this.editMsgImagem    = null;
            this.editMsgImagemPreview = null;
            this.editMsgRemoverImagem = false;
            this.editMsgBotoes = JSON.parse(JSON.stringify(msg.button_settings || []));
            this.editMsgObrigatorio = !!msg.obrigatorio;
        },

        cancelarMsg() {
            this.editandoMsgId = null;
            this.editMsgImagem = null;
            this.editMsgImagemPreview = null;
        },

        selecionarImagemMsg(e) {
            const file = e.target.files[0]; if (!file) return;
            this.editMsgImagem = file;
            this.editMsgImagemPreview = URL.createObjectURL(file);
            this.editMsgRemoverImagem = false;
        },

        removerImagemMsg(msg) {
            this.editMsgImagem = null;
            this.editMsgImagemPreview = null;
            this.editMsgRemoverImagem = true;
            msg.imagem_url = null;
        },

        async salvarMsg(seqId, msg) {
            const botoes = (this.editMsgBotoes || []).filter(b => (b.text || '').trim() !== '');

            const fd = new FormData();
            fd.append('_method', 'PUT');
            fd.append('conteudo', this.editMsgConteudo);
            fd.append('delay_segundos', this.delayParaSegundos(this.editMsgDelay, this.editMsgDelayUnidade));
            if (this.editMsgImagem) fd.append('imagem', this.editMsgImagem);
            if (this.editMsgRemoverImagem) fd.append('remover_imagem', '1');
            fd.append('button_settings', JSON.stringify(botoes));
            fd.append('obrigatorio', this.editMsgObrigatorio ? '1' : '0');

            const res = await fetch(`/api/painel/sequencias/${seqId}/mensagens/${msg.id}`, {
                method: 'POST',
                headers: { 'X-CSRF-TOKEN': '{{ csrf_token() }}' },
                body: fd,
            });

            if (res.ok) {
                this.cancelarMsg();
                await this.carregarMsgs(seqId);
            } else {
                const erro = await res.json().catch(() => null);
                this.mostrarToast(erro?.message || 'Não foi possível salvar a mensagem. Confira os botões preenchidos.', 'erro');
            }
        },

        async toggleAtivoMsg(seqId, msg) {
            await this.api(`/api/painel/sequencias/${seqId}/mensagens/${msg.id}`, 'PUT', { ativo: !msg.ativo });
            await this.carregarMsgs(seqId);
        },

        async excluirMsg(seqId, id) {
            if (!confirm('Remover esta mensagem?')) return;
            await this.api(`/api/painel/sequencias/${seqId}/mensagens/${id}`, 'DELETE');
            await this.carregarMsgs(seqId);
            await this.carregar();
        },

        formatDelay(s) {
            if (s < 60)   return s + 's';
            if (s < 3600) return Math.floor(s/60) + 'min';
            return Math.floor(s/3600) + 'h';
        },

        delayParaSegundos(valor, unidade) {
            const v = parseInt(valor) || 0;
            if (unidade === 'dia')  return v * 86400;
            if (unidade === 'hora') return v * 3600;
            if (unidade === 'min')  return v * 60;
            return v; // seg
        },

        segundosParaDisplay(s) {
            const n = parseInt(s) || 0;
            if (n > 0 && n % 86400 === 0) return { valor: n / 86400, unidade: 'dia' };
            if (n > 0 && n % 3600 === 0)  return { valor: n / 3600,  unidade: 'hora' };
            if (n > 0 && n % 60 === 0)    return { valor: n / 60,    unidade: 'min' };
            return { valor: n, unidade: 'seg' };
        },

        // ── IA por coluna ─────────────────────────────────────────────────────

        async carregarIa(key) {
            if (this.iaCarregado[key]) return;
            this.iaCarregado[key] = true;
            const res = await this.api(`/api/painel/kanban/coluna-config/${key}`);
            if (res.ok) {
                const json = await res.json();
                this.objetivo[key]      = json.objetivo       ?? '';
                this.iaObjetivo[key]    = json.ia_objetivo    ?? '';
                this.iaContexto[key]    = json.ia_contexto        ?? '';
                this.iaAtivo[key]       = json.ia_ativo           ?? false;
                const delay             = this.segundosParaDisplay(json.sdr_delay_segundos ?? 45);
                this.iaDelay[key]       = delay.valor;
                this.iaDelayUnidade[key] = delay.unidade;

                const e1 = this.segundosParaDisplay(json.followup_estagio1_segundos ?? 3600);
                this.estagio1Delay[key]        = e1.valor;
                this.estagio1DelayUnidade[key] = e1.unidade;
                const e2 = this.segundosParaDisplay(json.followup_estagio2_segundos ?? 7200);
                this.estagio2Delay[key]        = e2.valor;
                this.estagio2DelayUnidade[key] = e2.unidade;
                const e3 = this.segundosParaDisplay(json.followup_estagio3_segundos ?? 21600);
                this.estagio3Delay[key]        = e3.valor;
                this.estagio3DelayUnidade[key] = e3.unidade;

                this.autoMoverAtivo[key]    = json.auto_mover_ativo          ?? false;
                this.autoMoverDestino[key]  = json.auto_mover_coluna_destino || 'encerrado';
                this.autoMoverMensagem[key] = json.auto_mover_mensagem       ?? '';
                const am = this.segundosParaDisplay(json.auto_mover_segundos ?? 259200);
                this.autoMoverDelay[key]        = am.valor;
                this.autoMoverDelayUnidade[key] = am.unidade;
            }
        },

        async salvarObjetivo(key) {
            const res = await this.api(`/api/painel/kanban/coluna-config/${key}`, 'PUT', {
                objetivo: this.objetivo[key] ?? '',
            });
            if (res.ok) {
                this.objetivoAlterado[key] = false;
                this.objetivoSalvo[key]    = true;
                setTimeout(() => { this.objetivoSalvo[key] = false; }, 3000);
            }
        },

        iniciarEditarDescricao(seq) {
            this.editandoDescricao[seq.id] = true;
            this.$nextTick(() => {
                const el = document.querySelector(`[x-ref="descricaoInput"]`);
                if (el) el.focus();
            });
        },

        async salvarDescricao(seq) {
            this.editandoDescricao[seq.id] = false;
            await this.api(`/api/painel/sequencias/${seq.id}`, 'PUT', {
                descricao: seq.descricao ?? '',
            });
        },

        async salvarIaObjetivo(key) {
            this.editandoIaObjetivo[key] = false;
            await this.api(`/api/painel/kanban/coluna-config/${key}`, 'PUT', {
                ia_objetivo: this.iaObjetivo[key] ?? '',
            });
        },

        async salvarIa(key) {
            this.iaSalvando[key] = true;
            this.iaSalvo[key]    = false;
            const res = await this.api(`/api/painel/kanban/coluna-config/${key}`, 'PUT', {
                ia_contexto:         this.iaContexto[key] ?? '',
                ia_ativo:            this.iaAtivo[key]    ?? false,
                sdr_delay_segundos:  this.delayParaSegundos(this.iaDelay[key] ?? 45, this.iaDelayUnidade[key] || 'seg'),
                followup_estagio1_segundos: this.delayParaSegundos(this.estagio1Delay[key] ?? 1, this.estagio1DelayUnidade[key] || 'hora'),
                followup_estagio2_segundos: this.delayParaSegundos(this.estagio2Delay[key] ?? 2, this.estagio2DelayUnidade[key] || 'hora'),
                followup_estagio3_segundos: this.delayParaSegundos(this.estagio3Delay[key] ?? 6, this.estagio3DelayUnidade[key] || 'hora'),
                auto_mover_ativo:           this.autoMoverAtivo[key] ?? false,
                auto_mover_coluna_destino:  this.autoMoverDestino[key] || 'encerrado',
                auto_mover_segundos:        this.delayParaSegundos(this.autoMoverDelay[key] ?? 3, this.autoMoverDelayUnidade[key] || 'dia'),
                auto_mover_mensagem:        this.autoMoverMensagem[key] ?? '',
            });
            this.iaSalvando[key] = false;
            if (res.ok) {
                this.iaAlterado[key] = false;
                this.iaSalvo[key]    = true;
                setTimeout(() => { this.iaSalvo[key] = false; }, 3000);
            }
        },

        labelColuna(key) {
            const col = this.colunas.find(c => c.key === key);
            return col ? col.emoji + ' ' + col.label : key || '—';
        },

        // ── ✨ Aplicar Variáveis com IA ───────────────────────────────────────

        mostrarToast(texto, tipo = 'ok') {
            this.toast = { visivel: true, tipo, texto };
            setTimeout(() => { this.toast.visivel = false; }, 5000);
        },

        async sugerirVariaveis(seq) {
            if (this.analisandoSeqId === seq.id) return;
            this.analisandoSeqId = seq.id;

            try {
                const res = await this.api(`/api/painel/sequencias/${seq.id}/sugerir-variaveis`, 'POST');
                this.analisandoSeqId = null;

                if (!res.ok) {
                    const json = await res.json().catch(() => ({}));
                    this.mostrarToast(json.message || 'Erro ao analisar com a IA. Tente novamente.', 'erro');
                    return;
                }

                const json = await res.json();

                if (!json.sugestoes || json.sugestoes.length === 0) {
                    this.mostrarToast('Nenhuma mensagem de texto encontrada para analisar.', 'aviso');
                    return;
                }

                this.varSugestoes    = json.sugestoes;
                this.varSeqNome      = seq.nome;
                this.varSeqId        = seq.id;
                this.varSelecionados = {};
                json.sugestoes.forEach(s => {
                    this.varSelecionados[s.id] = s.alterado;
                });

                this.modalVar = true;
            } catch (e) {
                this.analisandoSeqId = null;
                this.mostrarToast('Erro de conexão. Verifique sua internet e tente novamente.', 'erro');
            }
        },

        varContarSelecionados() {
            return this.varSugestoes.filter(s => this.varSelecionados[s.id] && s.alterado).length;
        },

        async confirmarVariaveis() {
            if (this.varAplicando) return;
            this.varAplicando = true;

            const selecionados = this.varSugestoes.filter(s => this.varSelecionados[s.id] && s.alterado);
            for (const s of selecionados) {
                await this.api(`/api/painel/sequencias/${this.varSeqId}/mensagens/${s.id}`, 'PUT', {
                    conteudo: s.sugerido,
                });
            }

            this.varAplicando = false;
            this.modalVar     = false;
            await this.carregarMsgs(this.varSeqId);
        },

        destacarVariaveis(texto) {
            if (!texto) return '';
            const escaped = texto
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/\n/g, '<br>');
            return escaped.replace(/\{([a-z_]+)\}/g,
                '<span style="display:inline-block;padding:1px 5px;margin:0 1px;background:#e0e7ff;color:#4338ca;border-radius:5px;font-family:monospace;font-size:0.7rem;font-weight:700">{$1}</span>'
            );
        },

        async api(url, method = 'GET', body = null) {
            return fetch(url, {
                method,
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                },
                body: body ? JSON.stringify(body) : null,
            });
        },
    };
}
</script>
@endpush
