@extends('layouts.app')

@section('title', 'Auditor — Lead Certo')

@section('content')
<div x-data="auditor()" x-init="carregar()" class="h-full">

    {{-- Header --}}
    <div class="flex items-center justify-between mb-6">
        <div>
            <h1 class="text-xl font-bold text-gray-800">Painel do Auditor</h1>
            <p class="text-xs text-gray-400 mt-0.5">Governança e qualidade dos dados cadastrais</p>
        </div>
    </div>

    {{-- Cards de Saúde dos Dados --}}
    <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-6 gap-3 mb-6">
        <div class="bg-white rounded-xl p-4 shadow-sm">
            <p class="text-xs text-gray-400">Total</p>
            <p class="text-2xl font-bold text-gray-800 mt-1" x-text="stats.total ?? '—'"></p>
        </div>
        <div class="bg-yellow-50 rounded-xl p-4 shadow-sm border border-yellow-200 cursor-pointer"
             @click="aba = 'pendentes'">
            <p class="text-xs text-yellow-700">Pendentes</p>
            <p class="text-2xl font-bold text-yellow-600 mt-1" x-text="stats.pendentes ?? '—'"></p>
        </div>
        <div class="bg-red-50 rounded-xl p-4 shadow-sm border border-red-200 cursor-pointer"
             @click="aba = 'contatos'; filtros.status = 'inconsistente'; buscarContatos()">
            <p class="text-xs text-red-700">Inconsistentes</p>
            <p class="text-2xl font-bold text-red-600 mt-1" x-text="stats.inconsistentes ?? '—'"></p>
        </div>
        <div class="bg-orange-50 rounded-xl p-4 shadow-sm border border-orange-200">
            <p class="text-xs text-orange-700">Sem nome</p>
            <p class="text-2xl font-bold text-orange-500 mt-1" x-text="stats.sem_nome ?? '—'"></p>
        </div>
        <div class="bg-purple-50 rounded-xl p-4 shadow-sm border border-purple-200 cursor-pointer"
             @click="aba = 'conflitos'; carregarConflitos()">
            <p class="text-xs text-purple-700">Conflitos</p>
            <p class="text-2xl font-bold text-purple-600 mt-1" x-text="stats.conflitos ?? '—'"></p>
        </div>
        <div class="bg-blue-50 rounded-xl p-4 shadow-sm border border-blue-200">
            <p class="text-xs text-blue-700">Inativos</p>
            <p class="text-2xl font-bold text-blue-500 mt-1" x-text="stats.inativos ?? '—'"></p>
        </div>
        <div class="bg-green-50 rounded-xl p-4 shadow-sm border border-green-200">
            <p class="text-xs text-green-700">Sem telefone</p>
            <p class="text-2xl font-bold text-green-600 mt-1" x-text="stats.sem_telefone ?? '—'"></p>
        </div>
    </div>

    {{-- Abas --}}
    <div class="flex gap-1 mb-5 border-b border-gray-200">
        <button @click="aba = 'pendentes'"
                :class="aba === 'pendentes' ? 'border-b-2 border-yellow-500 text-yellow-700 font-medium' : 'text-gray-500 hover:text-gray-700'"
                class="px-4 py-2 text-sm transition-colors">
            Sugestões Pendentes
            <span x-show="stats.pendentes > 0"
                  class="ml-1 bg-yellow-400 text-gray-900 text-xs font-bold rounded-full px-1.5"
                  x-text="stats.pendentes"></span>
        </button>
        <button @click="aba = 'contatos'; buscarContatos()"
                :class="aba === 'contatos' ? 'border-b-2 border-blue-500 text-blue-700 font-medium' : 'text-gray-500 hover:text-gray-700'"
                class="px-4 py-2 text-sm transition-colors">
            Contatos
        </button>
        <button @click="aba = 'conflitos'; carregarConflitos()"
                :class="aba === 'conflitos' ? 'border-b-2 border-purple-500 text-purple-700 font-medium' : 'text-gray-500 hover:text-gray-700'"
                class="px-4 py-2 text-sm transition-colors">
            Conflitos de Identidade
            <span x-show="stats.conflitos > 0"
                  class="ml-1 bg-purple-400 text-white text-xs font-bold rounded-full px-1.5"
                  x-text="stats.conflitos"></span>
        </button>
        <button @click="aba = 'logs'; buscarLogs()"
                :class="aba === 'logs' ? 'border-b-2 border-gray-500 text-gray-700 font-medium' : 'text-gray-500 hover:text-gray-700'"
                class="px-4 py-2 text-sm transition-colors">
            Histórico de Auditoria
        </button>
    </div>

    {{-- ABA: Sugestões Pendentes --}}
    <div x-show="aba === 'pendentes'">
        <template x-if="pendentes.length === 0">
            <div class="text-center py-16 text-gray-400">
                <svg class="w-12 h-12 mx-auto mb-3 opacity-30" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                </svg>
                <p class="text-sm">Nenhuma sugestão de nome pendente</p>
            </div>
        </template>

        <div class="bg-white rounded-xl shadow-sm overflow-hidden">
            <table class="w-full text-sm" x-show="pendentes.length > 0">
                <thead class="bg-gray-50 border-b border-gray-200">
                    <tr>
                        <th class="text-left px-4 py-3 text-xs text-gray-500 font-medium">ID Contato</th>
                        <th class="text-left px-4 py-3 text-xs text-gray-500 font-medium">Nome no Master</th>
                        <th class="text-left px-4 py-3 text-xs text-gray-500 font-medium">Nome Sugerido</th>
                        <th class="text-left px-4 py-3 text-xs text-gray-500 font-medium">Telefone</th>
                        <th class="text-left px-4 py-3 text-xs text-gray-500 font-medium">Origem</th>
                        <th class="text-right px-4 py-3 text-xs text-gray-500 font-medium">Ação</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    <template x-for="item in pendentes" :key="item.vinculo_id">
                        <tr class="hover:bg-gray-50">
                            <td class="px-4 py-3 text-gray-400 font-mono text-xs" x-text="'#' + item.contato_id"></td>
                            <td class="px-4 py-3">
                                <span class="text-gray-500 italic" x-text="item.nome_master || '(sem nome)'"></span>
                            </td>
                            <td class="px-4 py-3">
                                <span class="font-medium text-yellow-700 bg-yellow-50 px-2 py-0.5 rounded"
                                      x-text="item.nome_sugerido"></span>
                            </td>
                            <td class="px-4 py-3 text-gray-500 font-mono text-xs" x-text="item.telefone"></td>
                            <td class="px-4 py-3">
                                <span class="text-xs bg-gray-100 text-gray-600 px-2 py-0.5 rounded"
                                      x-text="item.origem"></span>
                            </td>
                            <td class="px-4 py-3 text-right">
                                <div class="flex items-center justify-end gap-2">
                                    <button @click="aprovarNome(item)"
                                            class="text-xs bg-green-600 hover:bg-green-500 text-white px-3 py-1.5 rounded-lg transition-colors">
                                        Aprovar
                                    </button>
                                    <button @click="rejeitarNome(item)"
                                            class="text-xs bg-red-100 hover:bg-red-200 text-red-700 px-3 py-1.5 rounded-lg transition-colors">
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

    {{-- ABA: Contatos --}}
    <div x-show="aba === 'contatos'">
        {{-- Filtros --}}
        <div class="flex gap-3 mb-4 flex-wrap">
            <input x-model="filtros.busca" @input.debounce.400ms="buscarContatos()"
                   type="text" placeholder="Nome, telefone ou e-mail..."
                   class="border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-400 w-56">
            <select x-model="filtros.status" @change="buscarContatos()"
                    class="border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-400">
                <option value="">Todos os status</option>
                <option value="pendente">Pendente</option>
                <option value="aprovado">Aprovado</option>
                <option value="inconsistente">Inconsistente</option>
            </select>
            <select x-model="filtros.tipo_pessoa" @change="buscarContatos()"
                    class="border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-400">
                <option value="">PF e PJ</option>
                <option value="pf">Pessoa Física</option>
                <option value="pj">Pessoa Jurídica</option>
            </select>
            <select x-model="filtros.origem" @change="buscarContatos()"
                    class="border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-400">
                <option value="">Todas as origens</option>
                <option value="whatsapp">WhatsApp</option>
                <option value="agenda_google">Google</option>
                <option value="csv">CSV</option>
            </select>
            <span class="text-xs text-gray-400 self-center" x-text="totalContatos + ' resultados (máx. 100/página)'"></span>
        </div>

        <div class="bg-white rounded-xl shadow-sm overflow-hidden">
            <table class="w-full text-sm">
                <thead class="bg-gray-50 border-b border-gray-200">
                    <tr>
                        <th class="text-left px-4 py-3 text-xs text-gray-500 font-medium">ID</th>
                        <th class="text-left px-4 py-3 text-xs text-gray-500 font-medium">Nome</th>
                        <th class="text-left px-4 py-3 text-xs text-gray-500 font-medium">Telefone</th>
                        <th class="text-left px-4 py-3 text-xs text-gray-500 font-medium">E-mail</th>
                        <th class="text-left px-4 py-3 text-xs text-gray-500 font-medium">CPF/CNPJ</th>
                        <th class="text-left px-4 py-3 text-xs text-gray-500 font-medium">Tipo</th>
                        <th class="text-left px-4 py-3 text-xs text-gray-500 font-medium">Status</th>
                        <th class="text-right px-4 py-3 text-xs text-gray-500 font-medium">Ações</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    <template x-for="c in listaContatos" :key="c.id">
                        <tr class="hover:bg-gray-50">
                            <td class="px-4 py-3 text-gray-400 font-mono text-xs" x-text="'#' + c.id"></td>
                            <td class="px-4 py-3">
                                <p class="font-medium text-gray-800" x-text="[c.nome, c.sobrenome].filter(Boolean).join(' ') || '—'"></p>
                                <p class="text-xs text-gray-400" x-text="c.empresa"></p>
                            </td>
                            <td class="px-4 py-3 font-mono text-xs text-gray-500" x-text="c.telefone || '—'"></td>
                            <td class="px-4 py-3 text-xs text-gray-500" x-text="c.email || '—'"></td>
                            <td class="px-4 py-3 font-mono text-xs text-gray-500"
                                x-text="c.tipo_pessoa === 'pj' ? (c.cnpj || '—') : (c.cpf || '—')"></td>
                            <td class="px-4 py-3">
                                <span class="text-xs px-2 py-0.5 rounded"
                                      :class="c.tipo_pessoa === 'pj' ? 'bg-blue-100 text-blue-700' : 'bg-gray-100 text-gray-600'"
                                      x-text="c.tipo_pessoa?.toUpperCase() || '—'"></span>
                            </td>
                            <td class="px-4 py-3">
                                @if(true)
                                <span class="text-xs px-2 py-0.5 rounded"
                                      :class="{
                                          'bg-yellow-100 text-yellow-700': c.status_validacao === 'pendente',
                                          'bg-green-100 text-green-700':  c.status_validacao === 'aprovado',
                                          'bg-red-100 text-red-700':      c.status_validacao === 'inconsistente',
                                      }"
                                      x-text="c.status_validacao"></span>
                                @endif
                            </td>
                            <td class="px-4 py-3 text-right">
                                <div class="flex items-center justify-end gap-1.5">
                                    <button x-show="c.status_validacao !== 'aprovado'"
                                            @click="aprovarCadastro(c)"
                                            title="Aprovar cadastro"
                                            class="text-green-600 hover:text-green-500 p-1 rounded">
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                                        </svg>
                                    </button>
                                    <button @click="abrirSinalizar(c)"
                                            title="Sinalizar inconsistência"
                                            class="text-yellow-600 hover:text-yellow-500 p-1 rounded">
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
                                        </svg>
                                    </button>
                                    <button @click="abrirInativar(c)"
                                            title="Inativar contato"
                                            class="text-red-400 hover:text-red-600 p-1 rounded">
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18.364 18.364A9 9 0 005.636 5.636m12.728 12.728A9 9 0 015.636 5.636m12.728 12.728L5.636 5.636"/>
                                        </svg>
                                    </button>
                                </div>
                            </td>
                        </tr>
                    </template>
                </tbody>
            </table>

            {{-- Paginação --}}
            <div class="flex items-center justify-between px-4 py-3 border-t border-gray-100" x-show="totalPaginas > 1">
                <button @click="paginaAtual > 1 && (paginaAtual--, buscarContatos())"
                        :disabled="paginaAtual <= 1"
                        class="text-sm text-gray-500 hover:text-gray-800 disabled:opacity-30">← Anterior</button>
                <span class="text-xs text-gray-400" x-text="'Página ' + paginaAtual + ' de ' + totalPaginas"></span>
                <button @click="paginaAtual < totalPaginas && (paginaAtual++, buscarContatos())"
                        :disabled="paginaAtual >= totalPaginas"
                        class="text-sm text-gray-500 hover:text-gray-800 disabled:opacity-30">Próxima →</button>
            </div>
        </div>
    </div>

    {{-- ABA: Conflitos de Identidade --}}
    <div x-show="aba === 'conflitos'">
        <div class="mb-3 p-3 bg-purple-50 border border-purple-200 rounded-lg text-sm text-purple-700">
            <strong>Número possivelmente reciclado:</strong> o sistema encontrou um telefone já cadastrado em outro nome.
            Analise e decida: <em>Fundir</em> (mesma pessoa), <em>Criar novo</em> (chip reciclado) ou <em>Descartar</em> (dado errado).
        </div>

        <template x-if="conflitos.length === 0">
            <div class="text-center py-16 text-gray-400">
                <svg class="w-12 h-12 mx-auto mb-3 opacity-30" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                </svg>
                <p class="text-sm">Nenhum conflito aguardando resolução</p>
            </div>
        </template>

        <div class="bg-white rounded-xl shadow-sm overflow-hidden" x-show="conflitos.length > 0">
            <table class="w-full text-sm">
                <thead class="bg-gray-50 border-b border-gray-200">
                    <tr>
                        <th class="text-left px-4 py-3 text-xs text-gray-500 font-medium">Telefone</th>
                        <th class="text-left px-4 py-3 text-xs text-gray-500 font-medium">Nome Google (novo)</th>
                        <th class="text-left px-4 py-3 text-xs text-gray-500 font-medium">Nome no Sistema (existente)</th>
                        <th class="text-left px-4 py-3 text-xs text-gray-500 font-medium">Similaridade</th>
                        <th class="text-left px-4 py-3 text-xs text-gray-500 font-medium">Detectado em</th>
                        <th class="text-right px-4 py-3 text-xs text-gray-500 font-medium">Decisão</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    <template x-for="c in conflitos" :key="c.id">
                        <tr class="hover:bg-gray-50">
                            <td class="px-4 py-3 font-mono text-xs text-gray-500" x-text="c.telefone"></td>
                            <td class="px-4 py-3">
                                <span class="font-medium text-purple-700" x-text="c.nome_google"></span>
                            </td>
                            <td class="px-4 py-3">
                                <span class="text-gray-600" x-text="c.nome_existente"></span>
                                <span class="ml-1 text-xs text-gray-400 font-mono" x-text="'#' + c.contato_existente_id"></span>
                            </td>
                            <td class="px-4 py-3">
                                <span class="text-xs font-mono px-2 py-0.5 rounded"
                                      :class="c.similaridade_nome < 30 ? 'bg-red-100 text-red-700' : 'bg-yellow-100 text-yellow-700'"
                                      x-text="c.similaridade_nome + '%'"></span>
                            </td>
                            <td class="px-4 py-3 text-xs text-gray-400" x-text="c.criado_em"></td>
                            <td class="px-4 py-3 text-right">
                                <div class="flex items-center justify-end gap-1.5">
                                    <button @click="resolverConflito(c, 'fundir')"
                                            title="Mesma pessoa — fundir dados"
                                            class="text-xs bg-blue-100 hover:bg-blue-200 text-blue-700 px-2.5 py-1.5 rounded-lg">
                                        Fundir
                                    </button>
                                    <button @click="resolverConflito(c, 'criar-novo')"
                                            title="Chip reciclado — criar como novo contato"
                                            class="text-xs bg-green-600 hover:bg-green-500 text-white px-2.5 py-1.5 rounded-lg">
                                        Criar novo
                                    </button>
                                    <button @click="resolverConflito(c, 'descartar')"
                                            title="Dado inválido — descartar"
                                            class="text-xs bg-gray-100 hover:bg-gray-200 text-gray-600 px-2.5 py-1.5 rounded-lg">
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

    {{-- ABA: Histórico --}}
    <div x-show="aba === 'logs'">
        <div class="bg-white rounded-xl shadow-sm overflow-hidden">
            <table class="w-full text-sm">
                <thead class="bg-gray-50 border-b border-gray-200">
                    <tr>
                        <th class="text-left px-4 py-3 text-xs text-gray-500 font-medium">Data / Hora</th>
                        <th class="text-left px-4 py-3 text-xs text-gray-500 font-medium">Auditor</th>
                        <th class="text-left px-4 py-3 text-xs text-gray-500 font-medium">Ação</th>
                        <th class="text-left px-4 py-3 text-xs text-gray-500 font-medium">Tabela / ID</th>
                        <th class="text-left px-4 py-3 text-xs text-gray-500 font-medium">Campo</th>
                        <th class="text-left px-4 py-3 text-xs text-gray-500 font-medium">De</th>
                        <th class="text-left px-4 py-3 text-xs text-gray-500 font-medium">Para</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    <template x-for="log in logs" :key="log.id">
                        <tr class="hover:bg-gray-50">
                            <td class="px-4 py-2 text-xs text-gray-400 font-mono" x-text="log.criado_em"></td>
                            <td class="px-4 py-2 text-xs text-gray-600" x-text="log.auditor"></td>
                            <td class="px-4 py-2">
                                <span class="text-xs px-2 py-0.5 rounded bg-gray-100 text-gray-700"
                                      x-text="log.acao"></span>
                            </td>
                            <td class="px-4 py-2 text-xs font-mono text-gray-500"
                                x-text="log.tabela + ' #' + log.registro_id"></td>
                            <td class="px-4 py-2 text-xs text-gray-500" x-text="log.campo || '—'"></td>
                            <td class="px-4 py-2 text-xs text-red-500 max-w-xs truncate" x-text="log.valor_antigo || '—'"></td>
                            <td class="px-4 py-2 text-xs text-green-600 max-w-xs truncate" x-text="log.valor_novo || '—'"></td>
                        </tr>
                    </template>
                    <template x-if="logs.length === 0">
                        <tr>
                            <td colspan="7" class="px-4 py-8 text-center text-gray-400 text-sm">Nenhum evento registrado ainda</td>
                        </tr>
                    </template>
                </tbody>
            </table>
        </div>
    </div>

