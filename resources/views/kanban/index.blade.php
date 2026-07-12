@extends('layouts.app')

@section('title', 'Kanban — Lead Certo')

@section('content')
<div x-data="kanban()" x-init="carregar()" class="h-full">

    <div class="flex items-center justify-between mb-5">
        <h1 class="text-xl font-bold text-gray-800">Atendimentos</h1>
        <div class="flex items-center gap-3">
            <span class="text-xs text-gray-400">Atualiza a cada 5s</span>
            @if(auth()->user()->isDono())
            <a href="{{ route('kanban.config') }}"
               title="Configurações do Kanban"
               class="text-gray-400 hover:text-gray-600 p-1.5 rounded-lg hover:bg-gray-100 transition-colors">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                          d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/>
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                </svg>
            </a>
            @endif
        </div>
    </div>

    {{-- Colunas Kanban --}}
    <div class="flex gap-4 overflow-x-auto pb-4" style="height: calc(100vh - 160px)">

        <template x-for="coluna in colunas" :key="coluna.key">
            <div class="flex-shrink-0 w-72 rounded-xl transition-colors duration-150 flex flex-col h-full"
                 :style="dragOver === coluna.key ? 'background:#f0fdf4;outline:2px dashed #16a34a;outline-offset:4px' : ''"
                 @dragover.prevent="dragOver = coluna.key"
                 @dragleave="if (!$el.contains($event.relatedTarget)) dragOver = null"
                 @drop.prevent="soltar(coluna.key)">
                <div class="flex items-center justify-between mb-3 px-1 flex-shrink-0">
                    <span class="text-sm font-semibold"
                          :class="coluna.key === 'outros' ? 'text-gray-400' : 'text-gray-600'"
                          x-text="coluna.label"></span>
                    <span class="text-xs rounded-full px-2 py-0.5"
                          :class="coluna.key === 'outros' ? 'bg-gray-100 text-gray-400' : 'bg-gray-200 text-gray-600'"
                          x-text="totalPorColuna[coluna.key] ?? (tickets[coluna.key] || []).length"></span>
                </div>

                <div class="space-y-2 flex-1 overflow-y-auto pr-1"
                     :class="coluna.key === 'outros' ? 'border-l-2 border-dashed border-gray-200 pl-3' : ''"
                     style="min-height: 5rem">
                    <template x-for="ticket in (tickets[coluna.key] || [])" :key="ticket.id">
                        <div :class="ticket.precisa_resposta
                                ? 'bg-blue-50 border-2 border-blue-300 rounded-xl shadow-sm p-4 cursor-grab hover:shadow-md transition-shadow'
                                : (coluna.key === 'outros' ? 'bg-gray-50 rounded-xl shadow-sm p-4 cursor-grab hover:shadow-md transition-shadow border border-gray-200' : 'bg-white rounded-xl shadow-sm p-4 cursor-grab hover:shadow-md transition-shadow')"
                             :style="dragCard?.id === ticket.id ? 'opacity:0.4;cursor:grabbing' : ''"
                             draggable="true"
                             @dragstart="iniciarDrag($event, ticket)"
                             @dragend="terminarDrag()"
                             @click="handleCardClick(ticket)">

                            <div class="flex items-start justify-between gap-2">
                                <div>
                                    <div class="flex items-center gap-1.5">
                                        <p class="text-sm font-medium text-gray-800"
                                           x-text="ticket.contato?.nome_local || ticket.contato?.nome || ticket.contato?.telefone || '#' + ticket.id"></p>
                                        <span x-show="ticket.contato?.auditoria_pendente"
                                              title="Nome em revisão pelo Auditor"
                                              class="inline-block w-2 h-2 rounded-full bg-yellow-400 flex-shrink-0"></span>
                                    </div>
                                    <p class="text-xs text-gray-400 mt-0.5"
                                       x-text="[ticket.contato?.telefone, ticket.contato?.id].filter(Boolean).join(' · ')"></p>
                                </div>
                                <div class="flex flex-col items-end gap-1">
                                    <template x-if="ticket.agente_responsavel === 'bot'">
                                        <span class="text-xs bg-blue-100 text-blue-600 px-2 py-0.5 rounded-full">Bot</span>
                                    </template>
                                    <template x-if="ticket.agente_responsavel === 'humano'">
                                        <span class="text-xs bg-green-100 text-green-600 px-2 py-0.5 rounded-full">Humano</span>
                                    </template>
                                    <template x-if="ticket.pendente_desde">
                                        <span class="text-xs bg-orange-100 text-orange-600 px-2 py-0.5 rounded-full">Pendente</span>
                                    </template>
                                    <template x-if="ticket.status === 'resolvido'">
                                        <span class="text-xs bg-teal-100 text-teal-600 px-2 py-0.5 rounded-full">Resolvido</span>
                                    </template>
                                </div>
                            </div>

                            <div class="flex items-center justify-between mt-3">
                                <span class="text-xs text-gray-400"
                                      x-text="dataRelativa(ticket.aberto_em)"></span>
                                <template x-if="ticket.count_midias > 0">
                                    <span class="text-xs text-purple-600">📎 <span x-text="ticket.count_midias"></span></span>
                                </template>
                            </div>

                            {{-- Badge de retorno agendado --}}
                            <template x-if="ticket.retorno_agendado_em">
                                <div class="mt-2 flex items-center gap-1 text-xs px-2 py-1 rounded-lg"
                                     :style="retornoBadgeStyle(ticket.retorno_agendado_em)">
                                    <svg class="w-3 h-3 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                              d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                                    </svg>
                                    <span x-text="'Retorno ' + formatarData(ticket.retorno_agendado_em)"></span>
                                </div>
                            </template>

                            <template x-if="!ticket.vendedor_id">
                                <button @click.stop="assumir(ticket.id)"
                                        class="mt-3 w-full text-xs bg-green-600 hover:bg-green-500 text-white py-1.5 rounded-lg transition-colors">
                                    Assumir
                                </button>
                            </template>
                            <template x-if="ticket.vendedor_id">
                                <p class="mt-2 text-xs text-gray-400 truncate"
                                   x-text="'Com: ' + (ticket.vendedor?.nome || '—')"></p>
                            </template>
                        </div>
                    </template>
                </div>
            </div>
        </template>

    </div>

    {{-- Modal de atendimento --}}
    <template x-if="ticketAtivo">
        <div class="fixed inset-0 bg-black/50 z-50 flex items-end md:items-center justify-center p-4"
             @click.self="ticketAtivo = null">
            <div class="bg-white rounded-2xl w-full max-w-lg shadow-2xl flex flex-col max-h-[90vh]">

                {{-- Header --}}
                <div class="flex items-start justify-between gap-2 flex-wrap px-5 py-4 border-b">
                    <div>
                        {{-- Nome editável --}}
                        <div x-show="!editandoNome" class="flex items-center gap-1.5">
                            <p class="font-semibold text-gray-800"
                               x-text="ticketAtivo.contato?.nome_local || ticketAtivo.contato?.nome || ticketAtivo.contato?.telefone"></p>
                            <span x-show="ticketAtivo.contato?.auditoria_pendente"
                                  class="text-xs bg-yellow-100 text-yellow-700 px-1.5 py-0.5 rounded font-normal"
                                  title="Nome sugerido aguarda aprovação do Auditor">Em revisão</span>
                            <button @click="editandoNome = true; nomeEdit = ticketAtivo.contato?.nome_local || ticketAtivo.contato?.nome || ''"
                                    class="text-gray-300 hover:text-gray-500 transition-colors">
                                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                          d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z"/>
                                </svg>
                            </button>
                            <a :href="'{{ route('contatos.importar') }}?abrir=' + ticketAtivo.contato?.id"
                               target="_blank"
                               title="Abrir ficha completa do contato numa aba nova"
                               class="text-gray-300 hover:text-blue-500 transition-colors">
                                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                          d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"/>
                                </svg>
                            </a>
                        </div>
                        <div x-show="editandoNome" class="flex items-center gap-2">
                            <input x-model="nomeEdit" type="text"
                                   @keydown.enter="salvarNome()"
                                   @keydown.escape="editandoNome = false"
                                   x-ref="inputNome"
                                   class="text-sm font-semibold border-b-2 border-green-400 focus:outline-none bg-transparent w-40"
                                   placeholder="Nome do contato">
                            <button @click="salvarNome()"
                                    class="text-xs text-green-600 font-medium hover:underline">Salvar</button>
                            <button @click="editandoNome = false"
                                    class="text-xs text-gray-400 hover:underline">×</button>
                        </div>
                        <p class="text-xs text-gray-400"
                           x-text="[ticketAtivo.contato?.telefone, ticketAtivo.contato?.id].filter(Boolean).join(' · ')"></p>
                        {{-- Retorno agendado --}}
                        <div class="flex items-center gap-1 mt-1">
                            <svg class="w-3 h-3 text-gray-400 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                      d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                            </svg>
                            <input type="date"
                                   :value="ticketAtivo.retorno_agendado_em ? ticketAtivo.retorno_agendado_em.substring(0,10) : ''"
                                   @change="salvarRetorno($event.target.value)"
                                   class="text-xs text-gray-500 border-0 focus:outline-none focus:ring-0 bg-transparent cursor-pointer p-0"
                                   title="Agendar data de retorno">
                            <template x-if="ticketAtivo.retorno_agendado_em">
                                <button @click="salvarRetorno(null)"
                                        class="text-gray-300 hover:text-red-400 text-sm leading-none ml-0.5"
                                        title="Limpar retorno">×</button>
                            </template>
                        </div>
                    </div>
                    <div class="flex items-center gap-1.5 flex-wrap justify-end">
                        <div class="flex items-center gap-1">
                            <select x-model="moverColunaAlvo"
                                    class="text-xs border border-gray-300 rounded-lg px-2 py-1.5 bg-white text-gray-700">
                                <template x-for="c in colunas" :key="c.key">
                                    <option :value="c.key" x-text="c.label"></option>
                                </template>
                            </select>
                            <button @click="moverColunaConfirmar(ticketAtivo, moverColunaAlvo)"
                                    :disabled="moverColunaAlvo === ticketAtivo.coluna_kanban"
                                    class="text-xs bg-blue-100 text-blue-700 px-2.5 py-1.5 rounded-lg hover:bg-blue-200 disabled:opacity-40 disabled:cursor-not-allowed transition-colors"
                                    title="Mover manualmente pra outra coluna">
                                Mover
                            </button>
                        </div>
                        <template x-if="ticketAtivo.vendedor_id && !['resolvido','encerrado'].includes(ticketAtivo.status)">
                            <div class="flex gap-1">
                                <button @click="liberar(ticketAtivo.id)"
                                        class="text-xs bg-yellow-100 text-yellow-700 px-2.5 py-1.5 rounded-lg hover:bg-yellow-200 transition-colors"
                                        title="Devolve o controle ao bot (IA responde na próxima mensagem do lead)">
                                    Devolver
                                </button>
                                <button @click="liberarEAcionarIA(ticketAtivo.id)"
                                        class="text-xs bg-purple-100 text-purple-700 px-2.5 py-1.5 rounded-lg hover:bg-purple-200 transition-colors"
                                        title="Devolve e aciona a IA agora, sem precisar esperar o lead responder">
                                    Devolver + IA
                                </button>
                            </div>
                        </template>
                        <template x-if="!['resolvido','encerrado'].includes(ticketAtivo.status)">
                            <button @click="marcarPendente(ticketAtivo.id)"
                                    :class="ticketAtivo.pendente_desde ? 'bg-orange-500 text-white hover:bg-orange-600' : 'bg-orange-100 text-orange-600 hover:bg-orange-200'"
                                    class="text-xs px-2.5 py-1.5 rounded-lg transition-colors"
                                    title="Etiqueta: tenho uma pergunta em aberto com o lead, aguardando resposta">
                                <span x-text="ticketAtivo.pendente_desde ? 'Pendente ✓' : 'Pendente'"></span>
                            </button>
                        </template>
                        <template x-if="ticketAtivo.coluna_kanban === 'aguardando_orcamento' && !['resolvido','encerrado'].includes(ticketAtivo.status)">
                            <button @click="orcamentoEnviado(ticketAtivo.id)"
                                    class="text-xs bg-orange-500 hover:bg-orange-600 text-white px-2.5 py-1.5 rounded-lg transition-colors font-medium">
                                Orçamento Enviado ✓
                            </button>
                        </template>
                        <button @click="ticketAtivo = null"
                                class="text-gray-400 hover:text-gray-600 text-xl leading-none ml-1">&times;</button>
                    </div>
                </div>

                {{-- Notas do contato --}}
                <template x-if="ticketAtivo.contato?.id">
                    <div class="border-b">
                        <button @click="notasAberto = !notasAberto"
                                class="w-full flex items-center justify-between px-5 py-2 text-xs text-gray-500 hover:bg-gray-50 transition-colors">
                            <span class="flex items-center gap-1.5">
                                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/>
                                </svg>
                                <span x-text="notas.length > 0 ? 'Notas (' + notas.length + ')' : 'Notas'"></span>
                            </span>
                            <svg class="w-3.5 h-3.5 transition-transform duration-200" :class="notasAberto ? 'rotate-180' : ''" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
                            </svg>
                        </button>
                        <template x-if="notasAberto">
                            <div class="px-5 pb-3 space-y-1.5 max-h-44 overflow-y-auto">
                                <template x-if="notas.length === 0">
                                    <p class="text-xs text-gray-400 text-center py-1">Sem notas ainda.</p>
                                </template>
                                <template x-for="nota in notas" :key="nota.id">
                                    <div class="bg-yellow-50 border border-yellow-200 rounded-lg px-3 py-2">
                                        <p class="text-xs text-gray-700 leading-snug" x-text="nota.texto"></p>
                                        <div class="flex items-center justify-between mt-1">
                                            <span class="text-xs text-gray-400"
                                                  x-text="nota.autor + ' · ' + new Date(nota.created_at).toLocaleDateString('pt-BR')"></span>
                                            <button @click="excluirNota(nota.id)"
                                                    class="text-gray-300 hover:text-red-400 transition-colors text-sm leading-none">×</button>
                                        </div>
                                    </div>
                                </template>
                                <div class="flex gap-2 pt-1">
                                    <input x-model="novaNota" type="text"
                                           placeholder="Adicionar nota sobre este contato..."
                                           @keydown.enter.prevent="salvarNota()"
                                           class="flex-1 border border-gray-300 rounded-lg px-3 py-1.5 text-xs focus:outline-none focus:ring-1 focus:ring-yellow-400">
                                    <button @click="salvarNota()"
                                            :disabled="salvandoNota || !novaNota.trim()"
                                            class="bg-yellow-400 hover:bg-yellow-300 disabled:opacity-40 text-gray-800 px-3 py-1.5 rounded-lg text-xs font-medium transition-colors">
                                        Salvar
                                    </button>
                                </div>
                            </div>
                        </template>
                    </div>
                </template>

                {{-- Resumo IA --}}
                <template x-if="ticketAtivo.resumo_ia">
                    <div class="border-b">
                        <button @click="resumoAberto = !resumoAberto"
                                class="w-full flex items-center justify-between px-5 py-2 text-xs text-gray-500 hover:bg-gray-50 transition-colors">
                            <span class="flex items-center gap-1.5">
                                <svg class="w-3.5 h-3.5 text-purple-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.663 17h4.673M12 3v1m6.364 1.636l-.707.707M21 12h-1M4 12H3m3.343-5.657l-.707-.707m2.828 9.9a5 5 0 117.072 0l-.548.547A3.374 3.374 0 0014 18.469V19a2 2 0 11-4 0v-.531c0-.895-.356-1.754-.988-2.386l-.548-.547z"/>
                                </svg>
                                Resumo IA
                            </span>
                            <svg class="w-3.5 h-3.5 transition-transform duration-200" :class="resumoAberto ? 'rotate-180' : ''" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
                            </svg>
                        </button>
                        <template x-if="resumoAberto">
                            <div class="px-5 pb-3">
                                <p class="text-xs text-gray-600 leading-relaxed bg-purple-50 border border-purple-100 rounded-lg px-3 py-2"
                                   x-text="ticketAtivo.resumo_ia"></p>
                            </div>
                        </template>
                    </div>
                </template>

                {{-- Mensagens --}}
                <div class="flex-1 overflow-y-auto px-5 py-4 space-y-2" x-ref="msgBox">
                    <template x-for="msg in mensagens" :key="msg.id">
                        <div :class="msg.remetente === 'lead' ? 'flex justify-start' : 'flex justify-end'">
                            <div :class="msg.remetente === 'lead'
                                    ? 'bg-gray-100 text-gray-800 rounded-br-xl rounded-tr-xl rounded-bl-sm'
                                    : 'bg-green-600 text-white rounded-bl-xl rounded-tl-xl rounded-br-sm'"
                                 class="max-w-xs px-3.5 py-2.5 text-sm rounded-2xl">

                                {{-- Texto --}}
                                <template x-if="msg.tipo === 'texto'">
                                    <p x-text="msg.conteudo" class="whitespace-pre-wrap break-words"></p>
                                </template>

                                {{-- Imagem --}}
                                <template x-if="msg.tipo === 'imagem' && msg.midia_url">
                                    <div>
                                        <a :href="msg.midia_url" target="_blank">
                                            <img :src="msg.midia_url" class="rounded-lg max-w-full max-h-48 object-cover" loading="lazy">
                                        </a>
                                        <template x-if="msg.conteudo && msg.conteudo !== '[Imagem]'">
                                            <p class="text-xs mt-1 opacity-80" x-text="msg.conteudo"></p>
                                        </template>
                                    </div>
                                </template>

                                {{-- Áudio --}}
                                <template x-if="msg.tipo === 'audio' && msg.midia_url">
                                    <audio controls class="w-full max-w-[220px] h-8" :src="msg.midia_url"></audio>
                                </template>

                                {{-- Documento --}}
                                <template x-if="msg.tipo === 'documento' && msg.midia_url">
                                    <a :href="msg.midia_url" target="_blank"
                                       class="flex items-center gap-2 opacity-90 hover:opacity-100">
                                        <svg class="w-5 h-5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                                  d="M7 21h10a2 2 0 002-2V9.414a1 1 0 00-.293-.707l-5.414-5.414A1 1 0 0012.586 3H7a2 2 0 00-2 2v14a2 2 0 002 2z"/>
                                        </svg>
                                        <span class="text-xs underline truncate max-w-[180px]" x-text="msg.conteudo || 'Arquivo'"></span>
                                    </a>
                                </template>

                                {{-- Vídeo --}}
                                <template x-if="msg.tipo === 'video' && msg.midia_url">
                                    <a :href="msg.midia_url" target="_blank" class="text-xs underline opacity-80">Ver vídeo</a>
                                </template>

                                <p class="text-xs opacity-50 mt-1 text-right"
                                   x-text="new Date(msg.enviado_em).toLocaleTimeString('pt-BR', {hour:'2-digit', minute:'2-digit'})"></p>
                            </div>
                        </div>
                    </template>
                </div>

                {{-- Input --}}
                <template x-if="ticketAtivo.status !== 'encerrado'">
                    <div class="px-4 pb-4 pt-2 border-t">
                        <template x-if="ticketAtivo.agente_responsavel !== 'humano'">
                            <div class="text-center text-xs text-gray-400 py-2">
                                <button @click="assumir(ticketAtivo.id)"
                                        class="text-green-600 font-medium hover:underline">
                                    Assumir atendimento
                                </button>
                                para enviar mensagens
                            </div>
                        </template>
                        <template x-if="ticketAtivo.agente_responsavel === 'humano'">
                            <div>
                                {{-- Preview de mídia selecionada --}}
                                <template x-if="midiaPreview || audioPreviewUrl">
                                    <div class="mb-2 p-2 bg-gray-50 border border-gray-200 rounded-xl flex items-center gap-3">
                                        {{-- Preview imagem --}}
                                        <template x-if="midiaTipo === 'imagem' && midiaPreview">
                                            <img :src="midiaPreview" class="h-12 w-12 object-cover rounded-lg flex-shrink-0">
                                        </template>
                                        {{-- Preview documento --}}
                                        <template x-if="midiaTipo === 'documento' && midiaPreview">
                                            <div class="h-12 w-12 bg-blue-100 rounded-lg flex items-center justify-center flex-shrink-0">
                                                <svg class="w-6 h-6 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                                          d="M7 21h10a2 2 0 002-2V9.414a1 1 0 00-.293-.707l-5.414-5.414A1 1 0 0012.586 3H7a2 2 0 00-2 2v14a2 2 0 002 2z"/>
                                                </svg>
                                            </div>
                                        </template>
                                        {{-- Preview áudio gravado --}}
                                        <template x-if="audioPreviewUrl">
                                            <audio controls :src="audioPreviewUrl" class="h-8 flex-1"></audio>
                                        </template>
                                        {{-- Nome do arquivo --}}
                                        <template x-if="midiaArquivo && !audioPreviewUrl">
                                            <span class="text-xs text-gray-600 truncate flex-1" x-text="midiaArquivo.name"></span>
                                        </template>
                                        <button @click="limparMidia()"
                                                class="text-gray-400 hover:text-red-500 text-xl leading-none flex-shrink-0">×</button>
                                    </div>
                                </template>

                                {{-- Sugestões de resposta pronta --}}
                                <template x-if="sugestoes.length > 0">
                                    <div class="mb-2 border border-gray-200 rounded-xl overflow-hidden shadow-sm">
                                        <template x-for="(s, i) in sugestoes" :key="s.id">
                                            <button @click="aplicarResposta(s)"
                                                    :class="i === sugestaoSelecionada ? 'bg-green-50' : 'bg-white hover:bg-gray-50'"
                                                    class="w-full text-left px-4 py-2.5 border-b border-gray-100 last:border-0 transition-colors">
                                                <span class="text-xs font-mono text-green-700 mr-2" x-text="'/' + s.codigo_curto"></span>
                                                <span class="text-xs text-gray-500 truncate" x-text="s.conteudo"></span>
                                            </button>
                                        </template>
                                    </div>
                                </template>

                                {{-- Barra de input --}}
                                <div class="flex items-center gap-1.5">
                                    {{-- Botão Anexo --}}
                                    <input type="file" x-ref="fileInput"
                                           accept="image/*,application/pdf,.doc,.docx,.xls,.xlsx,.txt,.zip"
                                           class="hidden"
                                           @change="selecionarArquivo($event)">
                                    <button type="button"
                                            @click="$refs.fileInput.click()"
                                            title="Anexar imagem ou documento"
                                            :class="midiaArquivo ? 'text-green-600 bg-green-50' : 'text-gray-400 hover:text-gray-600'"
                                            class="p-2 rounded-lg hover:bg-gray-100 transition-colors flex-shrink-0">
                                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                                  d="M15.172 7l-6.586 6.586a2 2 0 102.828 2.828l6.414-6.586a4 4 0 00-5.656-5.656l-6.415 6.585a6 6 0 108.486 8.486L20.5 13"/>
                                        </svg>
                                    </button>

                                    {{-- Botão Gravar / Parar --}}
                                    <button type="button"
                                            @click="gravandoAudio ? pararGravacao() : iniciarGravacao()"
                                            :title="gravandoAudio ? 'Parar gravação' : 'Gravar áudio'"
                                            :class="gravandoAudio ? 'text-red-600 bg-red-50 animate-pulse' : (audioPreviewUrl ? 'text-green-600 bg-green-50' : 'text-gray-400 hover:text-gray-600')"
                                            class="p-2 rounded-lg hover:bg-gray-100 transition-colors flex-shrink-0">
                                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                                  d="M19 11a7 7 0 01-7 7m0 0a7 7 0 01-7-7m7 7v4m0 0H8m4 0h4m-4-8a3 3 0 01-3-3V5a3 3 0 116 0v6a3 3 0 01-3 3z"/>
                                        </svg>
                                    </button>

                                    {{-- Input de texto (oculto durante gravação) --}}
                                    <template x-if="!gravandoAudio && !audioPreviewUrl">
                                        <input x-model="novaMensagem" type="text"
                                               placeholder="Digite / para respostas prontas..."
                                               @input="onInputMensagem()"
                                               @keydown.escape="sugestoes = []"
                                               @keydown.arrow-up.prevent="navegarSugestao(-1)"
                                               @keydown.arrow-down.prevent="navegarSugestao(1)"
                                               @keydown.enter.prevent="sugestoes.length > 0 ? aplicarResposta(sugestoes[sugestaoSelecionada]) : (midiaArquivo ? enviarMidia() : enviarMensagem())"
                                               class="flex-1 border border-gray-300 rounded-xl px-4 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-green-500">
                                    </template>
                                    <template x-if="gravandoAudio">
                                        <div class="flex-1 flex items-center gap-2 px-3 py-2 bg-red-50 rounded-xl">
                                            <span class="w-2 h-2 rounded-full bg-red-500 animate-pulse"></span>
                                            <span class="text-xs text-red-600 font-medium">Gravando… toque no microfone para parar</span>
                                        </div>
                                    </template>
                                    <template x-if="audioPreviewUrl && !gravandoAudio">
                                        <div class="flex-1 text-xs text-gray-500 px-2">Pronto para enviar</div>
                                    </template>

                                    {{-- Botão Enviar --}}
                                    <button type="button"
                                            @click="midiaArquivo || audioPreviewUrl ? enviarMidia() : enviarMensagem()"
                                            :disabled="enviandoMidia || gravandoAudio || (!novaMensagem.trim() && !midiaArquivo && !audioPreviewUrl)"
                                            class="bg-green-600 hover:bg-green-500 disabled:opacity-40 text-white px-4 py-2 rounded-xl text-sm transition-colors flex-shrink-0">
                                        <template x-if="enviandoMidia">
                                            <span>Enviando…</span>
                                        </template>
                                        <template x-if="!enviandoMidia">
                                            <span>Enviar</span>
                                        </template>
                                    </button>
                                </div>
                            </div>
                        </template>
                    </div>
                </template>

            </div>
        </div>
    </template>

    {{-- Modal encerrar --}}
    <template x-if="encerrarModal">
        <div class="fixed inset-0 bg-black/50 z-[60] flex items-center justify-center p-4">
            <div class="bg-white rounded-2xl p-6 w-full max-w-sm shadow-2xl">
                <h3 class="font-semibold text-gray-800 mb-4">Encerrar atendimento</h3>
                <label class="block text-sm text-gray-600 mb-2">Motivo de desfecho</label>
                <select x-model="tagDesfecho"
                        class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-green-500 mb-4">
                    <option value="">Selecione...</option>
                    <option value="venda_fechada">Venda fechada</option>
                    <option value="sem_interesse">Sem interesse</option>
                    <option value="preco_alto">Preço alto</option>
                    <option value="sem_resposta">Sem resposta</option>
                    <option value="fora_de_area">Fora de área</option>
                    <option value="outro">Outro</option>
                </select>
                <div class="flex gap-2">
                    <button @click="encerrarModal = false"
                            class="flex-1 border border-gray-300 text-gray-600 py-2 rounded-lg text-sm hover:bg-gray-50">
                        Cancelar
                    </button>
                    <button @click="encerrar()"
                            :disabled="!tagDesfecho"
                            class="flex-1 bg-red-600 hover:bg-red-500 disabled:opacity-40 text-white py-2 rounded-lg text-sm transition-colors">
                        Confirmar
                    </button>
                </div>
            </div>
        </div>
    </template>

