@extends('layouts.app')

@section('title', 'Contatos — Lead Certo')

@section('content')
<div x-data="contatos()" x-init="inicializar()">

    {{-- Header --}}
    <div class="flex items-start justify-between mb-6">
        <div>
            <h1 class="text-xl font-bold text-gray-800">Contatos</h1>
            <p class="text-sm text-gray-500">{{ number_format($total) }} contatos vinculados · {{ $ultimo_sync ? 'Último sync ' . $ultimo_sync : 'Nunca sincronizado' }}</p>
        </div>
        <div class="flex items-center gap-2">

            @if($google_conectado)
            <button @click="atualizarSobrenome()" :disabled="carregandoSobrenome"
                    title="Grava o ID do banco no campo Sobrenome de cada contato no Google"
                    class="flex items-center gap-2 bg-white border border-gray-300 hover:bg-gray-50 disabled:opacity-60 text-gray-600 text-sm px-3 py-2 rounded-xl shadow-sm transition-colors">
                <svg class="w-4 h-4 flex-shrink-0" :class="carregandoSobrenome && 'animate-spin'" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
                </svg>
                <span x-text="carregandoSobrenome ? 'Atualizando...' : 'Atualizar Google'"></span>
            </button>
            <button @click="sincronizarGoogle()" :disabled="carregandoGoogle"
                    class="flex items-center gap-2 bg-white border border-gray-300 hover:bg-gray-50 disabled:opacity-60 text-gray-700 text-sm font-medium px-4 py-2 rounded-xl shadow-sm transition-colors">
                <template x-if="!carregandoGoogle">
                    <svg viewBox="0 0 24 24" class="w-4 h-4 flex-shrink-0">
                        <path fill="#4285F4" d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09z"/>
                        <path fill="#34A853" d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z"/>
                        <path fill="#FBBC05" d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.07H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.93l2.85-2.22.81-.62z"/>
                        <path fill="#EA4335" d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.07l3.66 2.84c.87-2.6 3.3-4.53 6.16-4.53z"/>
                    </svg>
                </template>
                <template x-if="carregandoGoogle">
                    <svg class="w-4 h-4 animate-spin text-gray-400" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8H4z"/>
                    </svg>
                </template>
                <span x-text="carregandoGoogle ? 'Sincronizando...' : 'Sincronizar Google'"></span>
            </button>
            @else
            <a href="{{ route('integracoes') }}"
               class="flex items-center gap-2 bg-white border border-dashed border-gray-300 text-gray-400 text-sm px-4 py-2 rounded-xl shadow-sm hover:border-gray-400 transition-colors">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.656 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1"/>
                </svg>
                Conectar Google
            </a>
            @endif

            <button onclick="abrirModalNovoContato()"
                    class="flex items-center gap-1.5 bg-green-600 hover:bg-green-700 text-white text-sm font-medium px-3 py-2 rounded-xl shadow-sm transition-colors">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
                </svg>
                Novo Contato
            </button>
            <button @click="mostrarCsv = !mostrarCsv"
                    class="flex items-center gap-1.5 bg-white border border-gray-300 hover:bg-gray-50 text-gray-600 text-sm px-3 py-2 rounded-xl shadow-sm transition-colors">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                </svg>
                CSV
            </button>
        </div>
    </div>

    {{-- Flash de sessão --}}
    @if(session('sucesso'))
    <div class="bg-green-50 border border-green-200 text-green-700 text-sm rounded-xl px-4 py-3 mb-4">
        {{ session('sucesso') }}
    </div>
    @endif

    {{-- Resultado do sync Google --}}
    <template x-if="resultado">
        <div class="bg-green-50 border border-green-200 rounded-xl px-4 py-3 mb-4 flex items-center justify-between">
            <div class="text-sm text-green-700">
                <template x-if="resultado.em_progresso">
                    <span class="font-semibold">✓ Sincronização iniciada em segundo plano — os contatos serão atualizados em instantes.</span>
                </template>
                <template x-if="!resultado.em_progresso">
                    <span>
                        <span class="font-semibold" x-text="resultado.importados + ' contatos sincronizados'"></span>
                        <span class="text-green-600" x-text="resultado.ignorados ? ' · ' + resultado.ignorados + ' sem telefone ignorados' : ''"></span>
                    </span>
                </template>
            </div>
            <button @click="resultado = null; location.reload()"
                    class="text-xs text-green-600 hover:underline ml-4 flex-shrink-0">
                Recarregar lista
            </button>
        </div>
    </template>

    {{-- Resultado do write-back Google --}}
    <template x-if="resultadoSobrenome">
        <div class="bg-blue-50 border border-blue-200 rounded-xl px-4 py-3 mb-4 flex items-center justify-between">
            <div class="text-sm text-blue-700">
                <span class="font-semibold" x-text="resultadoSobrenome.atualizados + ' contatos atualizados no Google'"></span>
                <span x-text="resultadoSobrenome.falhas ? ' · ' + resultadoSobrenome.falhas + ' falhas' : ''"></span>
            </div>
            <button @click="resultadoSobrenome = null" class="text-xs text-blue-500 hover:underline ml-4">OK</button>
        </div>
    </template>

    {{-- Erro --}}
    <template x-if="erro">
        <div class="bg-red-50 border border-red-200 text-red-700 text-sm rounded-xl px-4 py-3 mb-4" x-text="erro"></div>
    </template>

    {{-- Painel CSV (colapsável) --}}
    <template x-if="mostrarCsv">
        <div class="bg-white rounded-2xl shadow-sm p-6 mb-4">
            <div class="flex items-center justify-between mb-4">
                <h2 class="text-sm font-semibold text-gray-700">Importar via CSV do Google</h2>
                <button @click="mostrarCsv = false" class="text-gray-400 hover:text-gray-600 text-lg leading-none">&times;</button>
            </div>

            <div class="flex items-start gap-3 mb-4 text-xs text-gray-500">
                <span class="w-5 h-5 rounded-full bg-gray-100 flex items-center justify-center font-bold flex-shrink-0">1</span>
                <span>
                    Acesse
                    <a href="https://contacts.google.com/export" target="_blank" class="text-green-600 hover:underline">contacts.google.com/export</a>
                    → Exportar → Google CSV
                </span>
            </div>

            <template x-if="!resultadoCsv">
                <div>
                    <label class="flex flex-col items-center justify-center w-full h-28 border-2 border-dashed rounded-xl cursor-pointer transition-colors"
                           :class="arquivo ? 'border-green-400 bg-green-50' : 'border-gray-200 hover:border-green-400'"
                           @dragover.prevent @drop.prevent="aoSoltar($event)">
                        <input type="file" accept=".csv,.txt" class="hidden" @change="aoEscolher($event)">
                        <template x-if="!arquivo">
                            <div class="text-center text-gray-400">
                                <svg class="w-6 h-6 mx-auto mb-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12"/>
                                </svg>
                                <p class="text-xs">Arraste o CSV ou clique para selecionar</p>
                            </div>
                        </template>
                        <template x-if="arquivo">
                            <div class="text-center text-green-600">
                                <svg class="w-6 h-6 mx-auto mb-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                                </svg>
                                <p class="text-xs font-medium" x-text="arquivo.name"></p>
                            </div>
                        </template>
                    </label>

                    <button @click="sincronizarCsv()" :disabled="!arquivo || carregandoCsv"
                            class="mt-3 w-full py-2.5 rounded-xl text-sm font-medium transition-colors"
                            :class="arquivo && !carregandoCsv ? 'bg-green-600 hover:bg-green-700 text-white' : 'bg-gray-100 text-gray-400 cursor-not-allowed'">
                        <span x-text="carregandoCsv ? 'Importando...' : 'Importar CSV'"></span>
                    </button>
                </div>
            </template>

            <template x-if="resultadoCsv">
                <div class="space-y-3">
                    <div class="bg-green-50 border border-green-200 rounded-xl p-3 text-sm text-green-700">
                        <span class="font-semibold" x-text="resultadoCsv.importados + ' contatos importados'"></span>
                        <span x-text="resultadoCsv.ignorados ? ' · ' + resultadoCsv.ignorados + ' sem telefone' : ''"></span>
                    </div>
                    <button @click="resultadoCsv = null; arquivo = null; mostrarCsv = false; location.reload()"
                            class="w-full py-2 rounded-xl text-sm text-gray-600 bg-gray-100 hover:bg-gray-200 transition-colors">
                        Fechar e recarregar
                    </button>
                </div>
            </template>
        </div>
    </template>

    {{-- Aviso base global --}}
    <div class="bg-blue-50 border border-blue-100 rounded-xl px-4 py-2.5 mb-4 flex items-center gap-2 text-xs text-blue-600">
        <svg class="w-3.5 h-3.5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
        </svg>
        <span>Contatos são compartilhados na base global. Mesmo número em outra empresa é vinculado sem duplicação.</span>
    </div>

    {{-- Busca --}}
    <form method="GET" action="{{ route('contatos.importar') }}" class="mb-4">
        <div class="flex gap-2">
            <div class="relative flex-1">
                <svg class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-gray-400 pointer-events-none"
                     fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                          d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
                </svg>
                <input type="text" name="q" value="{{ $busca }}"
                       placeholder="Buscar por nome, telefone, e-mail ou cidade..."
                       class="w-full pl-9 pr-4 py-2.5 text-sm border border-gray-200 rounded-xl bg-white shadow-sm focus:outline-none focus:ring-2 focus:ring-blue-400 focus:border-transparent">
                @if($busca)
                <a href="{{ route('contatos.importar') }}"
                   class="absolute right-3 top-1/2 -translate-y-1/2 text-gray-400 hover:text-gray-600 text-lg leading-none">&times;</a>
                @endif
            </div>
            <button type="submit"
                    class="px-4 py-2.5 bg-blue-600 hover:bg-blue-700 text-white text-sm font-medium rounded-xl shadow-sm transition-colors flex-shrink-0">
                Buscar
            </button>
        </div>
        @if($busca)
        <p class="text-xs text-gray-400 mt-1.5 ml-1">
            {{ $contatos->total() }} resultado{{ $contatos->total() !== 1 ? 's' : '' }} para <span class="font-medium text-gray-600">"{{ $busca }}"</span>
        </p>
        @endif
    </form>

    {{-- Lista de contatos --}}
    <div class="bg-white rounded-2xl shadow-sm overflow-hidden">
        <div class="px-5 py-3 border-b border-gray-100 flex items-center justify-between">
            <span class="text-sm font-medium text-gray-700">Lista de contatos</span>
            <span class="text-xs text-gray-400">{{ $contatos->total() }} total</span>
        </div>

        @forelse($contatos as $contato)
        <div class="flex items-center px-5 py-2.5 border-b border-gray-50 last:border-0 hover:bg-gray-50 group">
            <div class="flex-1 min-w-0">
                <div class="flex items-baseline gap-2">
                    <span class="text-sm text-gray-800 font-medium">{{ $contato->nome ?? '—' }}</span>
                    <span class="text-xs text-gray-400 flex-shrink-0">#{{ $contato->id }}</span>
                </div>
                @if($contato->profissao || $contato->empresa || $contato->cidade)
                <span class="text-xs text-gray-400">{{ implode(' · ', array_filter([$contato->profissao, $contato->empresa, $contato->cidade])) }}</span>
                @endif
            </div>
            <span class="text-xs text-gray-400 flex-shrink-0 ml-4 font-mono">{{ $contato->telefone }}</span>
            <button onclick="abrirFicha({{ $contato->id }})"
                    class="ml-3 opacity-0 group-hover:opacity-100 transition-opacity p-1.5 rounded-lg hover:bg-blue-50 hover:text-blue-600 text-gray-400"
                    title="Editar contato">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                          d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/>
                </svg>
            </button>
        </div>
        @empty
        <div class="px-5 py-12 text-center">
            <svg class="w-8 h-8 mx-auto text-gray-300 mb-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z"/>
            </svg>
            <p class="text-sm text-gray-400 mb-3">Nenhum contato ainda.</p>
            @if($google_conectado)
            <button @click="sincronizarGoogle()" class="text-sm text-green-600 hover:underline">
                Sincronizar do Google
            </button>
            @else
            <a href="{{ route('integracoes') }}" class="text-sm text-green-600 hover:underline">
                Conectar Google para sincronizar
            </a>
            @endif
        </div>
        @endforelse
    </div>

    {{-- Paginação --}}
    @if($contatos->hasPages())
    <div class="mt-4">
        {{ $contatos->links() }}
    </div>
    @endif