</div>

{{-- Modal: Sinalizar Inconsistência --}}
<template x-if="modalSinalizar">
    <div class="fixed inset-0 bg-black/50 z-50 flex items-center justify-center p-4">
        <div class="bg-white rounded-2xl p-6 w-full max-w-sm shadow-2xl">
            <h3 class="font-semibold text-gray-800 mb-1">Sinalizar Inconsistência</h3>
            <p class="text-xs text-gray-400 mb-4" x-text="'Contato #' + contatoAtivo?.id + ' — ' + contatoAtivo?.nome"></p>
            <label class="block text-sm text-gray-600 mb-2">Descreva o problema</label>
            <textarea x-model="motivoAcao" rows="3"
                      class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-yellow-400 mb-4"
                      placeholder="Ex: CPF não confere com a Receita Federal, nome divergente..."></textarea>
            <div class="flex gap-2">
                <button @click="modalSinalizar = false" class="flex-1 border border-gray-300 text-gray-600 py-2 rounded-lg text-sm hover:bg-gray-50">Cancelar</button>
                <button @click="confirmarSinalizar()"
                        :disabled="!motivoAcao.trim()"
                        class="flex-1 bg-yellow-500 hover:bg-yellow-400 disabled:opacity-40 text-white py-2 rounded-lg text-sm">Sinalizar</button>
            </div>
        </div>
    </div>
