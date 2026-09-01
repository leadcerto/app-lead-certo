@extends('layouts.app')

@section('title', 'Auditor — Lead Certo')

@section('content')
<div x-data="auditor()" x-init="carregar()" class="h-full space-y-6">

    {{-- Header --}}
    <div class="flex items-center justify-between flex-wrap gap-4">
        <div>
            <h1 class="text-2xl font-bold text-gray-800">Painel do Auditor</h1>
            <p class="text-xs text-gray-400 mt-0.5">Governança e qualidade dos dados cadastrais</p>
        </div>
        <div class="flex gap-2">
            <button @click="autoLimparNaoPessoas()" 
                    class="px-4 py-2 bg-purple-600 hover:bg-purple-700 text-white rounded-xl text-xs font-bold transition shadow-sm flex items-center gap-1.5"
                    title="Varre todas as sugestões que não forem nomes reais de pessoas (ex: Frete 35, números) e define como 'Sem Nome'">
                <span>⚡ Auto-Limpar Não-Pessoas → "Sem Nome"</span>
            </button>
        </div>
    </div>

    {{-- Cards de Saúde dos Dados --}}
    <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-6 gap-3">
        <div class="bg-white rounded-xl p-4 shadow-sm border border-gray-100">
            <p class="text-xs text-gray-400">Total</p>
            <p class="text-2xl font-bold text-gray-800 mt-1" x-text="stats.total ?? '—'"></p>
        </div>
        <div class="bg-yellow-50 rounded-xl p-4 shadow-sm border border-yellow-200 cursor-pointer"
             @click="aba = 'pendentes'">
            <p class="text-xs text-yellow-700 font-semibold">Pendentes</p>
            <p class="text-2xl font-bold text-yellow-600 mt-1" x-text="stats.pendentes ?? '—'"></p>
        </div>
        <div class="bg-red-50 rounded-xl p-4 shadow-sm border border-red-200 cursor-pointer"
             @click="aba = 'contatos'; filtros.status = 'inconsistente'; buscarContatos()">
            <p class="text-xs text-red-700 font-semibold">Inconsistentes</p>
            <p class="text-2xl font-bold text-red-600 mt-1" x-text="stats.inconsistentes ?? '—'"></p>
        </div>
        <div class="bg-orange-50 rounded-xl p-4 shadow-sm border border-orange-200">
            <p class="text-xs text-orange-700 font-semibold">Sem nome</p>
            <p class="text-2xl font-bold text-orange-500 mt-1" x-text="stats.sem_nome ?? '—'"></p>
        </div>
        <div class="bg-purple-50 rounded-xl p-4 shadow-sm border border-purple-200 cursor-pointer"
             @click="aba = 'conflitos'; carregarConflitos()">
            <p class="text-xs text-purple-700 font-semibold">Conflitos</p>
            <p class="text-2xl font-bold text-purple-600 mt-1" x-text="stats.conflitos ?? '—'"></p>
        </div>
        <div class="bg-blue-50 rounded-xl p-4 shadow-sm border border-blue-200">
            <p class="text-xs text-blue-700 font-semibold">Inativos</p>
            <p class="text-2xl font-bold text-blue-500 mt-1" x-text="stats.inativos ?? '—'"></p>
        </div>
    </div>

    {{-- Abas --}}
    <div class="flex gap-1 border-b border-gray-200">
        <button @click="aba = 'pendentes'"
                :class="aba === 'pendentes' ? 'border-b-2 border-yellow-500 text-yellow-700 font-bold' : 'text-gray-500 hover:text-gray-700'"
                class="px-4 py-2.5 text-sm transition-colors flex items-center gap-1.5">
            <span>Sugestões Pendentes</span>
            <span x-show="stats.pendentes > 0"
                  class="bg-yellow-400 text-gray-900 text-xs font-bold rounded-full px-2 py-0.5"
                  x-text="stats.pendentes"></span>
        </button>
        <button @click="aba = 'contatos'; buscarContatos()"
                :class="aba === 'contatos' ? 'border-b-2 border-blue-500 text-blue-700 font-bold' : 'text-gray-500 hover:text-gray-700'"
                class="px-4 py-2.5 text-sm transition-colors">
            Contatos
        </button>
        <button @click="aba = 'conflitos'; carregarConflitos()"
                :class="aba === 'conflitos' ? 'border-b-2 border-purple-500 text-purple-700 font-bold' : 'text-gray-500 hover:text-gray-700'"
                class="px-4 py-2.5 text-sm transition-colors flex items-center gap-1.5">
            <span>Conflitos de Identidade</span>
            <span x-show="stats.conflitos > 0"
                  class="bg-purple-400 text-white text-xs font-bold rounded-full px-2 py-0.5"
                  x-text="stats.conflitos"></span>
        </button>
        <button @click="aba = 'logs'; buscarLogs()"
                :class="aba === 'logs' ? 'border-b-2 border-gray-500 text-gray-700 font-bold' : 'text-gray-500 hover:text-gray-700'"
                class="px-4 py-2.5 text-sm transition-colors">
            Histórico de Auditoria
        </button>
    </div>

    {{-- ABA: Sugestões Pendentes --}}
    <div x-show="aba === 'pendentes'" class="space-y-4">
        <template x-if="pendentes.length === 0">
            <div class="bg-white rounded-2xl shadow-sm border border-gray-100 text-center py-16 text-gray-400">
                <svg class="w-12 h-12 mx-auto mb-3 opacity-30" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                </svg>
                <p class="text-sm font-semibold">Nenhuma sugestão pendente de auditoria</p>
                <p class="text-xs text-gray-400 mt-1">Todos os dados dos seus contatos estão atualizados e em conformidade.</p>
            </div>
        </template>

        <div x-show="pendentes.length > 0" class="space-y-3">

            {{-- Barra de Ações em Lote --}}
            <div class="bg-white rounded-2xl shadow-sm border border-gray-200 p-3.5 flex items-center justify-between flex-wrap gap-3">
                <div class="flex items-center gap-3">
                    <label class="inline-flex items-center gap-2 cursor-pointer text-xs font-bold text-gray-700">
                        <input type="checkbox" 
                               :checked="selecionados.length === pendentes.length && pendentes.length > 0"
                               @change="toggleSelecionarTodos($event)"
                               class="rounded border-gray-300 text-green-600 focus:ring-green-500 w-4 h-4 cursor-pointer">
                        <span>Selecionar Todos (<span x-text="pendentes.length"></span>)</span>
                    </label>
                    <span x-show="selecionados.length > 0" class="text-xs font-semibold text-blue-600 bg-blue-50 px-2.5 py-1 rounded-lg border border-blue-200">
                        <span x-text="selecionados.length"></span> selecionado(s)
                    </span>
                </div>

                <div class="flex items-center gap-2 flex-wrap">
                    <button type="button" 
                            x-show="selecionados.length > 0"
                            @click="executarAcaoLote('aprovar')"
                            class="px-3.5 py-1.5 bg-green-600 hover:bg-green-700 text-white rounded-xl text-xs font-bold transition shadow-sm flex items-center gap-1">
                        <span>✅ Aprovar Selecionados</span>
                    </button>

                    <button type="button" 
                            x-show="selecionados.length > 0"
                            @click="executarAcaoLote('sem_nome')"
                            class="px-3.5 py-1.5 bg-amber-500 hover:bg-amber-600 text-white rounded-xl text-xs font-bold transition shadow-sm flex items-center gap-1"
                            title="Aplica 'Sem Nome' para os itens selecionados">
                        <span>🏷️ Definir como 'Sem Nome'</span>
                    </button>

                    <button type="button" 
                            x-show="selecionados.length > 0"
                            @click="executarAcaoLote('rejeitar')"
                            class="px-3.5 py-1.5 bg-red-100 hover:bg-red-200 text-red-700 rounded-xl text-xs font-bold transition flex items-center gap-1">
                        <span>❌ Rejeitar Selecionados</span>
                    </button>
                </div>
            </div>

            {{-- Tabela de Pendências --}}
            <div class="bg-white rounded-2xl shadow-sm border border-gray-200 overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="bg-gray-50 border-b border-gray-200">
                            <tr>
                                <th class="w-10 px-4 py-3 text-center">
                                    <input type="checkbox" 
                                           :checked="selecionados.length === pendentes.length && pendentes.length > 0"
                                           @change="toggleSelecionarTodos($event)"
                                           class="rounded border-gray-300 text-green-600 focus:ring-green-500 w-4 h-4 cursor-pointer">
                                </th>
                                <th class="text-left px-3 py-3 text-xs text-gray-500 font-bold uppercase tracking-wider">ID Contato</th>
                                <th class="text-left px-3 py-3 text-xs text-gray-500 font-bold uppercase tracking-wider">Campo</th>
                                <th class="text-left px-3 py-3 text-xs text-gray-500 font-bold uppercase tracking-wider">Valor Atual</th>
                                <th class="text-left px-3 py-3 text-xs text-gray-500 font-bold uppercase tracking-wider">Valor Sugerido</th>
                                <th class="text-left px-3 py-3 text-xs text-gray-500 font-bold uppercase tracking-wider">Telefone Completo</th>
                                <th class="text-left px-3 py-3 text-xs text-gray-500 font-bold uppercase tracking-wider">Origem</th>
                                <th class="text-right px-4 py-3 text-xs text-gray-500 font-bold uppercase tracking-wider">Ações</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            <template x-for="item in pendentes" :key="itemChave(item)">
                                <tr class="hover:bg-gray-50/80 transition-colors">
                                    <td class="px-4 py-3 text-center">
                                        <input type="checkbox" 
                                               :value="itemChave(item)" 
                                               x-model="selecionados"
                                               class="rounded border-gray-300 text-green-600 focus:ring-green-500 w-4 h-4 cursor-pointer">
                                    </td>
                                    <td class="px-3 py-3 text-gray-400 font-mono text-xs font-semibold" x-text="'#' + item.contato_id"></td>
                                    <td class="px-3 py-3">
                                        <span class="text-xs bg-gray-100 text-gray-700 px-2 py-0.5 rounded font-mono font-bold"
                                              x-text="item.campo"></span>
                                    </td>
                                    <td class="px-3 py-3">
                                        <span class="text-gray-500 italic text-xs" x-text="item.valor_atual || '(vazio)'"></span>
                                    </td>
                                    <td class="px-3 py-3">
                                        <span class="font-bold text-yellow-800 bg-yellow-100 px-2.5 py-1 rounded-lg text-xs"
                                              x-text="item.valor_sugerido"></span>
                                    </td>
                                    <td class="px-3 py-3 font-mono text-xs font-bold text-gray-800" x-text="item.telefone"></td>
                                    <td class="px-3 py-3">
                                        <span class="text-[11px] bg-gray-100 text-gray-600 px-2 py-0.5 rounded font-semibold"
                                              x-text="item.origem"></span>
                                    </td>
                                    <td class="px-4 py-3 text-right">
                                        <div class="flex items-center justify-end gap-1.5 flex-wrap">
                                            <button @click="abrirEditar(item)"
                                                    class="text-xs bg-blue-50 hover:bg-blue-100 text-blue-700 px-2.5 py-1.5 rounded-lg font-bold transition flex items-center gap-1"
                                                    title="Editar este valor manualmente">
                                                <span>✏️ Editar</span>
                                            </button>
                                            <button @click="marcarSemNomeIndividual(item)"
                                                    class="text-xs bg-amber-50 hover:bg-amber-100 text-amber-700 px-2 py-1.5 rounded-lg font-bold transition"
                                                    title="Definir diretamente como 'Sem Nome'">
                                                <span>Sem Nome</span>
                                            </button>
                                            <button @click="aprovarCampo(item)"
                                                    class="text-xs bg-green-600 hover:bg-green-700 text-white px-2.5 py-1.5 rounded-lg font-bold transition shadow-sm">
                                                Aprovar
                                            </button>
                                            <button @click="rejeitarCampo(item)"
                                                    class="text-xs bg-red-100 hover:bg-red-200 text-red-700 px-2 py-1.5 rounded-lg font-bold transition">
                                                Rejeitar
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                </div>
            </div>

        </div>
    </div>

    {{-- ABA: Contatos --}}
    <div x-show="aba === 'contatos'">
        {{-- Filtros --}}
        <div class="flex gap-3 mb-4 flex-wrap">
            <input x-model="filtros.busca" @input.debounce.400ms="buscarContatos()"
                   type="text" placeholder="Nome, telefone ou e-mail..."
                   class="border border-gray-300 rounded-xl px-4 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-400 w-64 shadow-sm bg-white">
            <select x-model="filtros.status" @change="buscarContatos()"
                    class="border border-gray-300 rounded-xl px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-400 bg-white">
                <option value="">Todos os status</option>
                <option value="pendente">Pendente</option>
                <option value="aprovado">Aprovado</option>
                <option value="inconsistente">Inconsistente</option>
            </select>
            <select x-model="filtros.tipo_pessoa" @change="buscarContatos()"
                    class="border border-gray-300 rounded-xl px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-400 bg-white">
                <option value="">PF e PJ</option>
                <option value="pf">Pessoa Física</option>
                <option value="pj">Pessoa Jurídica</option>
            </select>
            <select x-model="filtros.origem" @change="buscarContatos()"
                    class="border border-gray-300 rounded-xl px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-400 bg-white">
                <option value="">Todas as origens</option>
                <option value="whatsapp">WhatsApp</option>
                <option value="agenda_google">Google</option>
                <option value="csv">CSV</option>
            </select>
            <span class="text-xs text-gray-400 self-center" x-text="totalContatos + ' resultados'"></span>
        </div>

        <div class="bg-white rounded-2xl shadow-sm border border-gray-200 overflow-hidden">
            <table class="w-full text-sm">
                <thead class="bg-gray-50 border-b border-gray-200">
                    <tr>
                        <th class="text-left px-4 py-3 text-xs text-gray-500 font-bold uppercase">ID</th>
                        <th class="text-left px-4 py-3 text-xs text-gray-500 font-bold uppercase">Nome</th>
                        <th class="text-left px-4 py-3 text-xs text-gray-500 font-bold uppercase">Telefone Completo</th>
                        <th class="text-left px-4 py-3 text-xs text-gray-500 font-bold uppercase">E-mail</th>
                        <th class="text-left px-4 py-3 text-xs text-gray-500 font-bold uppercase">CPF/CNPJ</th>
                        <th class="text-left px-4 py-3 text-xs text-gray-500 font-bold uppercase">Tipo</th>
                        <th class="text-left px-4 py-3 text-xs text-gray-500 font-bold uppercase">Status</th>
                        <th class="text-right px-4 py-3 text-xs text-gray-500 font-bold uppercase">Ações</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    <template x-for="c in listaContatos" :key="c.id">
                        <tr class="hover:bg-gray-50/80 transition-colors">
                            <td class="px-4 py-3 text-gray-400 font-mono text-xs font-semibold" x-text="'#' + c.id"></td>
                            <td class="px-4 py-3">
                                <p class="font-bold text-gray-800" x-text="[c.nome, c.sobrenome].filter(Boolean).join(' ') || 'Sem Nome'"></p>
                                <p class="text-xs text-gray-400" x-text="c.empresa"></p>
                            </td>
                            <td class="px-4 py-3 font-mono text-xs font-bold text-gray-800" x-text="c.telefone || '—'"></td>
                            <td class="px-4 py-3 text-xs text-gray-600" x-text="c.email || '—'"></td>
                            <td class="px-4 py-3 font-mono text-xs text-gray-500"
                                x-text="c.tipo_pessoa === 'pj' ? (c.cnpj || '—') : (c.cpf || '—')"></td>
                            <td class="px-4 py-3">
                                <span class="text-xs uppercase font-mono px-2 py-0.5 rounded"
                                      :class="c.tipo_pessoa === 'pj' ? 'bg-blue-100 text-blue-700' : 'bg-gray-100 text-gray-600'"
                                      x-text="c.tipo_pessoa || 'pf'"></span>
                            </td>
                            <td class="px-4 py-3">
                                <span class="text-xs px-2.5 py-1 rounded-full font-bold"
                                      :class="{
                                          'bg-green-100 text-green-700': c.status_validacao === 'aprovado',
                                          'bg-yellow-100 text-yellow-700': c.status_validacao === 'pendente',
                                          'bg-red-100 text-red-700': c.status_validacao === 'inconsistente'
                                      }"
                                      x-text="c.status_validacao"></span>
                            </td>
                            <td class="px-4 py-3 text-right">
                                <div class="flex items-center justify-end gap-1.5">
                                    <template x-if="c.status_validacao === 'pendente'">
                                        <button @click="aprovarCadastro(c)"
                                                class="text-xs bg-green-600 hover:bg-green-700 text-white px-2.5 py-1 rounded-lg font-semibold transition">
                                            Aprovar
                                        </button>
                                    </template>
                                    <template x-if="c.status_validacao !== 'inconsistente'">
                                        <button @click="abrirSinalizar(c)"
                                                class="text-xs bg-yellow-100 hover:bg-yellow-200 text-yellow-800 px-2.5 py-1 rounded-lg font-semibold transition">
                                            Sinalizar
                                        </button>
                                    </template>
                                    <button @click="abrirInativar(c)"
                                            class="text-xs bg-red-50 hover:bg-red-100 text-red-600 px-2.5 py-1 rounded-lg font-semibold transition">
                                            Inativar
                                        </button>
                                </div>
                            </td>
                        </tr>
                    </template>
                </tbody>
            </table>
        </div>
    </div>

    {{-- ABA: Conflitos de Identidade --}}
    <div x-show="aba === 'conflitos'">
        <template x-if="conflitos.length === 0">
            <div class="bg-white rounded-2xl shadow-sm border border-gray-100 text-center py-16 text-gray-400">
                <svg class="w-12 h-12 mx-auto mb-3 opacity-30" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                </svg>
                <p class="text-sm font-semibold">Nenhum conflito de identidade pendente</p>
            </div>
        </template>

        <div class="space-y-4" x-show="conflitos.length > 0">
            <template x-for="c in conflitos" :key="c.id">
                <div class="bg-white rounded-2xl shadow-sm border border-gray-200 p-5 space-y-3">
                    <div class="flex items-start justify-between gap-4 flex-wrap">
                        <div>
                            <span class="text-xs bg-purple-100 text-purple-800 px-2.5 py-1 rounded-lg font-bold"
                                  x-text="c.tipo_conflito"></span>
                            <span class="text-xs text-gray-400 ml-2" x-text="c.criado_em"></span>
                            <p class="text-sm font-mono font-bold text-gray-800 mt-2">
                                Telefone: <span x-text="c.telefone" class="text-blue-600"></span>
                            </p>
                        </div>
                        <div class="flex gap-2">
                            <button @click="resolverConflito(c, 'fundir')"
                                    class="px-3.5 py-1.5 bg-green-600 hover:bg-green-700 text-white rounded-xl text-xs font-bold transition shadow-sm">
                                Mesma Pessoa (Fundir)
                            </button>
                            <button @click="resolverConflito(c, 'criar-novo')"
                                    class="px-3.5 py-1.5 bg-blue-600 hover:bg-blue-700 text-white rounded-xl text-xs font-bold transition shadow-sm">
                                Número Reciclado (Novo Contato)
                            </button>
                            <button @click="resolverConflito(c, 'descartar')"
                                    class="px-3 py-1.5 bg-gray-100 hover:bg-gray-200 text-gray-700 rounded-xl text-xs font-semibold transition">
                                Descartar
                            </button>
                        </div>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4 bg-gray-50 rounded-xl p-4 border border-gray-100">
                        <div>
                            <p class="text-xs text-gray-500 font-semibold mb-1">Nome no Google</p>
                            <p class="text-sm font-bold text-gray-800" x-text="c.nome_google || '(sem nome)'"></p>
                        </div>
                        <div>
                            <p class="text-xs text-gray-500 font-semibold mb-1">Nome Existente no Sistema</p>
                            <p class="text-sm font-bold text-gray-800" x-text="c.nome_existente || '(sem nome)'"></p>
                        </div>
                    </div>
                </div>
            </template>
        </div>
    </div>

    {{-- ABA: Logs de Auditoria --}}
    <div x-show="aba === 'logs'">
        <div class="bg-white rounded-2xl shadow-sm border border-gray-200 overflow-hidden">
            <table class="w-full text-sm">
                <thead class="bg-gray-50 border-b border-gray-200">
                    <tr>
                        <th class="text-left px-4 py-3 text-xs text-gray-500 font-bold uppercase">Data/Hora</th>
                        <th class="text-left px-4 py-3 text-xs text-gray-500 font-bold uppercase">Auditor</th>
                        <th class="text-left px-4 py-3 text-xs text-gray-500 font-bold uppercase">Ação</th>
                        <th class="text-left px-4 py-3 text-xs text-gray-500 font-bold uppercase">Campo</th>
                        <th class="text-left px-4 py-3 text-xs text-gray-500 font-bold uppercase">Valor Anterior</th>
                        <th class="text-left px-4 py-3 text-xs text-gray-500 font-bold uppercase">Novo Valor</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    <template x-for="l in logs" :key="l.id">
                        <tr class="hover:bg-gray-50">
                            <td class="px-4 py-3 text-xs text-gray-500" x-text="l.criado_em"></td>
                            <td class="px-4 py-3 font-semibold text-gray-800" x-text="l.auditor"></td>
                            <td class="px-4 py-3">
                                <span class="text-xs bg-gray-100 text-gray-700 px-2 py-0.5 rounded font-mono font-bold"
                                      x-text="l.acao"></span>
                            </td>
                            <td class="px-4 py-3 text-xs text-gray-600 font-mono" x-text="l.campo || '—'"></td>
                            <td class="px-4 py-3 text-xs text-gray-400 line-through" x-text="l.valor_antigo || '(vazio)'"></td>
                            <td class="px-4 py-3 text-xs font-bold text-green-700" x-text="l.valor_novo || '(vazio)'"></td>
                        </tr>
                    </template>
                </tbody>
            </table>
        </div>
    </div>

    {{-- Modal de Edição de Valor Sugerido / Campo --}}
    <div x-show="modalEditar" 
         x-transition:enter="transition ease-out duration-150"
         x-transition:enter-start="opacity-0"
         x-transition:enter-end="opacity-100"
         class="fixed inset-0 z-50 bg-black/60 backdrop-blur-sm flex items-center justify-center p-4" 
         style="display: none;">
        <div class="bg-white rounded-2xl max-w-md w-full p-6 space-y-4 shadow-2xl border border-gray-100" @click.outside="modalEditar = false">
            <div class="flex items-center justify-between border-b border-gray-100 pb-3">
                <h3 class="text-base font-bold text-gray-800">✏️ Editar Valor antes de Aprovar</h3>
                <button @click="modalEditar = false" class="text-gray-400 hover:text-gray-700 text-lg font-bold">✕</button>
            </div>

            <div class="space-y-3">
                <div>
                    <label class="block text-xs font-semibold text-gray-500 mb-1">Campo</label>
                    <input type="text" :value="itemEmEdicao?.campo" disabled class="w-full bg-gray-100 border border-gray-200 rounded-xl px-3 py-2 text-xs font-mono font-bold text-gray-700">
                </div>
                <div>
                    <label class="block text-xs font-semibold text-gray-500 mb-1">Telefone do Contato</label>
                    <input type="text" :value="itemEmEdicao?.telefone" disabled class="w-full bg-gray-100 border border-gray-200 rounded-xl px-3 py-2 text-xs font-mono text-gray-700">
                </div>
                <div>
                    <div class="flex items-center justify-between mb-1">
                        <label class="block text-xs font-bold text-gray-700">Novo Valor a Salvar</label>
                        <button type="button" @click="valorEditado = 'Sem Nome'" class="text-xs text-amber-600 font-bold hover:underline">
                            Inserir "Sem Nome"
                        </button>
                    </div>
                    <input type="text" x-model="valorEditado" class="w-full border border-gray-300 rounded-xl px-4 py-2.5 text-sm bg-gray-50 focus:bg-white focus:ring-2 focus:ring-blue-500 focus:border-blue-500 shadow-sm" autofocus>
                </div>
            </div>

            <div class="flex justify-end gap-2 pt-3 border-t border-gray-100">
                <button type="button" @click="modalEditar = false" class="px-4 py-2 bg-gray-100 hover:bg-gray-200 text-gray-700 text-xs font-semibold rounded-xl transition">
                    Cancelar
                </button>
                <button type="button" @click="salvarEdicao()" class="px-5 py-2 bg-blue-600 hover:bg-blue-700 text-white text-xs font-bold rounded-xl shadow transition">
                    Salvar e Aprovar
                </button>
            </div>
        </div>
    </div>

    {{-- Modal de Sinalizar Inconsistência --}}
    <div x-show="modalSinalizar" class="fixed inset-0 z-50 bg-black/60 flex items-center justify-center p-4" style="display: none;">
        <div class="bg-white rounded-2xl max-w-md w-full p-6 space-y-4 shadow-2xl" @click.outside="modalSinalizar = false">
            <h3 class="text-base font-bold text-gray-800">Sinalizar Inconsistência</h3>
            <textarea x-model="motivoAcao" placeholder="Descreva o motivo da inconsistência..." class="w-full border border-gray-300 rounded-xl p-3 text-sm" rows="3"></textarea>
            <div class="flex justify-end gap-2">
                <button type="button" @click="modalSinalizar = false" class="px-4 py-2 bg-gray-100 text-gray-700 text-xs rounded-xl font-semibold">Cancelar</button>
                <button type="button" @click="confirmarSinalizar()" class="px-4 py-2 bg-yellow-600 text-white text-xs rounded-xl font-bold">Confirmar</button>
            </div>
        </div>
    </div>

    {{-- Modal de Inativar Contato --}}
    <div x-show="modalInativar" class="fixed inset-0 z-50 bg-black/60 flex items-center justify-center p-4" style="display: none;">
        <div class="bg-white rounded-2xl max-w-md w-full p-6 space-y-4 shadow-2xl" @click.outside="modalInativar = false">
            <h3 class="text-base font-bold text-gray-800">Inativar Contato</h3>
            <textarea x-model="motivoAcao" placeholder="Motivo da inativação..." class="w-full border border-gray-300 rounded-xl p-3 text-sm" rows="3"></textarea>
            <div class="flex justify-end gap-2">
                <button type="button" @click="modalInativar = false" class="px-4 py-2 bg-gray-100 text-gray-700 text-xs rounded-xl font-semibold">Cancelar</button>
                <button type="button" @click="confirmarInativar()" class="px-4 py-2 bg-red-600 text-white text-xs rounded-xl font-bold">Inativar</button>
            </div>
        </div>
    </div>

