@extends('layouts.app')

@section('title', 'Auditoria & Governança de Contatos — Lead Certo')

@section('content')
<div x-data="auditor()" class="p-6 max-w-7xl mx-auto space-y-6">

    {{-- Header --}}
    <div class="flex items-center justify-between flex-wrap gap-4">
        <div>
            <h1 class="text-2xl font-bold text-gray-800">Auditoria & Governança de Contatos</h1>
            <p class="text-xs text-gray-500 mt-0.5">Validação de DDI/países, telefones internacionais, conflitos e qualidade cadastral</p>
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
        <div class="bg-white rounded-xl p-4 shadow-sm border border-gray-200">
            <p class="text-xs text-gray-400">Total Contatos</p>
            <p class="text-2xl font-bold text-gray-800 mt-1" x-text="stats.total ?? '—'"></p>
        </div>
        <div class="bg-red-50 rounded-xl p-4 shadow-sm border border-red-200 cursor-pointer hover:bg-red-100/70 transition"
             @click="aba = 'telefones'; carregarTelefones()">
            <p class="text-xs text-red-700 font-semibold flex items-center gap-1">
                <span>📞 Erros Formato / DDI</span>
            </p>
            <p class="text-2xl font-bold text-red-600 mt-1" x-text="stats.telefones_erros ?? '—'"></p>
        </div>
        <div class="bg-yellow-50 rounded-xl p-4 shadow-sm border border-yellow-200 cursor-pointer hover:bg-yellow-100/70 transition"
             @click="aba = 'pendentes'; carregarPendentes()">
            <p class="text-xs text-yellow-700 font-semibold">Sugestões Nomes</p>
            <p class="text-2xl font-bold text-yellow-600 mt-1" x-text="stats.pendentes ?? '—'"></p>
        </div>
        <div class="bg-purple-50 rounded-xl p-4 shadow-sm border border-purple-200 cursor-pointer hover:bg-purple-100/70 transition"
             @click="aba = 'conflitos'; carregarConflitos()">
            <p class="text-xs text-purple-700 font-semibold">Conflitos Google</p>
            <p class="text-2xl font-bold text-purple-600 mt-1" x-text="stats.conflitos ?? '—'"></p>
        </div>
        <div class="bg-orange-50 rounded-xl p-4 shadow-sm border border-orange-200">
            <p class="text-xs text-orange-700 font-semibold">Sem Nome</p>
            <p class="text-2xl font-bold text-orange-500 mt-1" x-text="stats.sem_nome ?? '—'"></p>
        </div>
        <div class="bg-blue-50 rounded-xl p-4 shadow-sm border border-blue-200">
            <p class="text-xs text-blue-700 font-semibold">Inativos</p>
            <p class="text-2xl font-bold text-blue-500 mt-1" x-text="stats.inativos ?? '—'"></p>
        </div>
    </div>

    {{-- Navegação por Abas --}}
    <div class="flex gap-2 border-b border-gray-200 overflow-x-auto pb-1">
        <button @click="aba = 'telefones'; carregarTelefones()"
                :class="aba === 'telefones' ? 'border-b-2 border-red-500 text-red-700 font-bold' : 'text-gray-500 hover:text-gray-700'"
                class="px-4 py-2.5 text-sm transition-colors flex items-center gap-2 whitespace-nowrap">
            <span>📞 Telefones com Erro / DDI Internacional</span>
            <span x-show="stats.telefones_erros > 0"
                  class="bg-red-500 text-white text-xs font-bold rounded-full px-2 py-0.5"
                  x-text="stats.telefones_erros"></span>
        </button>
        <button @click="aba = 'pendentes'; carregarPendentes()"
                :class="aba === 'pendentes' ? 'border-b-2 border-yellow-500 text-yellow-700 font-bold' : 'text-gray-500 hover:text-gray-700'"
                class="px-4 py-2.5 text-sm transition-colors flex items-center gap-2 whitespace-nowrap">
            <span>📝 Sugestões de Nomes & Sincronização</span>
            <span x-show="stats.pendentes > 0"
                  class="bg-yellow-400 text-gray-900 text-xs font-bold rounded-full px-2 py-0.5"
                  x-text="stats.pendentes"></span>
        </button>
        <button @click="aba = 'conflitos'; carregarConflitos()"
                :class="aba === 'conflitos' ? 'border-b-2 border-purple-500 text-purple-700 font-bold' : 'text-gray-500 hover:text-gray-700'"
                class="px-4 py-2.5 text-sm transition-colors flex items-center gap-2 whitespace-nowrap">
            <span>👥 Conflitos de Identidade</span>
            <span x-show="stats.conflitos > 0"
                  class="bg-purple-400 text-white text-xs font-bold rounded-full px-2 py-0.5"
                  x-text="stats.conflitos"></span>
        </button>
        <button @click="aba = 'contatos'; buscarContatos()"
                :class="aba === 'contatos' ? 'border-b-2 border-blue-500 text-blue-700 font-bold' : 'text-gray-500 hover:text-gray-700'"
                class="px-4 py-2.5 text-sm transition-colors whitespace-nowrap">
            👤 Base Geral de Contatos
        </button>
        <button @click="aba = 'logs'; buscarLogs()"
                :class="aba === 'logs' ? 'border-b-2 border-gray-500 text-gray-700 font-bold' : 'text-gray-500 hover:text-gray-700'"
                class="px-4 py-2.5 text-sm transition-colors whitespace-nowrap">
            📜 Histórico de Auditoria
        </button>
    </div>

    {{-- ABA 1: Telefones com Erro / DDI Internacional --}}
    <div x-show="aba === 'telefones'" class="space-y-4">
        <template x-if="telefones.length === 0">
            <div class="bg-white rounded-2xl shadow-sm border border-gray-200 text-center py-16 text-gray-400">
                <svg class="w-12 h-12 mx-auto mb-3 text-green-500 opacity-60" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                </svg>
                <p class="text-base font-bold text-gray-700">Todos os telefones estão em formato válido!</p>
                <p class="text-xs text-gray-400 mt-1">Nenhum número com dígitos anômalos ou DDI não identificado.</p>
            </div>
        </template>

        <div x-show="telefones.length > 0" class="space-y-3">
            <div class="bg-white rounded-2xl shadow-sm border border-gray-200 overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="bg-gray-50 border-b border-gray-200">
                            <tr>
                                <th class="text-left px-4 py-3 text-xs text-gray-500 font-bold uppercase tracking-wider">Contato</th>
                                <th class="text-left px-4 py-3 text-xs text-gray-500 font-bold uppercase tracking-wider">Problema Sinalizado</th>
                                <th class="text-left px-4 py-3 text-xs text-gray-500 font-bold uppercase tracking-wider">País & DDI Identificado</th>
                                <th class="text-left px-4 py-3 text-xs text-gray-500 font-bold uppercase tracking-wider">Número Original</th>
                                <th class="text-right px-4 py-3 text-xs text-gray-500 font-bold uppercase tracking-wider">Ações</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            <template x-for="item in telefones" :key="item.id">
                                <tr class="hover:bg-gray-50/80 transition-colors">
                                    <td class="px-4 py-3">
                                        <div class="font-bold text-gray-800" x-text="item.nome"></div>
                                        <div class="text-xs text-gray-400">ID #<span x-text="item.contato_id"></span></div>
                                    </td>
                                    <td class="px-4 py-3">
                                        <span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-xs font-semibold bg-red-100 text-red-700">
                                            <span>📞 Telefone</span>
                                        </span>
                                        <div class="text-xs text-gray-500 mt-0.5" x-text="item.observacao || 'Formato desconhecido'"></div>
                                    </td>
                                    <td class="px-4 py-3">
                                        <div class="inline-flex items-center gap-1.5 px-3 py-1 rounded-xl text-xs font-bold bg-gray-100 text-gray-800 border border-gray-200">
                                            <span class="text-base leading-none" x-text="item.bandeira"></span>
                                            <span x-text="item.pais_nome"></span>
                                            <span class="text-gray-400 font-mono" x-text="item.ddi ? '(+' + item.ddi + ')' : ''"></span>
                                        </div>
                                    </td>
                                    <td class="px-4 py-3 font-mono text-xs font-bold text-gray-700" x-text="item.valor_original"></td>
                                    <td class="px-4 py-3 text-right">
                                        <div class="flex items-center justify-end gap-1.5">
                                            <button @click="abrirEditarTelefoneModal(item)"
                                                    class="px-3 py-1.5 bg-blue-600 hover:bg-blue-700 text-white rounded-lg text-xs font-bold transition shadow-sm flex items-center gap-1">
                                                <span>✏️ Editar com DDI</span>
                                            </button>
                                            <button @click="ignorarTelefone(item)"
                                                    class="px-2.5 py-1.5 bg-gray-100 hover:bg-gray-200 text-gray-600 rounded-lg text-xs font-medium transition">
                                                Ignorar
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

    {{-- ABA 2: Sugestões de Nomes & Sincronização --}}
    <div x-show="aba === 'pendentes'" class="space-y-4">
        <template x-if="pendentes.length === 0">
            <div class="bg-white rounded-2xl shadow-sm border border-gray-200 text-center py-16 text-gray-400">
                <svg class="w-12 h-12 mx-auto mb-3 text-green-500 opacity-60" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                </svg>
                <p class="text-base font-bold text-gray-700">Nenhuma sugestão pendente de auditoria</p>
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
                                <th class="text-left px-3 py-3 text-xs text-gray-500 font-bold uppercase tracking-wider whitespace-nowrap">ID</th>
                                <th class="text-left px-4 py-3 text-xs text-gray-500 font-bold uppercase tracking-wider whitespace-nowrap min-w-[210px]">Telefone</th>
                                <th class="text-left px-3 py-3 text-xs text-gray-500 font-bold uppercase tracking-wider whitespace-nowrap">Campo</th>
                                <th class="text-left px-4 py-3 text-xs text-gray-500 font-bold uppercase tracking-wider min-w-[160px]">Valor Atual</th>
                                <th class="text-left px-4 py-3 text-xs text-gray-500 font-bold uppercase tracking-wider min-w-[180px]">Valor Sugerido</th>
                                <th class="text-right px-4 py-3 text-xs text-gray-500 font-bold uppercase tracking-wider whitespace-nowrap min-w-[220px]">Ações</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            <template x-for="item in pendentes" :key="itemChave(item)">
                                <tr class="hover:bg-gray-50/80 transition-colors" :class="selecionados.includes(itemChave(item)) ? 'bg-green-50/40' : ''">
                                    <td class="px-4 py-3 text-center">
                                        <input type="checkbox" 
                                               :value="itemChave(item)"
                                               :checked="selecionados.includes(itemChave(item))"
                                               @change="toggleSelecionarItem(itemChave(item))"
                                               class="rounded border-gray-300 text-green-600 focus:ring-green-500 w-4 h-4 cursor-pointer">
                                    </td>
                                    <td class="px-3 py-3 font-mono text-xs text-gray-500 whitespace-nowrap">
                                        #<span x-text="item.contato_id"></span>
                                    </td>
                                    <td class="px-4 py-3 whitespace-nowrap">
                                        <div class="inline-flex items-center gap-2 font-mono text-xs font-bold text-gray-900 bg-gray-50 px-2.5 py-1 rounded-xl border border-gray-200 shadow-sm">
                                            <span class="text-base leading-none flex-shrink-0" x-text="item.bandeira || '🇧🇷'"></span>
                                            <span class="whitespace-nowrap" x-text="item.telefone"></span>
                                        </div>
                                    </td>
                                    <td class="px-3 py-3 whitespace-nowrap">
                                        <span class="px-2 py-0.5 rounded-md text-xs font-bold bg-blue-100 text-blue-800 uppercase tracking-wide" x-text="item.campo"></span>
                                    </td>
                                    <td class="px-4 py-3 text-gray-500">
                                        <span class="line-through text-xs" x-text="item.valor_atual || '(vazio)'"></span>
                                    </td>
                                    <td class="px-4 py-3">
                                        <span class="font-bold text-gray-900 bg-yellow-100 px-2.5 py-1 rounded-lg text-xs" x-text="item.valor_sugerido"></span>
                                    </td>
                                    <td class="px-4 py-3 text-right whitespace-nowrap">
                                        <div class="flex items-center justify-end gap-1.5">
                                            <button @click="abrirEditarModal(item)"
                                                    class="px-2.5 py-1 bg-blue-50 hover:bg-blue-100 text-blue-700 rounded-lg text-xs font-bold transition flex items-center gap-1">
                                                 ✏️ Editar
                                            </button>
                                            <button @click="definirSemNome(item)"
                                                    class="px-2.5 py-1 bg-amber-50 hover:bg-amber-100 text-amber-700 rounded-lg text-xs font-bold transition flex items-center gap-1">
                                                 Sem Nome
                                            </button>
                                            <button @click="aprovarCampo(item)"
                                                    class="px-2.5 py-1 bg-green-600 hover:bg-green-700 text-white rounded-lg text-xs font-bold transition shadow-sm">
                                                Aprovar
                                            </button>
                                            <button @click="rejeitarCampo(item)"
                                                    class="px-2 py-1 text-gray-400 hover:text-red-600 rounded-lg text-xs font-medium transition">
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

    {{-- ABA 3: Conflitos de Identidade --}}
    <div x-show="aba === 'conflitos'" class="space-y-4">
        <template x-if="conflitos.length === 0">
            <div class="bg-white rounded-2xl shadow-sm border border-gray-200 text-center py-16 text-gray-400">
                <svg class="w-12 h-12 mx-auto mb-3 text-green-500 opacity-60" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                </svg>
                <p class="text-base font-bold text-gray-700">Nenhum conflito de identidade pendente</p>
                <p class="text-xs text-gray-400 mt-1">Nenhum caso de número reciclado ou sobreposição cadastral encontrado.</p>
            </div>
        </template>

        <div x-show="conflitos.length > 0" class="space-y-3">
            <div class="flex items-center justify-between flex-wrap gap-2">
                <p class="text-xs text-gray-500 font-medium">Contatos com divergência entre o nome do Google e o cadastro atual</p>
                <button @click="autoResolverConflitos()" 
                        class="px-4 py-2 bg-gradient-to-r from-purple-600 to-indigo-600 hover:from-purple-700 hover:to-indigo-700 text-white rounded-xl text-xs font-bold transition shadow-sm flex items-center gap-1.5"
                        title="Analisa todos os conflitos, mantém o nome próprio real da pessoa e define termos genéricos como 'Sem Nome'">
                    <span>⚡ Auto-Resolver Conflitos (Preservar Nomes Próprios)</span>
                </button>
            </div>

            <div class="bg-white rounded-2xl shadow-sm border border-gray-200 overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="bg-gray-50 border-b border-gray-200">
                            <tr>
                                <th class="text-left px-4 py-3 text-xs text-gray-500 font-bold uppercase whitespace-nowrap min-w-[180px]">Telefone</th>
                                <th class="text-left px-4 py-3 text-xs text-gray-500 font-bold uppercase whitespace-nowrap min-w-[180px]">Nome no Google</th>
                                <th class="text-left px-4 py-3 text-xs text-gray-500 font-bold uppercase whitespace-nowrap min-w-[180px]">Nome Existente</th>
                                <th class="text-left px-4 py-3 text-xs text-gray-500 font-bold uppercase whitespace-nowrap">Similaridade</th>
                                <th class="text-right px-4 py-3 text-xs text-gray-500 font-bold uppercase whitespace-nowrap min-w-[240px]">Decisão</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            <template x-for="c in conflitos" :key="c.id">
                                <tr class="hover:bg-gray-50 transition-colors">
                                    <td class="px-4 py-3 font-mono font-bold text-gray-800 text-xs" x-text="c.telefone"></td>
                                    <td class="px-4 py-3 font-semibold text-blue-700" x-text="c.nome_google"></td>
                                    <td class="px-4 py-3 text-gray-600" x-text="c.nome_existente"></td>
                                    <td class="px-4 py-3">
                                        <span class="px-2 py-0.5 rounded text-xs font-bold"
                                              :class="c.similaridade_nome >= 0.7 ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-700'"
                                              x-text="Math.round(c.similaridade_nome * 100) + '%'"></span>
                                    </td>
                                    <td class="px-4 py-3 text-right">
                                        <div class="flex items-center justify-end gap-2">
                                            <button @click="resolverConflito(c, 'fundir')"
                                                    class="px-2.5 py-1 bg-green-600 hover:bg-green-700 text-white rounded-lg text-xs font-bold shadow-sm">
                                                Mesma Pessoa
                                            </button>
                                            <button @click="resolverConflito(c, 'criar-novo')"
                                                    class="px-2.5 py-1 bg-purple-600 hover:bg-purple-700 text-white rounded-lg text-xs font-bold shadow-sm">
                                                Número Reciclado
                                            </button>
                                            <button @click="resolverConflito(c, 'descartar')"
                                                    class="px-2 py-1 text-gray-400 hover:text-red-600 rounded-lg text-xs">
                                                Descartar
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

    {{-- ABA 4: Base Geral de Contatos --}}
    <div x-show="aba === 'contatos'" class="space-y-4">
        <div class="bg-white rounded-2xl shadow-sm border border-gray-200 p-4 flex gap-3 flex-wrap">
            <input type="text" x-model="filtros.busca" @keyup.enter="buscarContatos()" placeholder="Buscar por nome, telefone, email..."
                   class="border border-gray-300 rounded-xl px-3.5 py-2 text-xs flex-1 min-w-[200px] focus:ring-2 focus:ring-green-500 focus:outline-none">
            <select x-model="filtros.status" @change="buscarContatos()" class="border border-gray-300 rounded-xl px-3 py-2 text-xs">
                <option value="">Status Validação (Todos)</option>
                <option value="aprovado">Aprovados</option>
                <option value="inconsistente">Inconsistentes</option>
                <option value="pendente">Pendentes</option>
            </select>
            <button @click="buscarContatos()" class="px-4 py-2 bg-green-600 text-white rounded-xl text-xs font-bold hover:bg-green-700">Buscar</button>
        </div>

        <div class="bg-white rounded-2xl shadow-sm border border-gray-200 overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-gray-50 border-b border-gray-200">
                        <tr>
                            <th class="text-left px-4 py-3 text-xs text-gray-500 font-bold uppercase">Nome</th>
                            <th class="text-left px-4 py-3 text-xs text-gray-500 font-bold uppercase">Telefone</th>
                            <th class="text-left px-4 py-3 text-xs text-gray-500 font-bold uppercase">Origem</th>
                            <th class="text-left px-4 py-3 text-xs text-gray-500 font-bold uppercase">Status</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        <template x-for="c in listaContatos" :key="c.id">
                            <tr class="hover:bg-gray-50 transition-colors">
                                <td class="px-4 py-3 font-semibold text-gray-800" x-text="c.nome || 'Sem Nome'"></td>
                                <td class="px-4 py-3 font-mono text-xs font-bold text-gray-700" x-text="c.telefone_exibicao || c.telefone"></td>
                                <td class="px-4 py-3 text-xs text-gray-500" x-text="c.origem"></td>
                                <td class="px-4 py-3">
                                    <span class="px-2 py-0.5 rounded-full text-xs font-bold"
                                          :class="c.status_validacao === 'aprovado' ? 'bg-green-100 text-green-700' : (c.status_validacao === 'inconsistente' ? 'bg-red-100 text-red-700' : 'bg-gray-100 text-gray-600')"
                                          x-text="c.status_validacao"></span>
                                </td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    {{-- ABA 5: Histórico de Auditoria --}}
    <div x-show="aba === 'logs'" class="space-y-4">
        <div class="bg-white rounded-2xl shadow-sm border border-gray-200 overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-gray-50 border-b border-gray-200">
                        <tr>
                            <th class="text-left px-4 py-3 text-xs text-gray-500 font-bold uppercase">Data/Hora</th>
                            <th class="text-left px-4 py-3 text-xs text-gray-500 font-bold uppercase">Ação</th>
                            <th class="text-left px-4 py-3 text-xs text-gray-500 font-bold uppercase">Registro</th>
                            <th class="text-left px-4 py-3 text-xs text-gray-500 font-bold uppercase">Usuário</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        <template x-for="log in logs" :key="log.id">
                            <tr class="hover:bg-gray-50 transition-colors">
                                <td class="px-4 py-3 text-xs text-gray-500 font-mono" x-text="log.criado_em"></td>
                                <td class="px-4 py-3 font-semibold text-gray-800" x-text="log.acao"></td>
                                <td class="px-4 py-3 text-xs text-gray-600" x-text="log.tabela + ' #' + log.registro_id"></td>
                                <td class="px-4 py-3 text-xs text-gray-500" x-text="log.usuario_nome || 'Sistema'"></td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    {{-- MODAL: Editar com Seletor de País & DDI (Google Contacts Style) --}}
    <div x-show="modalTelefone" style="display: none;"
         class="fixed inset-0 bg-black bg-opacity-50 z-50 flex items-center justify-center p-4">
        <div @click.outside="modalTelefone = false"
             class="bg-white rounded-3xl shadow-2xl w-full max-w-lg overflow-hidden border border-gray-100 animate-fadeIn">
            
            <div class="px-6 py-4 bg-gradient-to-r from-gray-900 to-gray-800 text-white flex items-center justify-between">
                <div class="flex items-center gap-2">
                    <span class="text-xl">🌐</span>
                    <div>
                        <h3 class="font-bold text-sm">Editar Telefone & Código do País</h3>
                        <p class="text-[11px] text-gray-300">Padrão Google Contatos (DDI + Número Local)</p>
                    </div>
                </div>
                <button @click="modalTelefone = false" class="text-gray-400 hover:text-white text-xl leading-none">&times;</button>
            </div>

            <div class="p-6 space-y-4">
                <div>
                    <label class="text-xs font-bold text-gray-700 block mb-1">Contato</label>
                    <p class="text-sm font-semibold text-gray-900 bg-gray-50 p-2.5 rounded-xl border border-gray-200" x-text="itemEdicaoTelefone?.nome"></p>
                </div>

                {{-- Seletor de País estilo Google Contatos --}}
                <div class="grid grid-cols-1 md:grid-cols-12 gap-3 items-end">
                    <div class="md:col-span-5">
                        <label class="text-xs font-bold text-gray-700 block mb-1">País / DDI</label>
                        <select x-model="itemEdicaoTelefone.ddi"
                                @change="atualizarPrefixoPais()"
                                class="w-full border border-gray-300 rounded-xl px-3 py-2.5 text-xs font-semibold focus:ring-2 focus:ring-blue-500 bg-white shadow-sm">
                            <template x-for="p in paises" :key="p.iso">
                                <option :value="p.ddi" x-text="p.bandeira + ' ' + p.nome + ' (+' + p.ddi + ')'" :selected="p.ddi === itemEdicaoTelefone.ddi"></option>
                            </template>
                        </select>
                    </div>

                    <div class="md:col-span-7">
                        <label class="text-xs font-bold text-gray-700 block mb-1">Número Local / Completo</label>
                        <div class="relative">
                            <span class="absolute inset-y-0 left-0 pl-3 flex items-center text-xs font-mono font-bold text-gray-400" x-text="'+' + itemEdicaoTelefone.ddi"></span>
                            <input type="text" 
                                   x-model="itemEdicaoTelefone.numero_local"
                                   placeholder="21999999999"
                                   class="w-full border border-gray-300 rounded-xl pl-12 pr-3 py-2.5 text-sm font-mono font-bold focus:ring-2 focus:ring-blue-500 bg-white shadow-sm">
                        </div>
                    </div>
                </div>

                <div class="p-3 bg-blue-50 rounded-xl border border-blue-200 text-xs text-blue-800">
                    <p class="font-bold flex items-center gap-1">
                        <span>💡 Visualização Final Formatada:</span>
                    </p>
                    <p class="font-mono text-sm font-bold text-blue-900 mt-1">
                        <span x-text="itemEdicaoTelefone.bandeira || '🌐'"></span>
                        +<span x-text="itemEdicaoTelefone.ddi"></span>
                        <span x-text="itemEdicaoTelefone.numero_local"></span>
                    </p>
                </div>
            </div>

            <div class="px-6 py-4 bg-gray-50 border-t border-gray-100 flex items-center justify-end gap-2">
                <button @click="modalTelefone = false" class="px-4 py-2 text-xs font-bold text-gray-600 hover:text-gray-800 rounded-xl">Cancelar</button>
                <button @click="salvarEdicaoTelefone()" class="px-5 py-2 text-xs font-bold bg-blue-600 hover:bg-blue-700 text-white rounded-xl shadow-md transition">
                    Salvar e Normalizar
                </button>
            </div>
        </div>
    </div>

    {{-- MODAL: Edição Completa de Contato (Todos os Campos) --}}
    <div x-show="modalEditar" style="display: none;"
         class="fixed inset-0 bg-black bg-opacity-60 z-50 flex items-center justify-center p-4">
        <div @click.outside="modalEditar = false"
             class="bg-white rounded-3xl shadow-2xl w-full max-w-xl overflow-hidden border border-gray-100 animate-fadeIn">
            
            <div class="px-6 py-4 bg-gradient-to-r from-gray-900 to-gray-800 text-white flex items-center justify-between">
                <div class="flex items-center gap-2.5">
                    <span class="text-2xl">👤</span>
                    <div>
                        <h3 class="font-bold text-sm">Editar Contato Completo</h3>
                        <p class="text-[11px] text-gray-300">Atualize nome, sobrenome, telefone (com país) e dados cadastrais</p>
                    </div>
                </div>
                <button @click="modalEditar = false" class="text-gray-400 hover:text-white text-2xl leading-none">&times;</button>
            </div>

            <div class="p-6 space-y-4 max-h-[75vh] overflow-y-auto">
                
                {{-- Linha: Nome e Botão Sem Nome --}}
                <div>
                    <div class="flex items-center justify-between mb-1">
                        <label class="text-xs font-bold text-gray-700">Nome Completo / Primeiro Nome</label>
                        <button type="button" 
                                @click="itemEdicao.nome = 'Sem Nome'; itemEdicao.sobrenome = ''"
                                class="text-[11px] font-bold text-purple-700 hover:text-purple-900 bg-purple-50 hover:bg-purple-100 px-2.5 py-0.5 rounded-lg border border-purple-200 transition">
                            ⚡ Definir "Sem Nome"
                        </button>
                    </div>
                    <input type="text" 
                           x-model="itemEdicao.nome"
                           placeholder="Ex: Carlos Eduardo ou Sem Nome"
                           class="w-full border border-gray-300 rounded-xl px-3.5 py-2.5 text-sm font-bold text-gray-900 focus:ring-2 focus:ring-blue-500 focus:outline-none shadow-sm">
                </div>

                {{-- Linha: Sobrenome --}}
                <div>
                    <label class="text-xs font-bold text-gray-700 block mb-1">Sobrenome (opcional)</label>
                    <input type="text" 
                           x-model="itemEdicao.sobrenome"
                           placeholder="Ex: Silva"
                           class="w-full border border-gray-300 rounded-xl px-3.5 py-2.5 text-sm font-semibold text-gray-800 focus:ring-2 focus:ring-blue-500 focus:outline-none shadow-sm">
                </div>

                {{-- Linha: Telefone Internacional com Seletor de País / DDI --}}
                <div class="p-4 bg-gray-50 rounded-2xl border border-gray-200 space-y-2">
                    <label class="text-xs font-bold text-gray-700 block">Telefone (País & Número Local)</label>
                    
                    <div class="grid grid-cols-1 md:grid-cols-12 gap-3 items-end">
                        <div class="md:col-span-5">
                            <label class="text-[10px] font-bold text-gray-500 uppercase block mb-1">País / DDI</label>
                            <select x-model="itemEdicao.ddi"
                                    @change="atualizarPrefixoModal()"
                                    class="w-full border border-gray-300 rounded-xl px-3 py-2.5 text-xs font-semibold focus:ring-2 focus:ring-blue-500 bg-white shadow-sm">
                                <template x-for="p in paises" :key="p.iso">
                                    <option :value="p.ddi" x-text="p.bandeira + ' ' + p.nome + ' (+' + p.ddi + ')'" :selected="p.ddi === itemEdicao.ddi"></option>
                                </template>
                            </select>
                        </div>

                        <div class="md:col-span-7">
                            <label class="text-[10px] font-bold text-gray-500 uppercase block mb-1">Número Local</label>
                            <div class="relative">
                                <span class="absolute inset-y-0 left-0 pl-3 flex items-center text-xs font-mono font-bold text-gray-400" x-text="'+' + itemEdicao.ddi"></span>
                                <input type="text" 
                                       x-model="itemEdicao.numero_local"
                                       placeholder="21999999999"
                                       class="w-full border border-gray-300 rounded-xl pl-12 pr-3 py-2.5 text-sm font-mono font-bold focus:ring-2 focus:ring-blue-500 bg-white shadow-sm">
                            </div>
                        </div>
                    </div>

                    <div class="text-[11px] font-mono font-bold text-blue-900 bg-blue-100/70 p-2 rounded-xl border border-blue-200 flex items-center gap-1.5">
                        <span class="text-sm" x-text="itemEdicao.bandeira || '🌐'"></span>
                        <span>Formato Salvo:</span>
                        <span>+<span x-text="itemEdicao.ddi"></span> <span x-text="itemEdicao.numero_local"></span></span>
                    </div>
                </div>

                {{-- Linha: E-mail --}}
                <div>
                    <label class="text-xs font-bold text-gray-700 block mb-1">E-mail (opcional)</label>
                    <input type="email" 
                           x-model="itemEdicao.email"
                           placeholder="cliente@exemplo.com"
                           class="w-full border border-gray-300 rounded-xl px-3.5 py-2.5 text-sm font-semibold focus:ring-2 focus:ring-blue-500 focus:outline-none shadow-sm">
                </div>

            </div>

            <div class="px-6 py-4 bg-gray-50 border-t border-gray-200 flex items-center justify-between gap-3">
                <button type="button"
                        @click="itemEdicao.nome = 'Sem Nome'; itemEdicao.sobrenome = ''; salvarEdicaoCompleta()"
                        class="px-3.5 py-2 text-xs font-bold text-purple-700 hover:text-purple-900 bg-purple-100 hover:bg-purple-200 rounded-xl transition">
                    ⚡ Salvar como "Sem Nome"
                </button>

                <div class="flex items-center gap-2">
                    <button @click="modalEditar = false" class="px-4 py-2 text-xs font-bold text-gray-600 hover:text-gray-800 rounded-xl">
                        Cancelar
                    </button>
                    <button @click="salvarEdicaoCompleta()" 
                            class="px-5 py-2 text-xs font-bold bg-blue-600 hover:bg-blue-700 text-white rounded-xl shadow-md transition flex items-center gap-1.5">
                        <span>💾 Salvar e Aprovar</span>
                    </button>
                </div>
            </div>
        </div>
    </div>