</template>

{{-- Modal: Inativar --}}
<template x-if="modalInativar">
    <div class="fixed inset-0 bg-black/50 z-50 flex items-center justify-center p-4">
        <div class="bg-white rounded-2xl p-6 w-full max-w-sm shadow-2xl">
            <h3 class="font-semibold text-gray-800 mb-1">Inativar Contato</h3>
            <p class="text-xs text-gray-400 mb-1" x-text="'Contato #' + contatoAtivo?.id + ' — ' + contatoAtivo?.nome"></p>
            <p class="text-xs text-orange-600 mb-4">O contato será arquivado e não aparecerá mais no sistema. O registro permanece no banco de dados (sem exclusão física).</p>
            <label class="block text-sm text-gray-600 mb-2">Motivo da inativação</label>
            <textarea x-model="motivoAcao" rows="3"
                      class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-red-400 mb-4"
                      placeholder="Ex: Duplicidade confirmada, contato inválido, opt-out definitivo..."></textarea>
            <div class="flex gap-2">
                <button @click="modalInativar = false" class="flex-1 border border-gray-300 text-gray-600 py-2 rounded-lg text-sm hover:bg-gray-50">Cancelar</button>
                <button @click="confirmarInativar()"
                        :disabled="!motivoAcao.trim()"
                        class="flex-1 bg-red-600 hover:bg-red-500 disabled:opacity-40 text-white py-2 rounded-lg text-sm">Inativar</button>
            </div>
        </div>
    </div>