</div>

<script>
function kanban() {
    return {
        colunas: [
            { key: 'lead_novo',           label: 'Novo' },
            { key: 'em_atendimento',      label: 'Em Atendimento' },
            { key: 'aguardando_orcamento',label: 'Aguardando Orçamento' },
            { key: 'aguardando_lead',     label: 'Aguardando Lead' },
            { key: 'pagamento',           label: 'Pagamento' },
            { key: 'servico_agendado',    label: 'Serviço Agendado' },
            { key: 'encerrado',           label: 'Encerrado' },
            { key: 'outros',              label: 'Outros / Internos' },
        ],
        tickets:        {},
        totalPorColuna: {},
        ticketAtivo:  null,
        moverColunaAlvo: '',
        mensagens:    [],
        novaMensagem:      '',
        encerrarModal:     false,
        tagDesfecho:       '',
        intervalo:         null,
        editandoNome:      false,
        nomeEdit:          '',
        sugestoes:         [],
        sugestaoSelecionada: 0,
        notas:             [],
        notasAberto:       false,
        resumoAberto:      false,
        novaNota:          '',
        salvandoNota:      false,
        dragCard:          null,
        dragOver:          null,
        dragOccurred:      false,
        // Mídia
        midiaArquivo:      null,
        midiaPreview:      null,
        midiaTipo:         null,
        gravandoAudio:     false,
        mediaRecorder:     null,
        audioChunks:       [],
        audioBlob:         null,
        audioPreviewUrl:   null,
        enviandoMidia:     false,

        async carregar() {
            const res = await this.api('/api/painel/kanban/tickets');
            if (!res.ok) return;

            const data = await res.json();
            const novosTickets = {};
            for (const c of this.colunas) {
                const entrada = data[c.key] || { tickets: [], total: 0 };
                novosTickets[c.key] = entrada.tickets;
                this.totalPorColuna[c.key] = entrada.total;
            }
            this.tickets = novosTickets;
        },

        iniciarDrag(event, ticket) {
            this.dragCard     = ticket;
            this.dragOccurred = false;
            event.dataTransfer.effectAllowed = 'move';
            event.dataTransfer.setData('text/plain', String(ticket.id));
        },

        terminarDrag() {
            this.dragOccurred = true;
            this.dragCard     = null;
            this.dragOver     = null;
            setTimeout(() => { this.dragOccurred = false; }, 100);
        },

        handleCardClick(ticket) {
            if (this.dragOccurred) return;
            this.abrirTicket(ticket);
        },

        async soltar(colunaDestino) {
            const ticket = this.dragCard;
            this.dragOver = null;

            if (!ticket || ticket.coluna_kanban === colunaDestino) {
                this.dragCard = null;
                return;
            }

            const origem  = ticket.coluna_kanban;
            const ticketId = ticket.id;

            // Move otimisticamente no estado local
            this.tickets[origem]       = (this.tickets[origem]       || []).filter(t => t.id !== ticketId);
            this.tickets[colunaDestino] = [...(this.tickets[colunaDestino] || []), { ...ticket, coluna_kanban: colunaDestino }];
            this.dragCard = null;

            const res = await this.api(`/api/painel/kanban/ticket/${ticketId}/mover`, 'POST', { coluna: colunaDestino });
            if (!res.ok) {
                // Reverte em caso de erro
                await this.carregar();
            }
        },

        async moverTicketParaColuna(ticket, colunaDestino) {
            if (!ticket || !colunaDestino || ticket.coluna_kanban === colunaDestino) return;

            const origem   = ticket.coluna_kanban;
            const ticketId = ticket.id;

            this.tickets[origem]       = (this.tickets[origem]       || []).filter(t => t.id !== ticketId);
            this.tickets[colunaDestino] = [...(this.tickets[colunaDestino] || []), { ...ticket, coluna_kanban: colunaDestino }];
            ticket.coluna_kanban = colunaDestino;

            const res = await this.api(`/api/painel/kanban/ticket/${ticketId}/mover`, 'POST', { coluna: colunaDestino });
            if (!res.ok) {
                await this.carregar();
            }
        },

        // Decide o fluxo certo conforme a coluna escolhida no seletor "Mover":
        // Outros e Encerrado têm efeitos além de só mudar a coluna (transferir
        // pra humano / marcar motivo e disparar relatórios), então reaproveitam
        // os fluxos que já existiam pra isso em vez de só mover.
        moverColunaConfirmar(ticket, colunaDestino) {
            if (!ticket || !colunaDestino || colunaDestino === ticket.coluna_kanban) return;

            if (colunaDestino === 'outros') {
                this.moverParaOutros(ticket.id);
            } else if (colunaDestino === 'encerrado') {
                this.tagDesfecho = '';
                this.encerrarModal = true;
            } else {
                this.moverTicketParaColuna(ticket, colunaDestino);
            }
        },

        async salvarRetorno(data) {
            if (!this.ticketAtivo) return;
            // Ignora datas com ano incompleto (navegador dispara change a cada dígito)
            if (data && parseInt(String(data).substring(0, 4)) < 2000) return;
            const res = await this.api(`/api/painel/kanban/ticket/${this.ticketAtivo.id}/retorno`, 'POST', { retorno_em: data || null });
            if (res.ok) {
                const json = await res.json();
                this.ticketAtivo.retorno_agendado_em = json.retorno_agendado_em;
                // Atualiza também no estado local do kanban
                const col = this.ticketAtivo.coluna_kanban;
                const idx = (this.tickets[col] || []).findIndex(t => t.id === this.ticketAtivo.id);
                if (idx >= 0) this.tickets[col][idx].retorno_agendado_em = json.retorno_agendado_em;
            }
        },

        async salvarNome() {
            const nome = this.nomeEdit.trim();
            if (!nome || !this.ticketAtivo?.contato?.id) return;

            const res = await this.api(`/api/painel/contato/${this.ticketAtivo.contato.id}`, 'PATCH', { nome });
            if (res.ok) {
                const data = await res.json();
                if (data.auditoria) {
                    // Nome vai para fila do Auditor — exibe localmente com badge
                    this.ticketAtivo.contato.nome_local        = nome;
                    this.ticketAtivo.contato.auditoria_pendente = true;
                } else {
                    // Master atualizado diretamente (nome estava vazio)
                    this.ticketAtivo.contato.nome       = nome;
                    this.ticketAtivo.contato.nome_local = null;
                }
                this.editandoNome = false;
                await this.carregar();
            }
        },

        async abrirTicket(ticket) {
            this.ticketAtivo  = ticket;
            this.editandoNome = false;
            this.novaMensagem = '';
            this.notas        = [];
            this.notasAberto  = false;
            this.novaNota     = '';
            this.moverColunaAlvo = ticket.coluna_kanban;
            this.limparMidia();
            await this.carregarMensagens(ticket.id);
            if (ticket.contato?.id) await this.carregarNotas(ticket.contato.id);
            this.$nextTick(() => {
                const box = this.$refs.msgBox;
                if (box) box.scrollTop = box.scrollHeight;
            });
        },

        async carregarNotas(contatoId) {
            const res = await this.api(`/api/painel/contato/${contatoId}/notas`);
            if (res.ok) {
                const json = await res.json();
                this.notas = json.data;
            }
        },

        async salvarNota() {
            const texto = this.novaNota.trim();
            if (!texto || !this.ticketAtivo?.contato?.id) return;
            this.salvandoNota = true;
            const res = await this.api(`/api/painel/contato/${this.ticketAtivo.contato.id}/notas`, 'POST', { texto });
            this.salvandoNota = false;
            if (res.ok) {
                this.novaNota = '';
                await this.carregarNotas(this.ticketAtivo.contato.id);
            }
        },

        async excluirNota(id) {
            if (!confirm('Excluir esta nota?')) return;
            const res = await this.api(`/api/painel/notas/${id}`, 'DELETE');
            if (res.ok) await this.carregarNotas(this.ticketAtivo.contato.id);
        },

        async liberar(id) {
            const res = await this.api(`/api/painel/kanban/ticket/${id}/liberar`, 'POST');
            if (res.ok) {
                this.ticketAtivo.vendedor_id        = null;
                this.ticketAtivo.agente_responsavel = 'bot';
                await this.carregar();
            }
        },

        async liberarEAcionarIA(id) {
            const res = await this.api(`/api/painel/kanban/ticket/${id}/liberar-ia`, 'POST');
            if (res.ok) {
                this.ticketAtivo.vendedor_id        = null;
                this.ticketAtivo.agente_responsavel = 'bot';
                await this.carregar();
            }
        },

        async marcarPendente(id) {
            const res = await this.api(`/api/painel/kanban/ticket/${id}/pendente`, 'POST');
            if (res.ok) {
                const json = await res.json();
                this.ticketAtivo.pendente_desde = json.pendente_desde;
                await this.carregar();
            }
        },

        async moverParaOutros(id) {
            if (!confirm('Mover para "Outros / Internos"? Este ticket sairá do funil de vendas.')) return;
            const res = await this.api(`/api/painel/kanban/ticket/${id}/outros`, 'POST');
            if (res.ok) {
                this.ticketAtivo = null;
                await this.carregar();
            }
        },

        async carregarMensagens(id) {
            const res = await this.api(`/api/painel/kanban/ticket/${id}/mensagens`);
            if (res.ok) this.mensagens = await res.json();
        },

        async assumir(id) {
            const res = await this.api(`/api/painel/kanban/ticket/${id}/assumir`, 'POST');
            if (res.ok) {
                await this.carregar();
                if (this.ticketAtivo?.id === id) {
                    const updated = Object.values(this.tickets).flat().find(t => t.id === id);
                    if (updated) this.ticketAtivo = updated;
                }
            }
        },

        async onInputMensagem() {
            const val = this.novaMensagem;
            if (val.startsWith('/') && val.length > 1) {
                const q   = val.slice(1);
                const res = await this.api(`/api/painel/respostas-prontas/buscar?q=${encodeURIComponent(q)}`);
                if (res.ok) {
                    const json = await res.json();
                    this.sugestoes = json.data;
                    this.sugestaoSelecionada = 0;
                }
            } else {
                this.sugestoes = [];
            }
        },

        aplicarResposta(s) {
            if (!s) return;
            this.novaMensagem = s.conteudo;
            this.sugestoes   = [];
        },

        navegarSugestao(dir) {
            if (!this.sugestoes.length) return;
            this.sugestaoSelecionada = Math.max(0, Math.min(
                this.sugestoes.length - 1,
                this.sugestaoSelecionada + dir
            ));
        },

        async enviarMensagem() {
            const conteudo = this.novaMensagem.trim();
            if (!conteudo || !this.ticketAtivo) return;
            this.novaMensagem = '';
            this.sugestoes   = [];
            const res = await this.api(`/api/painel/kanban/ticket/${this.ticketAtivo.id}/mensagem`, 'POST', { conteudo });
            if (res.ok) await this.carregarMensagens(this.ticketAtivo.id);
        },

        selecionarArquivo(event) {
            const file = event.target.files[0];
            if (!file) return;
            this.audioBlob      = null;
            this.audioPreviewUrl = null;
            this.midiaArquivo   = file;
            const mime = file.type;
            if (mime.startsWith('image/')) {
                this.midiaTipo   = 'imagem';
                this.midiaPreview = URL.createObjectURL(file);
            } else {
                this.midiaTipo   = 'documento';
                this.midiaPreview = 'doc';
            }
            event.target.value = '';
        },

        async iniciarGravacao() {
            try {
                const stream = await navigator.mediaDevices.getUserMedia({ audio: true });
                const opts   = MediaRecorder.isTypeSupported('audio/webm;codecs=opus')
                    ? { mimeType: 'audio/webm;codecs=opus' }
                    : {};
                this.mediaRecorder = new MediaRecorder(stream, opts);
                this.audioChunks   = [];
                this.mediaRecorder.ondataavailable = e => { if (e.data.size > 0) this.audioChunks.push(e.data); };
                this.mediaRecorder.onstop = () => {
                    const mimeType      = this.mediaRecorder.mimeType || 'audio/webm';
                    this.audioBlob      = new Blob(this.audioChunks, { type: mimeType });
                    this.audioPreviewUrl = URL.createObjectURL(this.audioBlob);
                    this.midiaArquivo   = null;
                    this.midiaPreview   = null;
                    this.midiaTipo      = 'audio';
                    stream.getTracks().forEach(t => t.stop());
                };
                this.mediaRecorder.start(100);
                this.gravandoAudio = true;
            } catch(e) {
                alert('Não foi possível acessar o microfone. Verifique as permissões do navegador.');
            }
        },

        pararGravacao() {
            if (this.mediaRecorder && this.gravandoAudio) {
                this.mediaRecorder.stop();
                this.gravandoAudio = false;
            }
        },

        limparMidia() {
            if (this.midiaPreview && this.midiaPreview !== 'doc') URL.revokeObjectURL(this.midiaPreview);
            if (this.audioPreviewUrl) URL.revokeObjectURL(this.audioPreviewUrl);
            if (this.gravandoAudio && this.mediaRecorder) this.mediaRecorder.stop();
            this.midiaArquivo    = null;
            this.midiaPreview    = null;
            this.midiaTipo       = null;
            this.audioBlob       = null;
            this.audioPreviewUrl = null;
            this.gravandoAudio   = false;
        },

        async enviarMidia() {
            if (!this.ticketAtivo) return;
            const fd = new FormData();
            const csrf = document.querySelector('meta[name="csrf-token"]').content;

            if (this.audioBlob) {
                const ext  = this.audioBlob.type.includes('ogg') ? 'ogg' : 'webm';
                fd.append('arquivo', this.audioBlob, `audio.${ext}`);
                fd.append('tipo', 'audio');
            } else if (this.midiaArquivo) {
                fd.append('arquivo', this.midiaArquivo);
                fd.append('tipo', this.midiaTipo);
                if (this.novaMensagem.trim()) fd.append('caption', this.novaMensagem.trim());
            } else {
                return;
            }

            this.enviandoMidia = true;
            try {
                const res = await fetch(`/api/painel/kanban/ticket/${this.ticketAtivo.id}/midia`, {
                    method: 'POST',
                    headers: { 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json' },
                    body: fd,
                });
                if (res.ok) {
                    this.novaMensagem = '';
                    this.limparMidia();
                    await this.carregarMensagens(this.ticketAtivo.id);
                } else {
                    const json = await res.json().catch(() => ({}));
                    alert(json.message || 'Erro ao enviar arquivo.');
                }
            } finally {
                this.enviandoMidia = false;
            }
        },

        async encerrar() {
            if (!this.tagDesfecho || !this.ticketAtivo) return;
            const res = await this.api(`/api/painel/kanban/ticket/${this.ticketAtivo.id}/encerrar`, 'POST', { tag_desfecho: this.tagDesfecho });
            if (res.ok) {
                this.encerrarModal = false;
                this.ticketAtivo = null;
                await this.carregar();
            }
        },

        formatarData(dt) {
            if (!dt) return '';
            const s = String(dt).substring(0, 10); // "YYYY-MM-DD"
            const [y, m, d] = s.split('-');
            if (!y || !m || !d) return '';
            return `${d}/${m}`;
        },

        retornoBadgeStyle(dt) {
            if (!dt) return '';
            const s = String(dt).substring(0, 10); // "YYYY-MM-DD"
            const [y, m, d] = s.split('-').map(Number);
            if (!y || !m || !d) return 'background:#f3f4f6;color:#9ca3af';
            const hoje = new Date(); hoje.setHours(0,0,0,0);
            const data = new Date(y, m - 1, d);
            if (data < hoje)                         return 'background:#fef2f2;color:#b91c1c';
            if (data.getTime() === hoje.getTime())   return 'background:#fffbeb;color:#b45309';
            return 'background:#eff6ff;color:#1d4ed8';
        },

        dataRelativa(dt) {
            if (!dt) return '';
            const diff = Math.floor((Date.now() - new Date(dt)) / 60000);
            if (diff < 1) return 'agora';
            if (diff < 60) return `${diff}min atrás`;
            if (diff < 1440) return `${Math.floor(diff/60)}h atrás`;
            return `${Math.floor(diff/1440)}d atrás`;
        },

        async orcamentoEnviado(id) {
            const res = await this.api(`/api/painel/kanban/ticket/${id}/mover`, 'POST', { coluna: 'aguardando_lead' });
            if (res.ok) {
                const col = this.ticketAtivo?.coluna_kanban;
                if (col && this.tickets[col]) {
                    const ticket = (this.tickets[col] || []).find(t => t.id === id);
                    if (ticket) {
                        this.tickets[col] = this.tickets[col].filter(t => t.id !== id);
                        this.tickets['aguardando_lead'] = [
                            ...(this.tickets['aguardando_lead'] || []),
                            { ...ticket, coluna_kanban: 'aguardando_lead' },
                        ];
                    }
                }
                if (this.ticketAtivo?.id === id) {
                    this.ticketAtivo.coluna_kanban = 'aguardando_lead';
                }
            }
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

        init() {
            this.intervalo = setInterval(() => this.carregar(), 5000);
        },

        destroy() {
            clearInterval(this.intervalo);
        }
    };
}
</script>
@endsection