</div>

{{-- Modal: Novo Contato --}}
<div id="modal-novo-contato" class="hidden fixed inset-0 bg-black/50 z-50 flex items-center justify-center p-4">
    <div class="bg-white rounded-2xl p-6 w-full max-w-sm shadow-2xl">
        <h2 class="font-semibold text-gray-800 mb-4">Novo Contato</h2>

        <div class="space-y-3">
            <div>
                <label class="block text-xs font-medium text-gray-500 mb-1">Nome *</label>
                <input id="novo-nome" type="text" placeholder="Nome completo"
                       class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-green-500">
            </div>
            <div>
                <label class="block text-xs font-medium text-gray-500 mb-1">Telefone (WhatsApp) *</label>
                <input id="novo-telefone" type="text" placeholder="Ex: 21 99999-9999"
                       class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-green-500">
            </div>
            <div id="novo-erro" class="hidden text-xs text-red-600 bg-red-50 border border-red-200 rounded-lg px-3 py-2"></div>
        </div>

        <div class="flex gap-2 mt-5">
            <button onclick="fecharModalNovoContato()"
                    class="flex-1 border border-gray-300 text-gray-600 py-2 rounded-lg text-sm hover:bg-gray-50">
                Cancelar
            </button>
            <button onclick="salvarNovoContato()"
                    class="flex-1 bg-green-600 hover:bg-green-700 text-white py-2 rounded-lg text-sm font-medium transition-colors">
                Cadastrar
            </button>
        </div>
    </div>