</template>

<script>
function auditor() {
    return {
        aba:           'pendentes',
        stats:         {},
        pendentes:     [],
        listaContatos: [],
        totalContatos: 0,
        paginaAtual:   1,
        totalPaginas:  1,
        logs:          [],
        filtros:       { busca: '', status: '', tipo_pessoa: '', origem: '' },
        conflitos:      [],
        modalSinalizar: false,
        modalInativar:  false,
        contatoAtivo:  null,
        motivoAcao:    '',

        async carregar() {
            await Promise.all([this.carregarStats(), this.carregarPendentes()]);
        },

        async carregarStats() {
            const res = await this.api('/api/painel/auditor/stats');
            if (res.ok) this.stats = await res.json();
        },

        async carregarPendentes() {
            const res = await this.api('/api/painel/auditor/pendentes');
            if (res.ok) {
                const d = await res.json();
                this.pendentes = d.data;
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
            const res = await this.api('/api/painel/auditor/contatos?' + params);
            if (res.ok) {
                const d = await res.json();
                this.listaContatos = d.data;
                this.totalContatos = d.total;
                this.totalPaginas  = d.ultima_pagina;
            }
        },

        async carregarConflitos() {
            const res = await this.api('/api/painel/auditor/conflitos');
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
            if (! confirm(labels[acao])) return;

            const res = await this.api(`/api/painel/auditor/conflito/${conflito.id}/${acao}`, 'POST');
            if (res.ok) {
                this.conflitos = this.conflitos.filter(c => c.id !== conflito.id);
                await this.carregarStats();
            }
        },

        async buscarLogs() {
            const res = await this.api('/api/painel/auditor/logs');
            if (res.ok) {
                const d = await res.json();
                this.logs = d.data;
            }
        },

        async aprovarNome(item) {
            const res = await this.api(`/api/painel/auditor/pendente/${item.vinculo_id}/aprovar`, 'POST');
            if (res.ok) {
                this.pendentes = this.pendentes.filter(p => p.vinculo_id !== item.vinculo_id);
                await this.carregarStats();
            }
        },

        async rejeitarNome(item) {
            if (! confirm(`Rejeitar sugestão "${item.nome_sugerido}" e manter "${item.nome_master}"?`)) return;
            const res = await this.api(`/api/painel/auditor/pendente/${item.vinculo_id}/rejeitar`, 'POST');
            if (res.ok) {
                this.pendentes = this.pendentes.filter(p => p.vinculo_id !== item.vinculo_id);
                await this.carregarStats();
            }
        },

        async aprovarCadastro(contato) {
            const res = await this.api(`/api/painel/auditor/contato/${contato.id}/aprovar-cadastro`, 'POST');
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
            if (! this.motivoAcao.trim()) return;
            const res = await this.api(`/api/painel/auditor/contato/${this.contatoAtivo.id}/sinalizar`, 'POST', {
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
            if (! this.motivoAcao.trim()) return;
            const res = await this.api(`/api/painel/auditor/contato/${this.contatoAtivo.id}/inativar`, 'POST', {
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
