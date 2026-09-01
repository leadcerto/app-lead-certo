@extends('layouts.app')
@section('title', 'Funções da Equipe — Lead Certo')

@section('content')
<div x-data="funcoesModule()" class="max-w-7xl mx-auto space-y-6">

    {{-- Topo / Header --}}
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 pb-2 border-b border-gray-100">
        <div>
            <div class="flex items-center gap-2">
                <span class="p-2 bg-indigo-50 text-indigo-600 rounded-xl text-lg">💼</span>
                <h1 class="text-2xl font-bold text-gray-900 tracking-tight">Funções & Cargos da Estrutura</h1>
            </div>
            <p class="text-sm text-gray-500 mt-1">
                Catálogo completo de papéis, responsabilidades, ferramentas e diretrizes operacionais.
            </p>
        </div>

        <div class="flex items-center gap-3">
            @if(auth()->user()?->isDono())
            <button @click="abrirNova()"
                    class="bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-semibold px-4 py-2.5 rounded-xl shadow-sm hover:shadow transition-all flex items-center gap-2">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
                </svg>
                Nova Função
            </button>
            @endif
        </div>
    </div>

    @if(session('sucesso'))
        <div class="p-4 bg-emerald-50 border border-emerald-200 text-emerald-800 rounded-2xl text-sm flex items-center gap-2 shadow-sm">
            <span>✅</span> {{ session('sucesso') }}
        </div>
    @endif

    {{-- Card Destaque Moderno: Suporte & Atendimento Oficial (Adriana) --}}
    @if($blocoSuporte)
    <div class="bg-gradient-to-br from-emerald-500/10 via-teal-500/5 to-white border border-emerald-200/80 rounded-3xl p-6 shadow-sm relative overflow-hidden">
        <div class="flex flex-col lg:flex-row lg:items-center justify-between gap-6 relative z-10">
            <div class="flex items-start gap-4">
                <div class="w-14 h-14 rounded-2xl bg-emerald-600 text-white flex items-center justify-center text-2xl flex-shrink-0 shadow-md shadow-emerald-200">
                    {{ $blocoSuporte->icone ?: '🎧' }}
                </div>
                <div class="space-y-1">
                    <div class="flex items-center gap-2">
                        <span class="text-xs font-bold uppercase tracking-wider text-emerald-700 bg-emerald-100/80 px-2.5 py-0.5 rounded-md">
                            Canal Oficial de Atendimento
                        </span>
                        <span class="w-2 h-2 rounded-full bg-emerald-500 animate-pulse"></span>
                    </div>
                    <h2 class="text-xl font-bold text-gray-900">{{ $blocoSuporte->nome }}</h2>
                    <p class="text-sm text-gray-600 max-w-2xl leading-relaxed">
                        {{ $blocoSuporte->descricao_cliente ?: $blocoSuporte->descricao }}
                    </p>
                    <div class="flex items-center gap-3 pt-1">
                        @foreach($blocoSuporte->agentes as $ag)
                            <div class="flex items-center gap-1.5 bg-white px-2.5 py-1 rounded-lg border border-emerald-200/70 text-xs font-medium text-emerald-950 shadow-2xs">
                                <span class="w-2 h-2 rounded-full bg-emerald-500"></span>
                                <span>{{ $ag->nome }}</span>
                            </div>
                        @endforeach
                        <button @click="verDetalhes({{ json_encode($blocoSuporte) }})" class="text-xs font-semibold text-emerald-700 hover:text-emerald-900 underline ml-1">
                            Ver Ficha Técnica
                        </button>
                    </div>
                </div>
            </div>

            {{-- Botão de Interação Rápida com o Suporte --}}
            <div x-data="{ aberto: false }" class="w-full lg:w-96 flex-shrink-0">
                <template x-if="!aberto">
                    <button @click="aberto = true"
                            class="w-full bg-emerald-600 hover:bg-emerald-700 text-white font-semibold py-3 px-5 rounded-2xl shadow-sm hover:shadow transition-all flex items-center justify-center gap-2.5">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"/>
                        </svg>
                        Falar com Suporte (Adriana)
                    </button>
                </template>

                <template x-if="aberto">
                    <div class="bg-white border border-emerald-200 rounded-2xl p-4 shadow-lg space-y-3">
                        <div class="flex items-center justify-between border-b border-gray-100 pb-2">
                            <span class="text-xs font-bold text-gray-800 flex items-center gap-1.5">
                                <span class="w-2 h-2 rounded-full bg-emerald-500"></span> Mensagens com Suporte
                            </span>
                            <button @click="aberto = false" class="text-xs text-gray-400 hover:text-gray-600">✕ Fechar</button>
                        </div>

                        <div class="space-y-2 max-h-44 overflow-y-auto pr-1">
                            @forelse($historicosSuporte as $item)
                                <div class="space-y-1">
                                    <div class="bg-emerald-50 text-emerald-950 rounded-2xl rounded-tr-xs px-3 py-2 text-xs ml-auto max-w-[90%] border border-emerald-100">
                                        @if($item->tipo_midia === 'imagem' && $item->midia_url)
                                            <img src="{{ $item->midia_url }}" class="rounded-lg max-w-full max-h-24 mb-1">
                                        @elseif($item->tipo_midia === 'arquivo' && $item->midia_url)
                                            <a href="{{ $item->midia_url }}" target="_blank" class="underline block mb-1">📎 Arquivo</a>
                                        @endif
                                        {{ $item->mensagem }}
                                    </div>
                                    @if($item->resposta)
                                    <div class="bg-gray-100 text-gray-800 rounded-2xl rounded-tl-xs px-3 py-2 text-xs max-w-[90%]">
                                        {{ $item->resposta }}
                                    </div>
                                    @endif
                                </div>
                            @empty
                                <p class="text-xs text-gray-400 text-center py-2">Nenhuma mensagem recente.</p>
                            @endforelse
                        </div>

                        <form action="{{ route('equipe.conversar.store', $blocoSuporte->id) }}" method="POST"
                              enctype="multipart/form-data" class="flex items-center gap-1.5 bg-gray-50 border border-gray-200 rounded-xl px-2 py-1">
                            @csrf
                            <label class="cursor-pointer text-gray-400 hover:text-gray-600 flex-shrink-0 p-1" title="Anexar arquivo">
                                <input type="file" name="arquivo" class="hidden">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.172 7l-6.586 6.586a2 2 0 102.828 2.828l6.414-6.586a4 4 0 00-5.656-5.656l-6.415 6.585a6 6 0 108.486 8.486L20.5 13"/></svg>
                            </label>
                            <input type="text" name="mensagem" placeholder="Escreva sua dúvida ou feedback..." required
                                   class="flex-1 text-xs bg-transparent focus:outline-none py-1 text-gray-800">
                            <button type="submit" class="bg-emerald-600 hover:bg-emerald-700 text-white w-7 h-7 rounded-lg flex items-center justify-center flex-shrink-0 transition">
                                <svg class="w-3.5 h-3.5" fill="currentColor" viewBox="0 0 24 24"><path d="M2 21l21-9L2 3v7l15 2-15 2z"/></svg>
                            </button>
                        </form>
                    </div>
                </template>
            </div>
        </div>
    </div>
    @endif

    {{-- Filtros por Categoria --}}
    <div class="flex items-center gap-2 overflow-x-auto pb-1 text-xs font-medium">
        <button @click="filtro = 'todos'"
                :class="filtro === 'todos' ? 'bg-indigo-600 text-white shadow-sm' : 'bg-white text-gray-600 hover:bg-gray-50 border border-gray-200'"
                class="px-4 py-2 rounded-xl transition whitespace-nowrap">
            Todas as Funções ({{ $cargos->count() }})
        </button>
        <button @click="filtro = 'marketing'"
                :class="filtro === 'marketing' ? 'bg-rose-600 text-white shadow-sm' : 'bg-white text-gray-600 hover:bg-gray-50 border border-gray-200'"
                class="px-4 py-2 rounded-xl transition whitespace-nowrap flex items-center gap-1.5">
            <span>📢</span> Marketing
        </button>
        <button @click="filtro = 'comercial'"
                :class="filtro === 'comercial' ? 'bg-blue-600 text-white shadow-sm' : 'bg-white text-gray-600 hover:bg-gray-50 border border-gray-200'"
                class="px-4 py-2 rounded-xl transition whitespace-nowrap flex items-center gap-1.5">
            <span>📊</span> Comercial
        </button>
        <button @click="filtro = 'mentor'"
                :class="filtro === 'mentor' ? 'bg-amber-600 text-white shadow-sm' : 'bg-white text-gray-600 hover:bg-gray-50 border border-gray-200'"
                class="px-4 py-2 rounded-xl transition whitespace-nowrap flex items-center gap-1.5">
            <span>🧭</span> Mentores & Especialistas
        </button>
        <button @click="filtro = 'inteligencia'"
                :class="filtro === 'inteligencia' ? 'bg-purple-600 text-white shadow-sm' : 'bg-white text-gray-600 hover:bg-gray-50 border border-gray-200'"
                class="px-4 py-2 rounded-xl transition whitespace-nowrap flex items-center gap-1.5">
            <span>🧠</span> Inteligência & Orquestração
        </button>
        <button @click="filtro = 'suporte'"
                :class="filtro === 'suporte' ? 'bg-emerald-600 text-white shadow-sm' : 'bg-white text-gray-600 hover:bg-gray-50 border border-gray-200'"
                class="px-4 py-2 rounded-xl transition whitespace-nowrap flex items-center gap-1.5">
            <span>🎧</span> Suporte & Atendimento
        </button>
    </div>

    {{-- Grid de Cards de Funções --}}
    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-5">
        @foreach($cargos as $cargo)
        @php
            $categoriaCores = match($cargo->tipo) {
                'marketing'    => ['bg' => 'bg-rose-50', 'text' => 'text-rose-700', 'border' => 'border-rose-100', 'tag' => 'bg-rose-100 text-rose-800'],
                'comercial'    => ['bg' => 'bg-blue-50', 'text' => 'text-blue-700', 'border' => 'border-blue-100', 'tag' => 'bg-blue-100 text-blue-800'],
                'mentor'       => ['bg' => 'bg-amber-50', 'text' => 'text-amber-700', 'border' => 'border-amber-100', 'tag' => 'bg-amber-100 text-amber-800'],
                'inteligencia' => ['bg' => 'bg-purple-50', 'text' => 'text-purple-700', 'border' => 'border-purple-100', 'tag' => 'bg-purple-100 text-purple-800'],
                'suporte'      => ['bg' => 'bg-emerald-50', 'text' => 'text-emerald-700', 'border' => 'border-emerald-100', 'tag' => 'bg-emerald-100 text-emerald-800'],
                default        => ['bg' => 'bg-gray-50', 'text' => 'text-gray-700', 'border' => 'border-gray-100', 'tag' => 'bg-gray-100 text-gray-800'],
            };
        @endphp
        <div x-show="filtro === 'todos' || filtro === '{{ $cargo->tipo }}'"
             class="bg-white rounded-3xl border border-gray-200/80 shadow-sm hover:shadow-lg hover:-translate-y-1 transition-all duration-300 flex flex-col justify-between overflow-hidden group">
            
            <div class="p-6 space-y-4">
                {{-- Cabeçalho do Card --}}
                <div class="flex items-start justify-between gap-3">
                    <div class="flex items-center gap-3">
                        <div class="w-12 h-12 rounded-2xl {{ $categoriaCores['bg'] }} flex items-center justify-center text-2xl flex-shrink-0 group-hover:scale-105 transition-transform">
                            {{ $cargo->icone ?: '💼' }}
                        </div>
                        <div>
                            <h3 class="font-bold text-gray-900 text-base leading-tight group-hover:text-indigo-600 transition-colors">{{ $cargo->nome }}</h3>
                            <span class="inline-block text-[10px] font-bold px-2 py-0.5 rounded-md mt-1 uppercase tracking-wider {{ $categoriaCores['tag'] }}">
                                {{ ucfirst($cargo->tipo) }}
                            </span>
                        </div>
                    </div>

                    @if(auth()->user()?->isDono())
                    <button @click="editar({{ json_encode($cargo) }})"
                            class="text-gray-400 hover:text-indigo-600 p-1.5 rounded-xl hover:bg-gray-50 transition"
                            title="Editar Função">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z"/>
                        </svg>
                    </button>
                    @endif
                </div>

                {{-- Descrição do Papel --}}
                <div class="bg-gray-50/80 p-3.5 rounded-2xl border border-gray-100/80">
                    <p class="text-xs text-gray-600 leading-relaxed line-clamp-3">
                        {{ $cargo->descricao }}
                    </p>
                </div>

                @if($cargo->cargoPai)
                <div class="text-[11px] text-gray-400 flex items-center gap-1.5">
                    <span class="text-gray-300">↳</span>
                    <span>Reporta a: <strong class="text-gray-700 font-medium">{{ $cargo->cargoPai->nome }}</strong></span>
                </div>
                @endif
            </div>

            {{-- Rodapé do Card com Agentes e Ficha --}}
            <div class="px-6 py-4 bg-gray-50/60 border-t border-gray-100 flex items-center justify-between">
                <div class="flex items-center gap-2">
                    <span class="text-[11px] font-medium text-gray-400">Alocados:</span>
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
                            <span class="text-[11px] text-gray-400 italic">Nenhum</span>
                        @endforelse
                    </div>
                </div>

                <button @click="verDetalhes({{ json_encode($cargo) }})"
                        class="text-xs font-semibold text-indigo-600 hover:text-indigo-800 flex items-center gap-1 group-hover:translate-x-0.5 transition-transform">
                    Ver Ficha <span>&rarr;</span>
                </button>
            </div>
        </div>
        @endforeach
    </div>

    {{-- Modal de Detalhes da Função (Ficha Técnica Completa) --}}
    <div x-show="modalDetalhes" x-transition.opacity class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 backdrop-blur-xs p-4">
        <div @click.outside="modalDetalhes = false" class="bg-white rounded-3xl shadow-2xl w-full max-w-2xl overflow-hidden max-h-[90vh] flex flex-col">
            <div class="p-6 border-b border-gray-100 flex items-start justify-between bg-gradient-to-r from-indigo-50/70 via-white to-purple-50/70">
                <div class="flex items-center gap-3.5">
                    <div class="w-14 h-14 rounded-2xl bg-white shadow-sm border border-gray-100 flex items-center justify-center text-3xl">
                        <span x-text="funcaoSelecionada.icone || '💼'"></span>
                    </div>
                    <div>
                        <h2 class="text-xl font-bold text-gray-900" x-text="funcaoSelecionada.nome"></h2>
                        <span class="inline-block text-xs font-bold text-indigo-700 bg-indigo-100/70 px-2.5 py-0.5 rounded-md mt-1 uppercase tracking-wider"
                              x-text="funcaoSelecionada.tipo"></span>
                    </div>
                </div>
                <button @click="modalDetalhes = false" class="text-gray-400 hover:text-gray-600 text-xl font-medium p-1">✕</button>
            </div>

            <div class="p-6 space-y-5 text-sm overflow-y-auto flex-1">
                {{-- Resumo da Função --}}
                <div>
                    <h3 class="text-xs font-bold text-gray-400 uppercase tracking-wider mb-1.5">Propósito & Resumo da Função:</h3>
                    <p class="text-gray-700 leading-relaxed bg-gray-50 p-4 rounded-2xl border border-gray-100" x-text="funcaoSelecionada.descricao"></p>
                </div>

                {{-- Escopo & Responsabilidades --}}
                <template x-if="funcaoSelecionada.escopo_detalhado">
                    <div>
                        <h3 class="text-xs font-bold text-gray-400 uppercase tracking-wider mb-1.5">Escopo & Responsabilidades:</h3>
                        <div class="text-gray-700 leading-relaxed bg-gray-50 p-4 rounded-2xl border border-gray-100 whitespace-pre-line"
                             x-text="funcaoSelecionada.escopo_detalhado"></div>
                    </div>
                </template>

                {{-- Ferramentas Utilizadas --}}
                <template x-if="funcaoSelecionada.ferramentas && funcaoSelecionada.ferramentas.length">
                    <div>
                        <h3 class="text-xs font-bold text-gray-400 uppercase tracking-wider mb-1.5">Ferramentas & Acessos:</h3>
                        <div class="flex flex-wrap gap-2">
                            <template x-for="(ferr, i) in (Array.isArray(funcaoSelecionada.ferramentas) ? funcaoSelecionada.ferramentas : JSON.parse(funcaoSelecionada.ferramentas || '[]'))" :key="i">
                                <span class="bg-indigo-50 border border-indigo-100 text-indigo-700 px-3 py-1 rounded-xl text-xs font-medium" x-text="ferr"></span>
                            </template>
                        </div>
                    </div>
                </template>

                {{-- KPIs e Métricas de Sucesso --}}
                <template x-if="funcaoSelecionada.kpis_sucesso && funcaoSelecionada.kpis_sucesso.length">
                    <div>
                        <h3 class="text-xs font-bold text-gray-400 uppercase tracking-wider mb-1.5">Métricas & KPIs de Sucesso:</h3>
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-2">
                            <template x-for="(kpi, i) in (Array.isArray(funcaoSelecionada.kpis_sucesso) ? funcaoSelecionada.kpis_sucesso : JSON.parse(funcaoSelecionada.kpis_sucesso || '[]'))" :key="i">
                                <div class="bg-emerald-50 border border-emerald-100 text-emerald-800 p-2.5 rounded-xl text-xs flex items-center gap-2">
                                    <span class="text-emerald-500">🎯</span>
                                    <span x-text="kpi"></span>
                                </div>
                            </template>
                        </div>
                    </div>
                </template>

                {{-- Diretrizes para Agentes de IA --}}
                <template x-if="funcaoSelecionada.diretrizes_ia">
                    <div>
                        <h3 class="text-xs font-bold text-purple-700 uppercase tracking-wider mb-1.5 flex items-center gap-1.5">
                            <span>🤖</span> Diretrizes para os Agentes de IA:
                        </h3>
                        <div class="text-purple-900 bg-purple-50/70 border border-purple-200/80 p-4 rounded-2xl text-xs leading-relaxed whitespace-pre-line"
                             x-text="funcaoSelecionada.diretrizes_ia"></div>
                    </div>
                </template>
            </div>

            <div class="p-4 border-t border-gray-100 bg-gray-50 flex justify-end">
                <button @click="modalDetalhes = false" class="bg-gray-200 hover:bg-gray-300 text-gray-700 text-xs font-semibold px-5 py-2.5 rounded-xl transition">
                    Fechar
                </button>
            </div>
        </div>
    </div>

    {{-- Modal de Criar / Editar Função (Apenas Super Admin / Dono) --}}
    @if(auth()->user()?->isDono())
    <div x-show="modalForm" x-transition.opacity class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 backdrop-blur-xs p-4">
        <div @click.outside="modalForm = false" class="bg-white rounded-3xl shadow-2xl w-full max-w-2xl overflow-hidden max-h-[90vh] flex flex-col">
            <div class="p-6 border-b border-gray-100 flex items-center justify-between">
                <h2 class="text-lg font-bold text-gray-900" x-text="editandoId ? 'Editar Função' : 'Nova Função'"></h2>
                <button @click="modalForm = false" class="text-gray-400 hover:text-gray-600 text-xl font-medium">✕</button>
            </div>

            <form :action="editandoId ? '/equipe/funcoes/' + editandoId : '{{ route('equipe.funcoes.store') }}'" method="POST" class="p-6 space-y-4 overflow-y-auto flex-1">
                @csrf
                <template x-if="editandoId">
                    <input type="hidden" name="_method" value="PUT">
                </template>

                <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                    <div class="sm:col-span-2 space-y-1">
                        <label class="text-xs font-bold text-gray-700">Nome da Função</label>
                        <input type="text" name="nome" x-model="form.nome" required class="w-full text-xs border border-gray-300 rounded-xl p-2.5 focus:ring-2 focus:ring-indigo-500">
                    </div>
                    <div class="space-y-1">
                        <label class="text-xs font-bold text-gray-700">Ícone / Emoji</label>
                        <input type="text" name="icone" x-model="form.icone" placeholder="Ex: 📊" class="w-full text-xs border border-gray-300 rounded-xl p-2.5 focus:ring-2 focus:ring-indigo-500">
                    </div>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <div class="space-y-1">
                        <label class="text-xs font-bold text-gray-700">Departamento / Categoria</label>
                        <select name="tipo" x-model="form.tipo" required class="w-full text-xs border border-gray-300 rounded-xl p-2.5 focus:ring-2 focus:ring-indigo-500">
                            <option value="marketing">Marketing</option>
                            <option value="comercial">Comercial</option>
                            <option value="mentor">Mentores & Especialistas</option>
                            <option value="inteligencia">Inteligência & Orquestração</option>
                            <option value="suporte">Suporte & Atendimento</option>
                        </select>
                    </div>
                    <div class="space-y-1">
                        <label class="text-xs font-bold text-gray-700">Subordinado a (Cargo Pai)</label>
                        <select name="cargo_pai_id" x-model="form.cargo_pai_id" class="w-full text-xs border border-gray-300 rounded-xl p-2.5 focus:ring-2 focus:ring-indigo-500">
                            <option value="">Nenhum (Topo da Hierarquia)</option>
                            @foreach($cargos as $c)
                                <option value="{{ $c->id }}">{{ $c->nome }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>

                <div class="space-y-1">
                    <label class="text-xs font-bold text-gray-700">Resumo da Função</label>
                    <textarea name="descricao" x-model="form.descricao" rows="2" class="w-full text-xs border border-gray-300 rounded-xl p-2.5 focus:ring-2 focus:ring-indigo-500"></textarea>
                </div>

                <div class="space-y-1">
                    <label class="text-xs font-bold text-gray-700">Escopo Detalhado & Responsabilidades</label>
                    <textarea name="escopo_detalhado" x-model="form.escopo_detalhado" rows="3" class="w-full text-xs border border-gray-300 rounded-xl p-2.5 focus:ring-2 focus:ring-indigo-500"></textarea>
                </div>

                <div class="space-y-1">
                    <label class="text-xs font-bold text-purple-700">Diretrizes para IA (Instruções e prompts)</label>
                    <textarea name="diretrizes_ia" x-model="form.diretrizes_ia" rows="3" class="w-full text-xs border border-purple-200 bg-purple-50/50 rounded-xl p-2.5 focus:ring-2 focus:ring-purple-500"></textarea>
                </div>

                <div class="pt-3 border-t border-gray-100 flex justify-end gap-2">
                    <button type="button" @click="modalForm = false" class="px-4 py-2 bg-gray-100 text-gray-600 rounded-xl text-xs font-semibold hover:bg-gray-200">Cancelar</button>
                    <button type="submit" class="px-5 py-2 bg-indigo-600 text-white rounded-xl text-xs font-semibold hover:bg-indigo-700 shadow-sm">Salvar Função</button>
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
        modalDetalhes: false,
        modalForm: false,
        editandoId: null,
        funcaoSelecionada: {},
        form: {
            nome: '',
            icone: '',
            tipo: 'marketing',
            cargo_pai_id: '',
            descricao: '',
            escopo_detalhado: '',
            diretrizes_ia: ''
        },
        verDetalhes(cargo) {
            this.funcaoSelecionada = cargo;
            this.modalDetalhes = true;
        },
        abrirNova() {
            this.editandoId = null;
            this.form = {
                nome: '',
                icone: '',
                tipo: 'marketing',
                cargo_pai_id: '',
                descricao: '',
                escopo_detalhado: '',
                diretrizes_ia: ''
            };
            this.modalForm = true;
        },
        editar(cargo) {
            this.editandoId = cargo.id;
            this.form = {
                nome: cargo.nome,
                icone: cargo.icone || '',
                tipo: cargo.tipo || 'marketing',
                cargo_pai_id: cargo.cargo_pai_id || '',
                descricao: cargo.descricao || '',
                escopo_detalhado: cargo.escopo_detalhado || '',
                diretrizes_ia: cargo.diretrizes_ia || ''
            };
            this.modalForm = true;
        }
    };
}
</script>
@endsection
