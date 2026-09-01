@extends('layouts.app')
@section('title', 'Funções da Equipe — Lead Certo')

@section('content')
<div x-data="funcoesModule()" class="max-w-7xl mx-auto space-y-6">

    {{-- Topo / Header --}}
    <div class="flex flex-col md:flex-row md:items-center justify-between gap-4">
        <div>
            <h1 class="text-2xl font-bold text-gray-800 flex items-center gap-2">
                <span>📋</span> Funções & Cargos da Estrutura
            </h1>
            <p class="text-sm text-gray-500 mt-1">
                Catálogo de papéis, responsabilidades, ferramentas e diretrizes que operam no ecossistema da Lead Certo.
            </p>
        </div>

        @if(auth()->user()->isAdmin())
        <button @click="abrirNova()"
                class="bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-semibold px-4 py-2.5 rounded-xl shadow-sm hover:shadow transition flex items-center gap-2 self-start md:self-auto">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
            </svg>
            Nova Função
        </button>
        @endif
    </div>

    @if(session('sucesso'))
        <div class="p-4 bg-emerald-50 border border-emerald-200 text-emerald-800 rounded-xl text-sm flex items-center gap-2">
            <span>✅</span> {{ session('sucesso') }}
        </div>
    @endif

    {{-- Card Destaque: Suporte Direto com Adriana Aviag --}}
    @if($blocoSuporte)
    <div class="bg-gradient-to-r from-emerald-900 via-teal-900 to-slate-900 rounded-3xl shadow-xl text-white p-6 sm:p-8 relative overflow-hidden">
        <div class="absolute -right-10 -bottom-10 opacity-10 text-white pointer-events-none text-9xl">🎧</div>
        
        <div class="relative z-10 max-w-4xl">
            <div class="flex items-center gap-2 text-xs font-semibold uppercase tracking-wider text-emerald-400 bg-emerald-950/60 px-3 py-1 rounded-full w-fit mb-3">
                <span class="w-2 h-2 rounded-full bg-emerald-400 animate-pulse"></span>
                Canal Oficial de Atendimento & Feedback
            </div>

            <div class="flex flex-col lg:flex-row gap-6 items-start justify-between">
                <div class="space-y-2 flex-1">
                    <h2 class="text-xl sm:text-2xl font-bold flex items-center gap-2">
                        <span>{{ $blocoSuporte->icone ?? '🎧' }}</span> {{ $blocoSuporte->nome }}
                    </h2>
                    <p class="text-emerald-100/90 text-sm leading-relaxed">
                        {{ $blocoSuporte->descricao_cliente ?: $blocoSuporte->descricao }}
                    </p>
                    <div class="flex items-center gap-3 pt-2">
                        @foreach($blocoSuporte->agentes as $ag)
                            <div class="flex items-center gap-2 bg-white/10 backdrop-blur-md px-3 py-1.5 rounded-xl border border-white/10">
                                @if($ag->avatar_url)
                                    <img src="{{ $ag->avatar_url }}" class="w-6 h-6 rounded-full object-cover" alt="">
                                @else
                                    <div class="w-6 h-6 rounded-full bg-emerald-500 text-white flex items-center justify-center text-xs font-bold">
                                        {{ mb_substr($ag->nome, 0, 1) }}
                                    </div>
                                @endif
                                <span class="text-xs font-medium text-white">{{ $ag->nome }}</span>
                            </div>
                        @endforeach

                        <button @click="verDetalhes({{ json_encode($blocoSuporte) }})"
                                class="text-xs text-emerald-300 hover:text-white underline ml-2">
                            Ver Ficha Completa
                        </button>
                    </div>
                </div>

                {{-- Botão / Composer de Suporte --}}
                <div x-data="{ aberto: {{ $historicosSuporte->isNotEmpty() ? 'true' : 'false' }} }" class="w-full lg:w-96 bg-white/95 backdrop-blur-md rounded-2xl p-4 text-gray-800 shadow-lg">
                    <template x-if="!aberto">
                        <button @click="aberto = true"
                                class="w-full bg-emerald-600 hover:bg-emerald-700 text-white font-semibold py-3 rounded-xl transition flex items-center justify-center gap-2 shadow">
                            <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 24 24"><path d="M12 2C6.48 2 2 6.48 2 12c0 1.85.5 3.58 1.38 5.07L2 22l4.93-1.38A9.96 9.96 0 0012 22c5.52 0 10-4.48 10-10S17.52 2 12 2z"/></svg>
                            Falar com Suporte (Adriana)
                        </button>
                    </template>

                    <template x-if="aberto">
                        <div class="space-y-3">
                            <div class="flex items-center justify-between border-b border-gray-100 pb-2">
                                <span class="text-xs font-bold text-gray-700">Mensagens com a Equipe</span>
                                <button @click="aberto = false" class="text-xs text-gray-400 hover:text-gray-600">Fechar</button>
                            </div>

                            <div class="space-y-2 max-h-48 overflow-y-auto pr-1">
                                @forelse($historicosSuporte as $item)
                                    <div>
                                        <div class="bg-emerald-50 text-emerald-950 rounded-2xl rounded-tr-sm px-3 py-2 text-xs ml-auto max-w-[90%] border border-emerald-100">
                                            @if($item->tipo_midia === 'imagem' && $item->midia_url)
                                                <img src="{{ $item->midia_url }}" class="rounded-lg max-w-full max-h-24 mb-1">
                                            @elseif($item->tipo_midia === 'arquivo' && $item->midia_url)
                                                <a href="{{ $item->midia_url }}" target="_blank" class="underline block mb-1">📎 Arquivo</a>
                                            @endif
                                            {{ $item->mensagem }}
                                        </div>
                                        @if($item->resposta)
                                        <div class="bg-gray-100 text-gray-800 rounded-2xl rounded-tl-sm px-3 py-2 text-xs max-w-[90%] mt-1">
                                            {{ $item->resposta }}
                                        </div>
                                        @endif
                                    </div>
                                @empty
                                    <p class="text-xs text-gray-400 text-center py-2">Nenhuma conversa anterior.</p>
                                @endforelse
                            </div>

                            <form action="{{ route('equipe.conversar.store', $blocoSuporte->id) }}" method="POST"
                                  enctype="multipart/form-data" class="flex items-center gap-1.5 bg-gray-100 rounded-full pl-3 pr-1 py-1">
                                @csrf
                                <label class="cursor-pointer text-gray-400 hover:text-gray-600 flex-shrink-0" title="Anexar imagem, arquivo ou áudio">
                                    <input type="file" name="arquivo" class="hidden" accept="image/*,audio/*,.pdf,.doc,.docx,.xls,.xlsx,.txt,.zip">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.172 7l-6.586 6.586a2 2 0 102.828 2.828l6.414-6.586a4 4 0 00-5.656-5.656l-6.415 6.585a6 6 0 108.486 8.486L20.5 13"/></svg>
                                </label>
                                <input type="text" name="mensagem" placeholder="Escreva sua mensagem..." required
                                       class="flex-1 text-xs bg-transparent focus:outline-none py-1.5 text-gray-800">
                                <button type="submit" class="bg-emerald-600 hover:bg-emerald-700 text-white w-7 h-7 rounded-full flex items-center justify-center flex-shrink-0">
                                    <svg class="w-3.5 h-3.5" fill="currentColor" viewBox="0 0 24 24"><path d="M2 21l21-9L2 3v7l15 2-15 2z"/></svg>
                                </button>
                            </form>
                        </div>
                    </template>
                </div>
            </div>
        </div>
    </div>
    @endif

    {{-- Filtros por Categoria --}}
    <div class="flex items-center gap-2 overflow-x-auto pb-1 text-sm">
        <button @click="filtro = 'todos'"
                :class="filtro === 'todos' ? 'bg-indigo-600 text-white font-semibold' : 'bg-white text-gray-600 hover:bg-gray-50 border border-gray-200'"
                class="px-3.5 py-1.5 rounded-xl transition whitespace-nowrap">
            Todas as Funções ({{ $cargos->count() }})
        </button>
        <button @click="filtro = 'marketing'"
                :class="filtro === 'marketing' ? 'bg-indigo-600 text-white font-semibold' : 'bg-white text-gray-600 hover:bg-gray-50 border border-gray-200'"
                class="px-3.5 py-1.5 rounded-xl transition whitespace-nowrap">
            📢 Marketing
        </button>
        <button @click="filtro = 'comercial'"
                :class="filtro === 'comercial' ? 'bg-indigo-600 text-white font-semibold' : 'bg-white text-gray-600 hover:bg-gray-50 border border-gray-200'"
                class="px-3.5 py-1.5 rounded-xl transition whitespace-nowrap">
            📊 Comercial
        </button>
        <button @click="filtro = 'mentor'"
                :class="filtro === 'mentor' ? 'bg-indigo-600 text-white font-semibold' : 'bg-white text-gray-600 hover:bg-gray-50 border border-gray-200'"
                class="px-3.5 py-1.5 rounded-xl transition whitespace-nowrap">
            🧭 Mentores & Especialistas
        </button>
        <button @click="filtro = 'inteligencia'"
                :class="filtro === 'inteligencia' ? 'bg-indigo-600 text-white font-semibold' : 'bg-white text-gray-600 hover:bg-gray-50 border border-gray-200'"
                class="px-3.5 py-1.5 rounded-xl transition whitespace-nowrap">
            🧠 Inteligência & Orquestração
        </button>
        <button @click="filtro = 'suporte'"
                :class="filtro === 'suporte' ? 'bg-indigo-600 text-white font-semibold' : 'bg-white text-gray-600 hover:bg-gray-50 border border-gray-200'"
                class="px-3.5 py-1.5 rounded-xl transition whitespace-nowrap">
            🎧 Suporte & Atendimento
        </button>
    </div>

    {{-- Grid de Cards de Funções --}}
    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-5">
        @foreach($cargos as $cargo)
        <div x-show="filtro === 'todos' || filtro === '{{ $cargo->tipo }}'"
             class="bg-white rounded-2xl border border-gray-200 shadow-sm hover:shadow-md transition-all flex flex-col justify-between overflow-hidden">
            
            <div class="p-5 space-y-3">
                <div class="flex items-start justify-between gap-2">
                    <div class="flex items-center gap-2.5">
                        <span class="text-2xl p-2 bg-indigo-50 rounded-xl">{{ $cargo->icone ?: '💼' }}</span>
                        <div>
                            <h3 class="font-bold text-gray-800 text-base leading-tight">{{ $cargo->nome }}</h3>
                            <span class="inline-block text-[11px] font-semibold text-indigo-700 bg-indigo-50 px-2 py-0.5 rounded-md mt-1 uppercase tracking-wide">
                                {{ ucfirst($cargo->tipo) }}
                            </span>
                        </div>
                    </div>

                    @if(auth()->user()->isAdmin())
                    <button @click="editar({{ json_encode($cargo) }})"
                            class="text-xs text-gray-400 hover:text-indigo-600 p-1.5 rounded-lg hover:bg-gray-100 transition"
                            title="Editar Função">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z"/>
                        </svg>
                    </button>
                    @endif
                </div>

                {{-- Descrição Técnica / Escopo --}}
                <p class="text-xs text-gray-600 leading-relaxed bg-gray-50 p-3 rounded-xl border border-gray-100 line-clamp-3">
                    {{ $cargo->descricao }}
                </p>

                @if($cargo->cargoPai)
                <div class="text-[11px] text-gray-400 flex items-center gap-1">
                    <span>↳</span> Subordinado a: <strong class="text-gray-600">{{ $cargo->cargoPai->nome }}</strong>
                </div>
                @endif
            </div>

            {{-- Agentes Alocados & Botão Ficha Técnica --}}
            <div class="px-5 py-3 bg-gray-50/80 border-t border-gray-100 space-y-2">
                <div class="flex items-center justify-between">
                    <div class="flex items-center gap-2">
                        <span class="text-[11px] font-medium text-gray-500">Agentes:</span>
                        <div class="flex items-center -space-x-1.5">
                            @forelse($cargo->agentes as $ag)
                                @if($ag->avatar_url)
                                    <img src="{{ $ag->avatar_url }}" class="w-6 h-6 rounded-full ring-2 ring-white object-cover" title="{{ $ag->nome }}" alt="">
                                @else
                                    <div class="w-6 h-6 rounded-full ring-2 ring-white bg-indigo-500 text-white flex items-center justify-center text-[10px] font-bold" title="{{ $ag->nome }}">
                                        {{ mb_substr($ag->nome, 0, 1) }}
                                    </div>
                                @endif
                            @empty
                                <span class="text-[11px] text-gray-400 italic">Nenhum agente</span>
                            @endforelse
                        </div>
                    </div>

                    <button @click="verDetalhes({{ json_encode($cargo) }})"
                            class="text-xs text-indigo-600 hover:text-indigo-800 font-semibold flex items-center gap-1 hover:underline">
                        Ver Ficha <span>&rarr;</span>
                    </button>
                </div>
            </div>
        </div>
        @endforeach
    </div>

    {{-- Modal de Detalhes da Função (Ficha Técnica Aberta para Todos) --}}
    <div x-show="modalDetalhes" x-transition class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4">
        <div @click.outside="modalDetalhes = false" class="bg-white rounded-3xl shadow-2xl w-full max-w-2xl overflow-y-auto max-h-[90vh]">
            <div class="p-6 border-b border-gray-100 flex items-start justify-between bg-gradient-to-r from-indigo-50 via-white to-purple-50">
                <div class="flex items-center gap-3">
                    <span class="text-3xl p-2.5 bg-white rounded-2xl shadow-sm border border-gray-100" x-text="funcaoSelecionada.icone || '💼'"></span>
                    <div>
                        <h2 class="text-lg font-bold text-gray-800" x-text="funcaoSelecionada.nome"></h2>
                        <span class="inline-block text-xs font-semibold text-indigo-700 bg-indigo-100/70 px-2.5 py-0.5 rounded-md mt-1 uppercase"
                              x-text="funcaoSelecionada.tipo"></span>
                    </div>
                </div>
                <button @click="modalDetalhes = false" class="text-gray-400 hover:text-gray-600 text-lg">✕</button>
            </div>

            <div class="p-6 space-y-5 text-sm">
                {{-- Resumo da Função --}}
                <div>
                    <h3 class="text-xs font-bold text-gray-400 uppercase tracking-wider mb-1">Propósito & Resumo:</h3>
                    <p class="text-gray-700 leading-relaxed bg-gray-50 p-3.5 rounded-2xl border border-gray-100" x-text="funcaoSelecionada.descricao"></p>
                </div>

                {{-- Escopo Detalhado --}}
                <template x-if="funcaoSelecionada.detalhes_escopo">
                    <div>
                        <h3 class="text-xs font-bold text-gray-400 uppercase tracking-wider mb-1">🎯 Escopo de Atuação:</h3>
                        <div class="text-gray-700 leading-relaxed bg-indigo-50/40 p-4 rounded-2xl border border-indigo-100/60 whitespace-pre-line text-xs"
                             x-text="funcaoSelecionada.detalhes_escopo"></div>
                    </div>
                </template>

                {{-- Grid Ferramentas e KPIs --}}
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <template x-if="funcaoSelecionada.ferramentas">
                        <div class="bg-gray-50 p-4 rounded-2xl border border-gray-100">
                            <h4 class="text-xs font-bold text-gray-700 mb-1 flex items-center gap-1.5">
                                <span>🛠️</span> Ferramentas & Integrações
                            </h4>
                            <p class="text-xs text-gray-600" x-text="funcaoSelecionada.ferramentas"></p>
                        </div>
                    </template>

                    <template x-if="funcaoSelecionada.kpis">
                        <div class="bg-gray-50 p-4 rounded-2xl border border-gray-100">
                            <h4 class="text-xs font-bold text-gray-700 mb-1 flex items-center gap-1.5">
                                <span>📈</span> Métricas Chave (KPIs)
                            </h4>
                            <p class="text-xs text-gray-600" x-text="funcaoSelecionada.kpis"></p>
                        </div>
                    </template>
                </div>

                {{-- Diretriz da IA --}}
                <template x-if="funcaoSelecionada.diretriz_ia">
                    <div>
                        <h3 class="text-xs font-bold text-gray-400 uppercase tracking-wider mb-1">🧠 Manual / Diretriz de Operação da IA:</h3>
                        <p class="text-xs text-purple-900 bg-purple-50/60 p-3.5 rounded-2xl border border-purple-100 leading-relaxed"
                           x-text="funcaoSelecionada.diretriz_ia"></p>
                    </div>
                </template>

                {{-- Agentes Alocados --}}
                <div>
                    <h3 class="text-xs font-bold text-gray-400 uppercase tracking-wider mb-2">👥 Agentes Vinculados a esta Função:</h3>
                    <div class="flex flex-wrap gap-2">
                        <template x-for="ag in (funcaoSelecionada.agentes || [])" :key="ag.id">
                            <div class="flex items-center gap-2 bg-gray-50 px-3 py-2 rounded-xl border border-gray-200">
                                <template x-if="ag.avatar_url">
                                    <img :src="ag.avatar_url" class="w-6 h-6 rounded-full object-cover">
                                </template>
                                <template x-if="!ag.avatar_url">
                                    <div class="w-6 h-6 rounded-full bg-indigo-600 text-white flex items-center justify-center text-[10px] font-bold"
                                         x-text="ag.nome.charAt(0)"></div>
                                </template>
                                <div>
                                    <p class="text-xs font-bold text-gray-800" x-text="ag.nome"></p>
                                    <p class="text-[10px] text-gray-400" x-text="ag.email"></p>
                                </div>
                            </div>
                        </template>
                        <template x-if="!funcaoSelecionada.agentes || funcaoSelecionada.agentes.length === 0">
                            <p class="text-xs text-gray-400 italic">Nenhum agente vinculado no momento.</p>
                        </template>
                    </div>
                </div>
            </div>

            <div class="p-4 border-t border-gray-100 bg-gray-50 flex items-center justify-end gap-2">
                @if(auth()->user()->isAdmin())
                <button @click="modalDetalhes = false; editar(funcaoSelecionada)"
                        class="px-4 py-2 bg-indigo-600 hover:bg-indigo-700 text-white rounded-xl text-xs font-semibold shadow">
                    Editar Diretrizes
                </button>
                @endif
                <button @click="modalDetalhes = false" class="px-4 py-2 text-xs font-semibold text-gray-600 hover:text-gray-800">
                    Fechar
                </button>
            </div>
        </div>
    </div>

    {{-- Modal Nova / Editar Função (Apenas Super Admin) --}}
    @if(auth()->user()->isAdmin())
    <div x-show="modalAberto" x-transition class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4">
        <div @click.outside="modalAberto = false" class="bg-white rounded-3xl shadow-2xl w-full max-w-2xl overflow-y-auto max-h-[90vh]">
            <div class="p-5 border-b border-gray-100 flex items-center justify-between">
                <h3 class="text-base font-bold text-gray-800" x-text="editando ? 'Editar Função' : 'Nova Função'"></h3>
                <button @click="modalAberto = false" class="text-gray-400 hover:text-gray-600">✕</button>
            </div>

            <form :action="formAction" method="POST" class="p-6 space-y-4">
                @csrf
                <div class="grid grid-cols-4 gap-3">
                    <div class="col-span-3">
                        <label class="block text-xs font-semibold text-gray-700 mb-1">Nome da Função *</label>
                        <input type="text" name="nome" x-model="form.nome" required
                               class="w-full text-sm border border-gray-300 rounded-xl px-3 py-2 focus:ring-2 focus:ring-indigo-400 outline-none">
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-gray-700 mb-1">Ícone</label>
                        <input type="text" name="icone" x-model="form.icone" placeholder="Ex: 📢"
                               class="w-full text-sm border border-gray-300 rounded-xl px-3 py-2 text-center focus:ring-2 focus:ring-indigo-400 outline-none">
                    </div>
                </div>

                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="block text-xs font-semibold text-gray-700 mb-1">Categoria / Tipo *</label>
                        <select name="tipo" x-model="form.tipo" required
                                class="w-full text-sm border border-gray-300 rounded-xl px-3 py-2 focus:ring-2 focus:ring-indigo-400 outline-none">
                            <option value="marketing">Marketing</option>
                            <option value="comercial">Comercial</option>
                            <option value="mentor">Mentor</option>
                            <option value="inteligencia">Inteligência</option>
                            <option value="suporte">Suporte</option>
                            <option value="atendimento">Atendimento</option>
                            <option value="operacional">Operacional</option>
                        </select>
                    </div>

                    <div>
                        <label class="block text-xs font-semibold text-gray-700 mb-1">Reporta para (Cargo Superior)</label>
                        <select name="cargo_pai_id" x-model="form.cargo_pai_id"
                                class="w-full text-sm border border-gray-300 rounded-xl px-3 py-2 focus:ring-2 focus:ring-indigo-400 outline-none">
                            <option value="">Sem superior (Topo)</option>
                            @foreach($cargos as $c)
                                <option value="{{ $c->id }}">{{ $c->nome }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>

                <div>
                    <label class="block text-xs font-semibold text-gray-700 mb-1">Resumo / Propósito *</label>
                    <textarea name="descricao" x-model="form.descricao" rows="2" required
                              class="w-full text-sm border border-gray-300 rounded-xl px-3 py-2 focus:ring-2 focus:ring-indigo-400 outline-none"></textarea>
                </div>

                <div>
                    <label class="block text-xs font-semibold text-gray-700 mb-1">🎯 Escopo Detalhado de Atuação</label>
                    <textarea name="detalhes_escopo" x-model="form.detalhes_escopo" rows="3"
                              class="w-full text-sm border border-gray-300 rounded-xl px-3 py-2 focus:ring-2 focus:ring-indigo-400 outline-none"
                              placeholder="1. Atividade A...&#10;2. Atividade B..."></textarea>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <div>
                        <label class="block text-xs font-semibold text-gray-700 mb-1">🛠️ Ferramentas & Canais</label>
                        <input type="text" name="ferramentas" x-model="form.ferramentas" placeholder="Ex: Google Ads, WhatsApp, CRM..."
                               class="w-full text-sm border border-gray-300 rounded-xl px-3 py-2 focus:ring-2 focus:ring-indigo-400 outline-none">
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-gray-700 mb-1">📈 Métricas Chave (KPIs)</label>
                        <input type="text" name="kpis" x-model="form.kpis" placeholder="Ex: CPL, Conversão, CSAT..."
                               class="w-full text-sm border border-gray-300 rounded-xl px-3 py-2 focus:ring-2 focus:ring-indigo-400 outline-none">
                    </div>
                </div>

                <div>
                    <label class="block text-xs font-semibold text-gray-700 mb-1">🧠 Manual / Diretriz da IA</label>
                    <textarea name="diretriz_ia" x-model="form.diretriz_ia" rows="2"
                              class="w-full text-sm border border-gray-300 rounded-xl px-3 py-2 focus:ring-2 focus:ring-indigo-400 outline-none"
                              placeholder="Instruções de tom de voz e regras de negócio..."></textarea>
                </div>

                <div class="flex items-center justify-between pt-2">
                    <label class="flex items-center gap-2 text-xs text-gray-600">
                        <input type="checkbox" name="visivel_para_clientes" value="1" x-model="form.visivel_para_clientes" class="rounded">
                        Visível no Suporte dos Clientes
                    </label>

                    <div class="flex items-center gap-2">
                        <button type="button" @click="modalAberto = false" class="px-4 py-2 text-xs font-semibold text-gray-500 hover:text-gray-700">
                            Cancelar
                        </button>
                        <button type="submit" class="px-5 py-2 bg-indigo-600 hover:bg-indigo-700 text-white rounded-xl text-xs font-semibold shadow">
                            Salvar Função
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>
    @endif

</div>

<script>
function funcoesModule() {
    return {
        filtro: 'todos',
        modalAberto: false,
        modalDetalhes: false,
        editando: false,
        funcaoSelecionada: {},
        formAction: '{{ route("equipe.funcoes.store") }}',
        form: {
            nome: '',
            tipo: 'marketing',
            icone: '💼',
            descricao: '',
            descricao_cliente: '',
            detalhes_escopo: '',
            ferramentas: '',
            kpis: '',
            diretriz_ia: '',
            cargo_pai_id: '',
            visivel_para_clientes: false
        },
        verDetalhes(cargo) {
            this.funcaoSelecionada = cargo;
            this.modalDetalhes = true;
        },
        abrirNova() {
            this.editando = false;
            this.formAction = '{{ route("equipe.funcoes.store") }}';
            this.form = {
                nome: '',
                tipo: 'marketing',
                icone: '💼',
                descricao: '',
                descricao_cliente: '',
                detalhes_escopo: '',
                ferramentas: '',
                kpis: '',
                diretriz_ia: '',
                cargo_pai_id: '',
                visivel_para_clientes: false
            };
            this.modalAberto = true;
        },
        editar(cargo) {
            this.editando = true;
            this.formAction = '/equipe/funcoes/' + cargo.id;
            this.form = {
                nome: cargo.nome,
                tipo: cargo.tipo || 'operacional',
                icone: cargo.icone || '💼',
                descricao: cargo.descricao,
                descricao_cliente: cargo.descricao_cliente || '',
                detalhes_escopo: cargo.detalhes_escopo || '',
                ferramentas: cargo.ferramentas || '',
                kpis: cargo.kpis || '',
                diretriz_ia: cargo.diretriz_ia || '',
                cargo_pai_id: cargo.cargo_pai_id || '',
                visivel_para_clientes: !!cargo.visivel_para_clientes
            };
            this.modalAberto = true;
        }
    };
}
</script>
@endsection