</div>

{{-- Toast de feedback --}}
<div id="toast-feedback" style="display:none; position:fixed; bottom:24px; right:24px; z-index:9999; padding:12px 20px; border-radius:12px; font-size:13px; font-weight:500; box-shadow:0 4px 16px rgba(0,0,0,.15); transition:opacity .3s;"></div>

{{-- Overlay fundo --}}
<div id="drawer-overlay" class="hidden fixed inset-0 bg-black/40 z-40" onclick="fecharFicha()"></div>

{{-- Drawer lateral: Ficha do Contato --}}
<div id="drawer-ficha"
     class="hidden fixed top-0 right-0 h-full w-full max-w-xl bg-white shadow-2xl z-50 flex-col">

    {{-- Header --}}
    <div id="drawer-header" class="px-6 py-4 border-b border-gray-100 flex items-start justify-between gap-3 flex-shrink-0">
        <div class="flex items-center gap-3">
            <div id="drawer-avatar"
                 class="w-12 h-12 rounded-full bg-blue-100 flex items-center justify-center text-blue-700 font-bold text-lg flex-shrink-0">
                ?
            </div>
            <div>
                <h2 id="drawer-nome" class="font-semibold text-gray-800 text-base leading-tight">—</h2>
                <p id="drawer-telefone" class="text-xs text-gray-400 font-mono mt-0.5">—</p>
            </div>
        </div>
        <div class="flex items-center gap-2 flex-shrink-0">
            <button id="btn-editar" onclick="modoEdicao(true)"
                    class="text-xs bg-blue-600 hover:bg-blue-700 text-white px-3 py-1.5 rounded-lg font-medium transition-colors">
                Editar
            </button>
            <button id="btn-salvar" onclick="salvarFicha()"
                    class="text-xs bg-green-600 hover:bg-green-700 text-white px-3 py-1.5 rounded-lg font-medium transition-colors">
                Salvar
            </button>
            <button id="btn-cancelar" onclick="fecharFicha()"
                    class="text-xs text-gray-500 hover:text-gray-700 px-2 py-1.5 rounded-lg transition-colors">
                Cancelar
            </button>
            <button onclick="fecharFicha()" class="text-gray-400 hover:text-gray-600 text-xl leading-none ml-1">&times;</button>
        </div>
    </div>

    {{-- Conteúdo rolável --}}
    <div class="flex-1 overflow-y-auto px-6 py-5 space-y-6">

        {{-- Aviso auditoria --}}
        <div id="aviso-auditoria" class="hidden bg-yellow-50 border border-yellow-200 text-yellow-700 text-xs rounded-lg px-3 py-2">
            Nome enviado para auditoria — os demais dados foram salvos.
        </div>
        <div id="aviso-erro" class="hidden bg-red-50 border border-red-200 text-red-600 text-xs rounded-lg px-3 py-2"></div>

        {{-- Seção: Identificação --}}
        <div>
            <h3 class="text-xs font-semibold text-gray-400 uppercase tracking-widest mb-3">Identificação</h3>
            <div class="grid grid-cols-2 gap-3">
                <div class="col-span-2">
                    <label class="label-field">Nome completo</label>
                    <div class="view-field" data-field="nome"></div>
                    <input class="edit-field" data-field="nome" type="text" maxlength="200" placeholder="Nome completo">
                </div>
                <div>
                    <label class="label-field">Telefone (WhatsApp)</label>
                    <div class="view-field font-mono" data-field="telefone"></div>
                </div>
                <div>
                    <label class="label-field">E-mail</label>
                    <div class="view-field" data-field="email"></div>
                    <input class="edit-field" data-field="email" type="email" maxlength="200">
                </div>
                <div>
                    <label class="label-field">Tipo</label>
                    <div class="view-field" data-field="tipo_contato"></div>
                    <select class="edit-field" data-field="tipo_contato">
                        <option value="lead">Lead</option>
                        <option value="cliente">Cliente</option>
                        <option value="fornecedor">Fornecedor</option>
                        <option value="parceiro">Parceiro</option>
                        <option value="pessoal">Pessoal</option>
                    </select>
                </div>
                <div>
                    <label class="label-field">Opt-out</label>
                    <div class="view-field" data-field="opt_out"></div>
                    <select class="edit-field" data-field="opt_out">
                        <option value="0">Não</option>
                        <option value="1">Sim (não recebe msgs)</option>
                    </select>
                </div>
            </div>
        </div>

        {{-- Seção: Profissional --}}
        <div>
            <h3 class="text-xs font-semibold text-gray-400 uppercase tracking-widest mb-3">Profissional</h3>
            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="label-field">Profissão / Cargo</label>
                    <div class="view-field" data-field="profissao"></div>
                    <input class="edit-field" data-field="profissao" type="text" maxlength="200">
                </div>
                <div>
                    <label class="label-field">Empresa</label>
                    <div class="view-field" data-field="empresa"></div>
                    <input class="edit-field" data-field="empresa" type="text" maxlength="200">
                </div>
                <div>
                    <label class="label-field">Departamento</label>
                    <div class="view-field" data-field="departamento"></div>
                    <input class="edit-field" data-field="departamento" type="text" maxlength="200">
                </div>
                <div>
                    <label class="label-field">Website</label>
                    <div class="view-field" data-field="website"></div>
                    <input class="edit-field" data-field="website" type="text" maxlength="300">
                </div>
            </div>
        </div>

        {{-- Seção: Pessoal --}}
        <div>
            <h3 class="text-xs font-semibold text-gray-400 uppercase tracking-widest mb-3">Dados Pessoais</h3>
            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="label-field">Gênero</label>
                    <div class="view-field" data-field="genero"></div>
                    <input class="edit-field" data-field="genero" type="text" maxlength="30">
                </div>
                <div>
                    <label class="label-field">Estado civil</label>
                    <div class="view-field" data-field="estado_civil"></div>
                    <input class="edit-field" data-field="estado_civil" type="text" maxlength="30">
                </div>
                <div>
                    <label class="label-field">Aniversário</label>
                    <div class="view-field" data-field="aniversario"></div>
                    <input class="edit-field" data-field="aniversario" type="date">
                </div>
                <div>
                    <label class="label-field">CPF</label>
                    <div class="view-field" data-field="cpf"></div>
                    <input class="edit-field" data-field="cpf" type="text" maxlength="14">
                </div>
                <div>
                    <label class="label-field">RG</label>
                    <div class="view-field" data-field="rg"></div>
                    <input class="edit-field" data-field="rg" type="text" maxlength="20">
                </div>
            </div>
        </div>

        {{-- Seção: Endereço --}}
        <div>
            <h3 class="text-xs font-semibold text-gray-400 uppercase tracking-widest mb-3">Endereço</h3>
            <div class="grid grid-cols-2 gap-3">
                <div class="col-span-2">
                    <label class="label-field">Rua / Logradouro</label>
                    <div class="view-field" data-field="endereco"></div>
                    <input class="edit-field" data-field="endereco" type="text" maxlength="300">
                </div>
                <div>
                    <label class="label-field">Cidade</label>
                    <div class="view-field" data-field="cidade"></div>
                    <input class="edit-field" data-field="cidade" type="text" maxlength="100">
                </div>
                <div>
                    <label class="label-field">Estado</label>
                    <div class="view-field" data-field="estado"></div>
                    <input class="edit-field" data-field="estado" type="text" maxlength="50">
                </div>
                <div>
                    <label class="label-field">CEP</label>
                    <div class="view-field" data-field="cep"></div>
                    <input class="edit-field" data-field="cep" type="text" maxlength="20">
                </div>
                <div>
                    <label class="label-field">País</label>
                    <div class="view-field" data-field="pais"></div>
                    <input class="edit-field" data-field="pais" type="text" maxlength="50">
                </div>
            </div>
        </div>

        {{-- Seção: Redes Sociais --}}
        <div>
            <h3 class="text-xs font-semibold text-gray-400 uppercase tracking-widest mb-3">Redes Sociais</h3>
            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="label-field">Instagram</label>
                    <div class="view-field" data-field="instagram"></div>
                    <input class="edit-field" data-field="instagram" type="text" maxlength="200">
                </div>
                <div>
                    <label class="label-field">Facebook</label>
                    <div class="view-field" data-field="facebook"></div>
                    <input class="edit-field" data-field="facebook" type="text" maxlength="200">
                </div>
                <div>
                    <label class="label-field">LinkedIn</label>
                    <div class="view-field" data-field="linkedin"></div>
                    <input class="edit-field" data-field="linkedin" type="text" maxlength="200">
                </div>
                <div>
                    <label class="label-field">Twitter / X</label>
                    <div class="view-field" data-field="twitter"></div>
                    <input class="edit-field" data-field="twitter" type="text" maxlength="200">
                </div>
            </div>
        </div>

        {{-- Seção: Observações --}}
        <div>
            <h3 class="text-xs font-semibold text-gray-400 uppercase tracking-widest mb-3">Observações</h3>
            <div class="view-field whitespace-pre-wrap" data-field="observacoes"></div>
            <textarea class="edit-field" data-field="observacoes" rows="4"
                      placeholder="Observações sobre o contato..."></textarea>
        </div>

        {{-- Seção: Sistema (somente leitura) --}}
        <div class="bg-gray-50 rounded-xl px-4 py-3 space-y-1.5">
            <h3 class="text-xs font-semibold text-gray-400 uppercase tracking-widest mb-2">Sistema</h3>
            <div class="flex justify-between text-xs">
                <span class="text-gray-400">ID</span>
                <span id="sys-id" class="text-gray-600 font-mono">—</span>
            </div>
            <div class="flex justify-between text-xs">
                <span class="text-gray-400">Origem</span>
                <span id="sys-origem" class="text-gray-600">—</span>
            </div>
            <div class="flex justify-between text-xs">
                <span class="text-gray-400">Status validação</span>
                <span id="sys-status" class="text-gray-600">—</span>
            </div>
            <div class="flex justify-between text-xs">
                <span class="text-gray-400">Cadastrado em</span>
                <span id="sys-created" class="text-gray-600">—</span>
            </div>
        </div>

        {{-- Seção: Histórico de Atendimentos --}}
        <div>
            <h3 class="text-xs font-semibold text-gray-400 uppercase tracking-widest mb-3">Histórico de Atendimentos</h3>
            <div id="historico-loading" class="text-xs text-gray-400 text-center py-3">Carregando...</div>
            <div id="historico-lista" class="space-y-2 hidden"></div>
            <div id="historico-vazio" class="hidden text-xs text-gray-400 text-center py-3">Nenhum atendimento registrado.</div>
        </div>

        {{-- Zona de perigo --}}
        <div class="border border-red-100 rounded-xl px-4 py-3">
            <h3 class="text-xs font-semibold text-red-400 uppercase tracking-widest mb-3">Zona de perigo</h3>
            <div class="flex gap-2">
                <button onclick="desativarDoDrawer()"
                        class="flex-1 text-xs border border-gray-300 text-gray-600 hover:bg-gray-50 py-2 rounded-lg transition-colors">
                    Desativar (mantém cadastro)
                </button>
                <button onclick="excluirDoDrawer()"
                        class="flex-1 text-xs bg-red-50 border border-red-200 text-red-600 hover:bg-red-100 py-2 rounded-lg transition-colors">
                    Excluir definitivamente
                </button>
            </div>
        </div>

        <div class="h-6"></div>
    </div>