</div>

<script>
function auditor() {
    return {
        aba: 'pendentes',
        stats: {},
        pendentes: [],
        selecionados: [],
        conflitos: [],
        logs: [],
        listaContatos: [],
        totalContatos: 0,
        paginaAtual: 1,
        totalPaginas: 1,
        filtros: { busca: '', status: '', tipo_pessoa: '', origem: '' },

        modalEditar: false,
        itemEmEdicao: null,
        valorEditado: '',

        modalSinalizar: false,
        modalInativar: false,
        contatoAtivo: null,
        motivoAcao: '',

        async carregar() {
            await Promise.all([
                this.carregarStats(),
                this.carregarPendentes(),
            ]);
        },

        async carregarStats() {
            const res = await this.api('/auditor/stats');
            if (res.ok) {
                this.stats = await res.json();
            }
        },

        async carregarPendentes() {
            const res = await this.api('/auditor/pendentes');
            if (res.ok) {
                const d = await res.json();
                this.pendentes = d.data || [];
                this.selecionados = [];
            }
        },

        itemChave(item) {
            return `${item.vinculo_id}:::${item.campo}`;
        },

        toggleSelecionarTodos(e) {
            if (e.target.checked) {
                this.selecionados = this.pendentes.map(item => this.itemChave(item));
            } else {
                this.selecionados = [];
            }
        },

        abrirEditar(item) {
            this.itemEmEdicao = item;
            this.valorEditado = item.valor_sugerido || item.valor_atual || '';
            this.modalEditar = true;
        },

        async salvarEdicao() {
            if (!this.itemEmEdicao) return;
            const res = await this.api(`/auditor/pendente/${this.itemEmEdicao.vinculo_id}/campo/${this.itemEmEdicao.campo}/salvar`, 'POST', {
                valor: this.valorEditado,
            });
            if (res.ok) {
                this.pendentes = this.pendentes.filter(p => this.itemChave(p) !== this.itemChave(this.itemEmEdicao));
                this.selecionados = this.selecionados.filter(s => s !== this.itemChave(this.itemEmEdicao));
                this.modalEditar = false;
                await this.carregarStats();
            }
        },

        async marcarSemNomeIndividual(item) {
            const res = await this.api(`/auditor/pendente/${item.vinculo_id}/campo/${item.campo}/salvar`, 'POST', {
                valor: 'Sem Nome',
            });
            if (res.ok) {
                this.pendentes = this.pendentes.filter(p => this.itemChave(p) !== this.itemChave(item));
                this.selecionados = this.selecionados.filter(s => s !== this.itemChave(item));
                await this.carregarStats();
            }
        },

        async executarAcaoLote(acao) {
            if (this.selecionados.length === 0) return;

            const itensParaEnviar = this.selecionados.map(chave => {
                const [vinculoId, campo] = chave.split(':::');
                return { vinculo_id: parseInt(vinculoId), campo };
            });

            let rota = '/auditor/pendentes/aprovar-lote';
            if (acao === 'rejeitar') rota = '/auditor/pendentes/rejeitar-lote';
            if (acao === 'sem_nome') rota = '/auditor/pendentes/marcar-sem-nome-lote';

            const res = await this.api(rota, 'POST', { itens: itensParaEnviar });
            if (res.ok) {
                await this.carregarPendentes();
                await this.carregarStats();
            }
        },

        async autoLimparNaoPessoas() {
            if (!confirm('Deseja converter automaticamente todas as sugestões com nomes de empresas, termos genéricos e números (ex: "Frete 35", "Bongo") para "Sem Nome"?')) return;

            const res = await this.api('/auditor/pendentes/auto-limpar-nao-pessoas', 'POST');
            if (res.ok) {
                const data = await res.json();
                alert(`${data.total} contato(s) limpos e definidos como "Sem Nome" com sucesso!`);
                await this.carregarPendentes();
                await this.carregarStats();
            }
        },

        async aprovarCampo(item) {
            const res = await this.api(`/auditor/pendente/${item.vinculo_id}/campo/${item.campo}/aprovar`, 'POST');
            if (res.ok) {
                this.pendentes = this.pendentes.filter(p => this.itemChave(p) !== this.itemChave(item));
                this.selecionados = this.selecionados.filter(s => s !== this.itemChave(item));
                await this.carregarStats();
            }
        },

        async rejeitarCampo(item) {
            if (!confirm(`Rejeitar sugestão "${item.valor_sugerido}" pro campo "${item.campo}" e manter "${item.valor_atual}"?`)) return;
            const res = await this.api(`/auditor/pendente/${item.vinculo_id}/campo/${item.campo}/rejeitar`, 'POST');
            if (res.ok) {
                this.pendentes = this.pendentes.filter(p => this.itemChave(p) !== this.itemChave(item));
                this.selecionados = this.selecionados.filter(s => s !== this.itemChave(item));
                await this.carregarStats();
            }
        },

        async buscarContatos() {
            const params = new URLSearchParams({
                page:        this.paginaAtual,
                busca:       this.filtros.busca,
                status:      this.filtros.status,
                tipo_pessoa: this.filtros.tipo_pessoa,
                origem:      this.filtros.origem,
            });
            const res = await this.api('/auditor/contatos?' + params);
            if (res.ok) {
                const d = await res.json();
                this.listaContatos = d.data;
                this.totalContatos = d.total;
                this.totalPaginas  = d.ultima_pagina;
            }
        },

        async carregarConflitos() {
            const res = await this.api('/auditor/conflitos');
            if (res.ok) {
                const d = await res.json();
                this.conflitos = d.data;
            }
        },

        async resolverConflito(conflito, acao) {
            const labels = {
                'fundir':      'Fundir como mesma pessoa?',
                'criar-novo':  'Confirmar número reciclado e criar novo contato?',
                'descartar':   'Descartar este conflito?',
            };
            if (!confirm(labels[acao])) return;

            const res = await this.api(`/auditor/conflito/${conflito.id}/${acao}`, 'POST');
            if (res.ok) {
                this.conflitos = this.conflitos.filter(c => c.id !== conflito.id);
                await this.carregarStats();
            }
        },

        async buscarLogs() {
            const res = await this.api('/auditor/logs');
            if (res.ok) {
                const d = await res.json();
                this.logs = d.data;
            }
        },

        async aprovarCadastro(contato) {
            const res = await this.api(`/auditor/contato/${contato.id}/aprovar-cadastro`, 'POST');
            if (res.ok) {
                contato.status_validacao = 'aprovado';
            }
        },

        abrirSinalizar(contato) {
            this.contatoAtivo  = contato;
            this.motivoAcao    = '';
            this.modalSinalizar = true;
        },

        async confirmarSinalizar() {
            if (!this.motivoAcao.trim()) return;
            const res = await this.api(`/auditor/contato/${this.contatoAtivo.id}/sinalizar`, 'POST', {
                motivo: this.motivoAcao,
            });
            if (res.ok) {
                this.contatoAtivo.status_validacao = 'inconsistente';
                this.modalSinalizar = false;
                await this.carregarStats();
            }
        },

        abrirInativar(contato) {
            this.contatoAtivo = contato;
            this.motivoAcao   = '';
            this.modalInativar = true;
        },

        async confirmarInativar() {
            if (!this.motivoAcao.trim()) return;
            const res = await this.api(`/auditor/contato/${this.contatoAtivo.id}/inativar`, 'POST', {
                motivo: this.motivoAcao,
            });
            if (res.ok) {
                this.listaContatos = this.listaContatos.filter(c => c.id !== this.contatoAtivo.id);
                this.modalInativar = false;
                await this.carregarStats();
            }
        },

        async api(url, method = 'GET', body = null) {
            return fetch(url, {
                method,
                headers: {
                    'Content-Type': 'application/json',
                    'Accept':       'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                },
                body: body ? JSON.stringify(body) : null,
            });
        },
    };
}
</script>
@endsection