</div>

<script>
function auditor() {
    return {
        aba: 'telefones',
        stats: {},
        telefones: [],
        pendentes: [],
        conflitos: [],
        listaContatos: [],
        logs: [],
        paises: [],
        selecionados: [],

        // Modais
        modalEditar: false,
        itemEdicao: null,
        valorEditado: '',

        modalTelefone: false,
        itemEdicaoTelefone: { ddi: '55', numero_local: '', bandeira: '🇧🇷', nome: '' },

        async init() {
            await Promise.all([
                this.carregarStats(),
                this.carregarTelefones(),
                this.carregarPendentes(),
                this.carregarConflitos(),
            ]);
        },

        async carregarStats() {
            try {
                const res = await this.api('/auditor/stats');
                if (res.ok) {
                    this.stats = await res.json();
                }
            } catch (e) {
                console.error(e);
            }
        },

        async carregarTelefones() {
            try {
                const res = await this.api('/auditor/telefones-invalidos');
                if (res.ok) {
                    const d = await res.json();
                    this.telefones = d.data || [];
                    this.paises = d.paises || [];
                    this.stats.telefones_erros = d.total || 0;
                }
            } catch (e) {
                console.error(e);
            }
        },

        async carregarPendentes() {
            try {
                const res = await this.api('/auditor/pendentes');
                if (res.ok) {
                    const d = await res.json();
                    this.pendentes = d.data || [];
                    this.stats.pendentes = d.total || 0;
                    if (d.paises && d.paises.length) {
                        this.paises = d.paises;
                    }
                }
            } catch (e) {
                console.error(e);
            }
        },

        itemChave(item) {
            return `${item.vinculo_id}:::${item.campo}`;
        },

        toggleSelecionarTodos(e) {
            if (e.target.checked) {
                this.selecionados = this.pendentes.map(p => this.itemChave(p));
            } else {
                this.selecionados = [];
            }
        },

        toggleSelecionarItem(chave) {
            if (this.selecionados.includes(chave)) {
                this.selecionados = this.selecionados.filter(s => s !== chave);
            } else {
                this.selecionados.push(chave);
            }
        },

        // Edição de Telefone & DDI
        abrirEditarTelefoneModal(item) {
            const p = this.paises.find(x => x.ddi === item.ddi) || { ddi: '55', bandeira: '🇧🇷' };
            this.itemEdicaoTelefone = {
                id: item.id,
                contato_id: item.contato_id,
                nome: item.nome,
                ddi: item.ddi || '55',
                numero_local: item.numero_local || (item.valor_original ? item.valor_original.replace(/\D/g, '') : ''),
                bandeira: item.bandeira || p.bandeira,
            };
            this.modalTelefone = true;
        },

        atualizarPrefixoPais() {
            const p = this.paises.find(x => x.ddi === this.itemEdicaoTelefone.ddi);
            if (p) {
                this.itemEdicaoTelefone.bandeira = p.bandeira;
            }
        },

        async salvarEdicaoTelefone() {
            const ddi = this.itemEdicaoTelefone.ddi.replace(/\D/g, '');
            const local = this.itemEdicaoTelefone.numero_local.replace(/\D/g, '');
            const numeroFinal = ddi + local;

            const res = await this.api(`/auditor/telefones-invalidos/${this.itemEdicaoTelefone.id}/resolver`, 'POST', {
                valor_novo: numeroFinal,
            });

            if (res.ok) {
                this.modalTelefone = false;
                await this.carregarTelefones();
                await this.carregarStats();
            } else {
                alert('Erro ao salvar número.');
            }
        },

        async ignorarTelefone(item) {
            if (!confirm('Ignorar este erro de telefone?')) return;
            const res = await this.api(`/auditor/telefones-invalidos/${item.id}/ignorar`, 'POST');
            if (res.ok) {
                await this.carregarTelefones();
                await this.carregarStats();
            }
        },

        // Edição Completa de Contato (Todos os Campos)
        abrirEditarModal(item) {
            const ddi = item.ddi || '55';
            const p = this.paises.find(x => x.ddi === ddi) || { ddi: '55', bandeira: '🇧🇷' };
            
            let nomeInicial = item.nome || item.valor_atual || '';
            let sobrenomeInicial = item.sobrenome || '';
            if (item.campo === 'nome' && item.valor_sugerido) {
                nomeInicial = item.valor_sugerido;
            }
            if (item.campo === 'sobrenome' && item.valor_sugerido) {
                sobrenomeInicial = item.valor_sugerido;
            }

            let numLocal = item.numero_local || '';
            if (!numLocal && item.telefone_original) {
                numLocal = item.telefone_original.replace(/\D/g, '').replace(new RegExp('^' + ddi), '');
            }
            if (!numLocal && item.telefone) {
                numLocal = item.telefone.replace(/\D/g, '').replace(new RegExp('^' + ddi), '');
            }

            this.itemEdicao = {
                vinculo_id: item.vinculo_id,
                contato_id: item.contato_id,
                nome: nomeInicial,
                sobrenome: sobrenomeInicial,
                email: item.email || '',
                ddi: ddi,
                numero_local: numLocal,
                bandeira: item.bandeira || p.bandeira,
                campo_origem: item.campo,
            };
            this.modalEditar = true;
        },

        atualizarPrefixoModal() {
            const p = this.paises.find(x => x.ddi === this.itemEdicao.ddi);
            if (p) {
                this.itemEdicao.bandeira = p.bandeira;
            }
        },

        async salvarEdicaoCompleta() {
            if (!this.itemEdicao) return;
            const targetId = this.itemEdicao.vinculo_id || this.itemEdicao.contato_id;
            const res = await this.api(`/auditor/contato/${targetId}/salvar-completo`, 'POST', {
                nome: this.itemEdicao.nome,
                sobrenome: this.itemEdicao.sobrenome,
                email: this.itemEdicao.email,
                ddi: this.itemEdicao.ddi,
                numero_local: this.itemEdicao.numero_local,
            });
            if (res.ok) {
                this.modalEditar = false;
                await this.carregarPendentes();
                await this.carregarTelefones();
                await this.carregarStats();
            } else {
                alert('Erro ao salvar dados do contato.');
            }
        },

        async definirSemNome(item) {
            const targetId = item.vinculo_id || item.contato_id;
            const res = await this.api(`/auditor/contato/${targetId}/salvar-completo`, 'POST', {
                nome: 'Sem Nome',
                sobrenome: '',
                ddi: item.ddi || '55',
                numero_local: item.numero_local || '',
            });
            if (res.ok) {
                await this.carregarPendentes();
                await this.carregarTelefones();
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
                this.selecionados = [];
                await this.carregarPendentes();
                await this.carregarStats();
            }
        },

        async autoLimparNaoPessoas() {
            if (!confirm('Deseja executar a auto-limpeza inteligente? O sistema analisará todas as sugestões e contatos, PRESERVANDO todos os nomes próprios de pessoas reais e convertendo termos não-humanos (frotas, fretes, números) em "Sem Nome".')) return;

            const res = await this.api('/auditor/pendentes/auto-limpar-nao-pessoas', 'POST');
            if (res.ok) {
                const data = await res.json();
                alert(`${data.total} registro(s) foram higienizados e os nomes próprios reais foram preservados!`);
                await this.carregarPendentes();
                await this.carregarStats();
            }
        },

        async autoResolverConflitos() {
            if (!confirm('Deseja auto-resolver todos os conflitos de identidade? O sistema manterá o nome próprio real de cada pessoa (ex: Bruno, Jamal, Lidia, Saulo Pinhal) e fundirá os registros automaticamente.')) return;

            const res = await this.api('/auditor/conflitos/auto-resolver', 'POST');
            if (res.ok) {
                const data = await res.json();
                alert(`${data.total} conflito(s) foram analisados e resolvidos preservando os nomes próprios!`);
                await this.carregarConflitos();
                await this.carregarStats();
            }
        },

        async aprovarCampo(item) {
            const res = await this.api(`/auditor/pendente/${item.vinculo_id}/campo/${item.campo}/aprovar`, 'POST');
            if (res.ok) {
                await this.carregarPendentes();
                await this.carregarStats();
            }
        },

        async rejeitarCampo(item) {
            if (!confirm(`Rejeitar sugestão "${item.valor_sugerido}" pro campo "${item.campo}" e manter "${item.valor_atual}"?`)) return;
            const res = await this.api(`/auditor/pendente/${item.vinculo_id}/campo/${item.campo}/rejeitar`, 'POST');
            if (res.ok) {
                await this.carregarPendentes();
                await this.carregarStats();
            }
        },

        async buscarContatos() {
            const params = new URLSearchParams({
                busca:  this.filtros.busca,
                status: this.filtros.status,
            });
            const res = await this.api('/auditor/contatos?' + params);
            if (res.ok) {
                const d = await res.json();
                this.listaContatos = d.data || [];
            }
        },

        async carregarConflitos() {
            const res = await this.api('/auditor/conflitos');
            if (res.ok) {
                const d = await res.json();
                this.conflitos = d.data || [];
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
                this.logs = d.data || [];
            }
        },

        async api(url, method = 'GET', body = null) {
            return fetch(url, {
                method,
                headers: {
                    'Content-Type': 'application/json',
                    'Accept':       'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
                },
                body: body ? JSON.stringify(body) : null,
            });
        },
    };
}
</script>
@endsection