</div>

<style>
/* ── Labels e campos de visualização ───────────────────────────────────────── */
.label-field {
    display: block;
    font-size: 0.75rem;
    font-weight: 500;
    color: #9ca3af;
    margin-bottom: 0.125rem;
}
.view-field {
    display: block;
    font-size: 0.875rem;
    color: #374151;
    min-height: 1.5rem;
    padding: 0.125rem 0;
}
.view-field:empty::before { content: '—'; color: #d1d5db; }

/* ── Campos de edição (estilo visual) ──────────────────────────────────────── */
.edit-field {
    display: none;          /* padrão: escondidos */
    width: 100%;
    border: 1px solid #d1d5db;
    border-radius: 0.5rem;
    padding: 0.375rem 0.625rem;
    font-size: 0.875rem;
    color: #111827;
    background: #fff;
    outline: none;
    transition: border-color 0.15s, box-shadow 0.15s;
    box-sizing: border-box;
}
.edit-field:focus { border-color: #60a5fa; box-shadow: 0 0 0 3px rgba(96,165,250,0.2); }
textarea.edit-field { resize: none; }

/* ── Botões de ação (padrão: salvar/cancelar ocultos) ──────────────────────── */
#btn-salvar, #btn-cancelar { display: none; }

/* ── MODO EDIÇÃO — ativado pela classe .modo-edicao no #drawer-ficha ──────── */
#drawer-ficha.modo-edicao #btn-editar                       { display: none; }
#drawer-ficha.modo-edicao #btn-salvar,
#drawer-ficha.modo-edicao #btn-cancelar                     { display: inline-block; }
#drawer-ficha.modo-edicao .view-field                       { display: none; }
#drawer-ficha.modo-edicao .edit-field                       { display: block; }
#drawer-ficha.modo-edicao #drawer-header                    { background-color: #eff6ff; }
</style>

<script>
const CSRF = document.querySelector('meta[name="csrf-token"]').content;
let _fichaId     = null;
let _fichaData   = {};
let _emEdicao    = false;

async function abrirFicha(id) {
    _fichaId   = id;
    _emEdicao  = false;
    _fichaData = {};

    // Mostra drawer + overlay
    document.getElementById('drawer-overlay').classList.remove('hidden');
    const drawer = document.getElementById('drawer-ficha');
    drawer.classList.remove('hidden');
    drawer.style.display = 'flex';

    // Limpa avisos
    document.getElementById('aviso-auditoria').classList.add('hidden');
    document.getElementById('aviso-erro').classList.add('hidden');

    // Carrega dados
    const res = await fetch(`/api/painel/contato/${id}`, { headers: { Accept: 'application/json' } });
    modoEdicao(false); // sempre reseta botões, mesmo antes de checar ok
    if (!res.ok) {
        document.getElementById('aviso-erro').textContent = `Erro ao carregar contato #${id} (${res.status})`;
        document.getElementById('aviso-erro').style.display = 'block';
        return;
    }
    _fichaData = await res.json();

    preencherDrawer(_fichaData);
    modoEdicao(true);
    carregarHistorico(id);
}

async function carregarHistorico(id) {
    const loading = document.getElementById('historico-loading');
    const lista   = document.getElementById('historico-lista');
    const vazio   = document.getElementById('historico-vazio');

    loading.classList.remove('hidden');
    lista.classList.add('hidden');
    lista.innerHTML = '';
    vazio.classList.add('hidden');

    const res = await fetch(`/api/painel/contato/${id}/historico`, { headers: { Accept: 'application/json' } });
    loading.classList.add('hidden');
    if (!res.ok) return;

    const tickets = await res.json();
    if (!tickets.length) { vazio.classList.remove('hidden'); return; }

    const statusCor = { aberto: '#22c55e', pendente: '#f59e0b', resolvido: '#3b82f6', encerrado: '#6b7280' };
    const statusLabel = { aberto: 'Aberto', pendente: 'Pendente', resolvido: 'Resolvido', encerrado: 'Encerrado' };

    tickets.forEach(t => {
        const cor = statusCor[t.status] || '#9ca3af';
        const el  = document.createElement('div');
        el.style.cssText = 'border:1px solid #e5e7eb;border-radius:10px;padding:10px 12px;font-size:12px;';
        el.innerHTML = `
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:4px;">
                <span style="font-weight:600;color:#374151;">${t.coluna}</span>
                <span style="background:${cor}20;color:${cor};padding:1px 8px;border-radius:999px;font-size:11px;font-weight:500;">${statusLabel[t.status] || t.status}</span>
            </div>
            <div style="color:#9ca3af;display:flex;flex-wrap:wrap;gap:8px;">
                <span>📅 ${t.aberto_em || '—'}</span>
                ${t.encerrado_em ? `<span>→ ${t.encerrado_em}</span>` : ''}
                <span>💬 ${t.msgs_lead} msg${t.msgs_lead !== 1 ? 's' : ''} do lead</span>
                ${t.origem && t.origem !== 'whatsapp' ? `<span>📌 ${t.origem}</span>` : ''}
                ${t.tag_desfecho ? `<span>🏷 ${t.tag_desfecho}</span>` : ''}
            </div>`;
        lista.appendChild(el);
    });

    lista.classList.remove('hidden');
}

function fecharFicha() {
    document.getElementById('drawer-overlay').classList.add('hidden');
    const drawer = document.getElementById('drawer-ficha');
    drawer.classList.add('hidden');
    drawer.style.display = '';
    _fichaId = null;
}

function preencherDrawer(d) {
    // Header
    const inicial = (d.nome || d.telefone || '?').charAt(0).toUpperCase();
    document.getElementById('drawer-avatar').textContent   = inicial;
    document.getElementById('drawer-nome').textContent     = d.nome || '—';
    document.getElementById('drawer-telefone').textContent = d.telefone || '—';

    // View fields
    document.querySelectorAll('.view-field[data-field]').forEach(el => {
        const f  = el.dataset.field;
        let val  = d[f];
        if (f === 'opt_out') val = val ? 'Sim — não recebe mensagens' : 'Não';
        el.textContent = val ?? '';
    });

    // Edit fields
    document.querySelectorAll('.edit-field[data-field]').forEach(el => {
        const f = el.dataset.field;
        if (el.tagName === 'SELECT') {
            el.value = f === 'opt_out' ? (d[f] ? '1' : '0') : (d[f] ?? '');
        } else {
            el.value = d[f] ?? '';
        }
    });

    // Sistema
    document.getElementById('sys-id').textContent      = d.id;
    document.getElementById('sys-origem').textContent  = d.origem || '—';
    document.getElementById('sys-status').textContent  = d.status_validacao || '—';
    document.getElementById('sys-created').textContent = d.created_at
        ? new Date(d.created_at).toLocaleDateString('pt-BR')
        : '—';
}

function modoEdicao(ativar) {
    _emEdicao = ativar;
    // Toda a lógica visual está no CSS via .modo-edicao — sem conflito de especificidade
    document.getElementById('drawer-ficha').classList.toggle('modo-edicao', ativar);
    if (!ativar) preencherDrawer(_fichaData);
}

function mostrarToast(msg, cor) {
    const t = document.getElementById('toast-feedback');
    t.textContent = msg;
    t.style.background = cor;
    t.style.color = '#fff';
    t.style.display = 'block';
    t.style.opacity = '1';
    clearTimeout(window._toastTimer);
    window._toastTimer = setTimeout(() => { t.style.opacity = '0'; setTimeout(() => t.style.display = 'none', 300); }, 3000);
}

async function salvarFicha() {
    const payload = {};
    document.querySelectorAll('.edit-field[data-field]').forEach(el => {
        const f = el.dataset.field;
        if (f === 'telefone') return;
        payload[f] = el.value !== '' ? el.value : null;
    });

    let res, data;
    try {
        res  = await fetch(`/api/painel/contato/${_fichaId}`, {
            method: 'PATCH',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': CSRF, Accept: 'application/json' },
            body: JSON.stringify(payload),
        });
        data = await res.json();
    } catch (e) {
        mostrarToast('Erro de conexão ao salvar.', '#ef4444');
        return;
    }

    console.log('[salvarFicha] status:', res.status, 'body:', data);

    if (res.ok) {
        _fichaData = data.contato ?? { ..._fichaData, ...payload };
        document.querySelectorAll(`button[onclick="abrirFicha(${_fichaId})"]`).forEach(btn => {
            const span = btn.closest('div')?.querySelector('span.font-medium');
            if (span && _fichaData.nome) span.textContent = _fichaData.nome;
        });
        fecharFicha();
        if (data.auditoria) {
            mostrarToast('Dados salvos. O nome foi enviado para revisão.', '#f59e0b');
        } else {
            mostrarToast('Contato salvo com sucesso!', '#22c55e');
        }
    } else {
        const erroEl = document.getElementById('aviso-erro');
        const msg = data.message || data.erro || `Erro ${res.status} ao salvar.`;
        erroEl.textContent = msg;
        erroEl.classList.remove('hidden');
        mostrarToast('Erro ao salvar: ' + msg.substring(0, 60), '#ef4444');
    }
}

async function desativarDoDrawer() {
    if (!confirm('Desativar este contato? Ele sairá da sua lista, mas o cadastro global é mantido.')) return;
    const res = await fetch(`/api/painel/contato/${_fichaId}/desativar`, {
        method: 'POST',
        headers: { 'X-CSRF-TOKEN': CSRF },
    });
    if (res.ok) { fecharFicha(); location.reload(); }
}

async function excluirDoDrawer() {
    if (!confirm('Excluir definitivamente este contato? Esta ação não pode ser desfeita.')) return;
    const res = await fetch(`/api/painel/contato/${_fichaId}/excluir-definitivo`, {
        method: 'DELETE',
        headers: { 'X-CSRF-TOKEN': CSRF },
    });
    if (res.ok) { fecharFicha(); location.reload(); }
}

document.addEventListener('keydown', e => {
    if (e.key === 'Escape') { fecharFicha(); fecharModalNovoContato(); }
});

function abrirModalNovoContato() {
    document.getElementById('novo-nome').value = '';
    document.getElementById('novo-telefone').value = '';
    document.getElementById('novo-erro').classList.add('hidden');
    document.getElementById('modal-novo-contato').classList.remove('hidden');
    setTimeout(() => document.getElementById('novo-nome').focus(), 50);
}

function fecharModalNovoContato() {
    document.getElementById('modal-novo-contato').classList.add('hidden');
}

async function salvarNovoContato() {
    const nome     = document.getElementById('novo-nome').value.trim();
    const telefone = document.getElementById('novo-telefone').value.trim();
    const erroEl   = document.getElementById('novo-erro');

    erroEl.classList.add('hidden');

    if (!nome || !telefone) {
        erroEl.textContent = 'Preencha o nome e o telefone.';
        erroEl.classList.remove('hidden');
        return;
    }

    const res  = await fetch('/api/painel/contatos/criar', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': CSRF, Accept: 'application/json' },
        body: JSON.stringify({ nome, telefone }),
    });
    const data = await res.json();

    if (res.status === 409) {
        // Número já existe — avisa e abre a ficha
        erroEl.textContent = data.erro + ' Abrindo a ficha...';
        erroEl.classList.remove('hidden');
        setTimeout(() => { fecharModalNovoContato(); abrirFicha(data.contato_id); }, 1500);
        return;
    }

    if (!res.ok) {
        erroEl.textContent = data.erro || 'Erro ao cadastrar.';
        erroEl.classList.remove('hidden');
        return;
    }

    fecharModalNovoContato();
    mostrarToast(data.vinculado ? 'Contato existente vinculado à sua lista!' : 'Contato cadastrado!', '#22c55e');
    // Abre a ficha para completar os dados
    setTimeout(() => abrirFicha(data.contato_id), 400);
}
</script>

<script>
function contatos() {
    return {
        carregandoGoogle:    false,
        carregandoSobrenome: false,
        resultado:           null,
        resultadoSobrenome:  null,
        resultadoCsv:        null,
        erro:                null,
        mostrarCsv:          false,
        arquivo:             null,
        carregandoCsv:       false,

        inicializar() {
            @if(session('google_recente'))
            this.$nextTick(() => this.sincronizarGoogle());
            @endif
        },

        async sincronizarGoogle() {
            this.carregandoGoogle = true;
            this.resultado = null;
            this.erro = null;

            try {
                const res = await fetch('/api/painel/contatos/sincronizar-google', {
                    method: 'POST',
                    headers: {
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                        'Accept': 'application/json',
                    },
                });

                const data = await res.json();

                if (res.ok) {
                    this.resultado = data;
                } else {
                    this.erro = data.erro || 'Erro ao sincronizar com o Google.';
                }
            } catch (e) {
                this.erro = 'Erro de conexão. Verifique se o servidor está rodando.';
            } finally {
                this.carregandoGoogle = false;
            }
        },

        async atualizarSobrenome() {
            if (!confirm('Isso vai substituir o Sobrenome de todos os contatos no Google pelo ID do banco. Continuar?')) return;
            this.carregandoSobrenome = true;
            this.resultadoSobrenome = null;
            this.erro = null;

            try {
                const res = await fetch('/api/painel/contatos/atualizar-google-sobrenome', {
                    method: 'POST',
                    headers: {
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                        'Accept': 'application/json',
                    },
                });

                const data = await res.json();

                if (res.ok) {
                    this.resultadoSobrenome = data;
                } else {
                    this.erro = data.erro || 'Erro ao atualizar o Google.';
                }
            } catch (e) {
                this.erro = 'Erro de conexão.';
            } finally {
                this.carregandoSobrenome = false;
            }
        },

        aoEscolher(e) {
            this.arquivo = e.target.files[0] || null;
            this.resultadoCsv = null;
        },

        aoSoltar(e) {
            const f = e.dataTransfer.files[0];
            if (f && (f.name.endsWith('.csv') || f.name.endsWith('.txt'))) {
                this.arquivo = f;
                this.resultadoCsv = null;
            }
        },

        async sincronizarCsv() {
            if (!this.arquivo) return;
            this.carregandoCsv = true;

            const form = new FormData();
            form.append('arquivo', this.arquivo);
            form.append('_token', document.querySelector('meta[name="csrf-token"]').content);

            try {
                const res = await fetch('/api/painel/contatos/importar', {
                    method: 'POST',
                    body: form,
                });

                const data = await res.json();

                if (res.ok) {
                    this.resultadoCsv = data;
                } else {
                    this.erro = data.message || data.erro || 'Erro ao importar CSV.';
                }
            } catch (e) {
                this.erro = 'Erro de conexão.';
            } finally {
                this.carregandoCsv = false;
            }
        },
    };
}
</script>
@endsection
