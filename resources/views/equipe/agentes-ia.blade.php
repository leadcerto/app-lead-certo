@extends('layouts.app')
@section('title', 'Agentes de IA — Equipe Lead Certo')

@section('content')
<div x-data="agentesIaModule()" class="max-w-7xl mx-auto space-y-6">

    {{-- Topo / Header --}}
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 pb-2 border-b border-gray-100">
        <div>
            <div class="flex items-center gap-2">
                <span class="p-2 bg-purple-50 text-purple-600 rounded-xl text-lg">🤖</span>
                <h1 class="text-2xl font-bold text-gray-900 tracking-tight">Agentes Inteligentes (IA)</h1>
            </div>
            <p class="text-sm text-gray-500 mt-1">
                Agentes autônomos com IA responsáveis pelas operações de atendimento, suporte, marketing e comercial.
            </p>
        </div>

        <div class="flex items-center gap-2.5 self-start sm:self-auto">
            <a href="{{ route('equipe.relatorio-ia') }}"
               class="bg-white hover:bg-gray-50 text-purple-700 border border-purple-200 text-xs font-semibold px-4 py-2.5 rounded-xl shadow-2xs transition-all flex items-center gap-1.5">
                <span>⚡</span> Relatório de Uso & IA
            </a>

            @if(auth()->user()?->isDono())
            <button @click="abrirNovo()"
                    class="bg-purple-600 hover:bg-purple-700 text-white text-sm font-semibold px-4 py-2.5 rounded-xl shadow-sm hover:shadow transition-all flex items-center gap-2">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
                </svg>
                Novo Agente IA
            </button>
            @endif
        </div>
    </div>

    @if(session('sucesso'))
        <div class="p-4 bg-emerald-50 border border-emerald-200 text-emerald-800 rounded-2xl text-sm flex items-center gap-2 shadow-sm">
            <span>✅</span> {{ session('sucesso') }}
        </div>
    @endif

    {{-- Grid de Agentes IA --}}
    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-5">
        @forelse($agentes as $agente)
        <div class="bg-white rounded-3xl border border-gray-200/80 shadow-sm hover:shadow-lg hover:-translate-y-1 transition-all duration-300 flex flex-col justify-between overflow-hidden group">
            <div class="p-6 space-y-4">
                {{-- Cabeçalho do Card --}}
                <div class="flex items-start justify-between gap-3">
                    <div class="flex items-center gap-3.5">
                        @if($agente->avatar_url)
                            <img src="{{ $agente->avatar_url }}" class="w-14 h-14 rounded-2xl object-cover ring-2 ring-purple-100 flex-shrink-0 shadow-sm" alt="">
                        @else
                            <div class="w-14 h-14 rounded-2xl bg-gradient-to-tr from-purple-600 to-indigo-600 text-white flex items-center justify-center text-xl font-bold flex-shrink-0 shadow-sm shadow-purple-200">
                                {{ mb_substr($agente->nome, 0, 1) }}
                            </div>
                        @endif
                        <div class="min-w-0">
                            <h3 class="font-bold text-gray-900 text-base truncate group-hover:text-purple-600 transition-colors">{{ $agente->nome }}</h3>
                            <p class="text-xs text-gray-400 truncate">{{ $agente->email }}</p>

                            <div class="flex flex-wrap gap-1 mt-1.5">
                                @if($agente->provedor_ia === 'gemini_direto')
                                    <span class="inline-flex items-center gap-1 text-[10px] font-bold text-purple-800 bg-purple-100/70 px-2 py-0.5 rounded-md border border-purple-200/60">
                                        <span>✨</span> Gemini ({{ str_replace('gemini-', '', $agente->gemini_modelo ?: '1.5-pro') }})
                                    </span>
                                @else
                                    <span class="inline-flex items-center gap-1 text-[10px] font-bold text-blue-800 bg-blue-100/70 px-2 py-0.5 rounded-md border border-blue-200/60" title="Modelo OpenRouter ativo">
                                        <span>🟣</span> {{ explode('/', $agente->openrouter_modelo ?: 'gpt-4o-mini')[1] ?? $agente->openrouter_modelo }}
                                    </span>
                                @endif

                                @if($agente->gemini_email)
                                    <span class="inline-flex items-center gap-1 text-[10px] text-gray-600 bg-gray-100 px-1.5 py-0.5 rounded">
                                        {{ $agente->gemini_email }}
                                    </span>
                                @endif
                            </div>
                        </div>
                    </div>

                    @if(auth()->user()?->isDono())
                    <button @click="editar({{ json_encode($agente) }}, {{ json_encode($agente->cargos->pluck('id')) }})"
                            class="text-gray-400 hover:text-purple-600 p-1.5 rounded-xl hover:bg-gray-50 transition"
                            title="Editar Agente IA">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z"/>
                        </svg>
                    </button>
                    @endif
                </div>

                {{-- Funções Atribuídas --}}
                <div>
                    <label class="block text-[10px] font-bold uppercase tracking-wider text-gray-400 mb-2">
                        Funções sob Responsabilidade:
                    </label>
                    <div class="flex flex-wrap gap-1.5 min-h-[32px]">
                        @forelse($agente->cargos as $c)
                            <span class="inline-flex items-center gap-1.5 text-xs bg-indigo-50 text-indigo-700 font-medium px-2.5 py-1 rounded-xl border border-indigo-100/80">
                                <span>{{ $c->icone ?: '💼' }}</span> {{ $c->nome }}
                            </span>
                        @empty
                            <span class="text-xs text-gray-400 italic">Nenhuma função associada</span>
                        @endforelse
                    </div>
                </div>
            </div>

            {{-- Rodapé do Card com Consumo de Tokens --}}
            <div class="px-6 py-3.5 bg-gray-50/70 border-t border-gray-100 flex flex-wrap items-center justify-between gap-2 text-xs">
                <div class="flex items-center gap-2.5">
                    <span class="inline-flex items-center gap-1.5 text-emerald-700 bg-emerald-50 px-2 py-0.5 rounded-md font-medium text-[11px] border border-emerald-100">
                        <span class="w-1.5 h-1.5 rounded-full bg-emerald-500 animate-pulse"></span>
                        Ativo
                    </span>

                    <span class="inline-flex items-center gap-1 text-[11px] font-semibold text-purple-900 bg-purple-50 px-2 py-0.5 rounded-md border border-purple-200/70" title="Consumo acumulado de tokens deste agente">
                        <span>⚡</span> {{ number_format($agente->total_tokens ?? 0, 0, ',', '.') }} tokens
                    </span>
                </div>

                <button @click="verDetalhes({{ json_encode($agente) }})"
                        class="text-xs font-semibold text-purple-600 hover:text-purple-800 flex items-center gap-1 group-hover:translate-x-0.5 transition-transform">
                    Ver Detalhes <span>&rarr;</span>
                </button>
            </div>
        </div>
        @empty
        <div class="col-span-full py-12 text-center bg-white rounded-3xl border border-gray-200/80">
            <span class="text-4xl">🤖</span>
            <h3 class="text-base font-bold text-gray-800 mt-2">Nenhum Agente IA cadastrado</h3>
            <p class="text-xs text-gray-500 mt-1">Clique no botão "Novo Agente IA" para cadastrar seu primeiro agente virtual.</p>
        </div>
        @endforelse
    </div>

    {{-- Modal de Visualização de Detalhes do Agente IA --}}
    <div x-show="modalDetalhes" x-transition.opacity class="fixed inset-0 z-50 flex items-center justify-center bg-black/70 backdrop-blur-xs p-3 sm:p-4 overflow-y-auto">
        <div @click.outside="modalDetalhes = false" class="bg-white rounded-3xl shadow-2xl w-full max-w-2xl flex flex-col my-auto max-h-[90vh] overflow-hidden border border-gray-100">
            <div class="p-5 sm:p-6 border-b border-gray-100 flex items-start justify-between bg-gradient-to-r from-purple-50/70 via-white to-indigo-50/70 flex-shrink-0">
                <div class="flex items-center gap-3.5">
                    <template x-if="agenteSelecionado.avatar_url">
                        <img :src="agenteSelecionado.avatar_url" class="w-14 h-14 rounded-2xl object-cover ring-2 ring-purple-200" alt="">
                    </template>
                    <template x-if="!agenteSelecionado.avatar_url">
                        <div class="w-14 h-14 rounded-2xl bg-gradient-to-tr from-purple-600 to-indigo-600 text-white flex items-center justify-center text-xl font-bold shadow-sm shadow-purple-200">
                            <span x-text="(agenteSelecionado.nome || 'A').substr(0,1)"></span>
                        </div>
                    </template>
                    <div>
                        <h2 class="text-xl font-bold text-gray-900" x-text="agenteSelecionado.nome"></h2>
                        <p class="text-xs text-gray-500" x-text="agenteSelecionado.email"></p>
                    </div>
                </div>
                <button @click="modalDetalhes = false" class="text-gray-400 hover:text-gray-600 text-xl font-medium p-1">✕</button>
            </div>

            <div class="p-6 space-y-4 text-sm overflow-y-auto flex-1 overscroll-contain" style="max-height: calc(90vh - 140px);">
                {{-- Funções --}}
                <div>
                    <h3 class="text-xs font-bold text-gray-400 uppercase tracking-wider mb-2">Funções Atribuídas:</h3>
                    <div class="flex flex-wrap gap-1.5">
                        <template x-for="c in (agenteSelecionado.cargos || [])" :key="c.id">
                            <span class="inline-flex items-center gap-1.5 text-xs bg-indigo-50 text-indigo-700 font-medium px-3 py-1 rounded-xl border border-indigo-100"
                                  x-text="(c.icone || '💼') + ' ' + c.nome"></span>
                        </template>
                    </div>
                </div>

                {{-- Conexão / Motor de IA --}}
                <div class="bg-purple-50/70 border border-purple-100 p-4 rounded-2xl space-y-1.5">
                    <h3 class="text-xs font-bold text-purple-900 flex items-center gap-1.5">
                        <span>✨</span> Motor de Inteligência Artificial Ativo:
                    </h3>
                    <p class="text-xs font-semibold text-purple-800"
                       x-text="agenteSelecionado.provedor_ia === 'gemini_direto' ? ('Google Gemini Pro (Direto) — ' + (agenteSelecionado.gemini_modelo || 'gemini-1.5-pro')) : ('OpenRouter — ' + (agenteSelecionado.openrouter_modelo || 'openai/gpt-4o-mini'))"></p>
                    <p class="text-[11px] text-purple-700" x-text="agenteSelecionado.gemini_email ? 'Conta Google: ' + agenteSelecionado.gemini_email : 'Conta vinculada ao sistema'"></p>
                </div>

                {{-- Consumo de Tokens --}}
                <div class="bg-gray-50 p-4 rounded-2xl border border-gray-100 flex items-center justify-between">
                    <div>
                        <span class="text-xs font-bold text-gray-700">Consumo Acumulado:</span>
                        <p class="text-[11px] text-gray-500">Total de tokens consumidos em requisições</p>
                    </div>
                    <span class="text-sm font-bold text-purple-700 bg-white px-3 py-1 rounded-xl border border-purple-100 shadow-2xs"
                          x-text="(agenteSelecionado.total_tokens ? Number(agenteSelecionado.total_tokens).toLocaleString('pt-BR') : '0') + ' tokens'"></span>
                </div>

                {{-- Base de Conhecimento & Aprendizado --}}
                <template x-if="agenteSelecionado.base_conhecimento">
                    <div class="bg-amber-50/70 border border-amber-200 p-4 rounded-2xl space-y-2">
                        <h3 class="text-xs font-bold text-amber-900 flex items-center gap-1.5">
                            <span>📚</span> Base de Conhecimento & Aprendizado Contínuo da Empresa:
                        </h3>
                        <div class="text-amber-950 bg-white/90 p-4 rounded-xl border border-amber-200/80 text-xs leading-relaxed whitespace-pre-line font-mono text-[11px]"
                             x-text="agenteSelecionado.base_conhecimento"></div>
                    </div>
                </template>

                {{-- Instruções do Modelo --}}
                <template x-if="agenteSelecionado.gemini_instrucoes">
                    <div>
                        <h3 class="text-xs font-bold text-gray-400 uppercase tracking-wider mb-1.5">Diretrizes & Instruções Operacionais:</h3>
                        <div class="text-gray-700 bg-gray-50 p-4 rounded-2xl border border-gray-100 text-xs leading-relaxed whitespace-pre-line"
                             x-text="agenteSelecionado.gemini_instrucoes"></div>
                    </div>
                </template>
            </div>

            <div class="p-4 border-t border-gray-100 bg-gray-50 flex justify-end flex-shrink-0">
                <button @click="modalDetalhes = false" class="bg-gray-200 hover:bg-gray-300 text-gray-700 text-xs font-semibold px-5 py-2.5 rounded-xl transition">
                    Fechar
                </button>
            </div>
        </div>
    </div>

    {{-- Modal de Criar / Editar Agente IA (Apenas Super Admin / Dono) --}}
    @if(auth()->user()?->isDono())
    <div x-show="modalForm" x-transition.opacity class="fixed inset-0 z-50 flex items-center justify-center bg-black/70 backdrop-blur-xs p-2 sm:p-4 overflow-y-auto">
        <div @click.outside="modalForm = false" class="bg-white rounded-3xl shadow-2xl w-full max-w-4xl flex flex-col my-auto max-h-[92vh] overflow-hidden border border-gray-100 relative">
            
            {{-- Header Fixo do Modal --}}
            <div class="p-5 sm:p-6 border-b border-gray-100 flex items-center justify-between bg-gradient-to-r from-purple-50/70 via-white to-indigo-50/70 flex-shrink-0">
                <div class="flex items-center gap-2.5">
                    <span class="p-2 bg-purple-100 text-purple-700 rounded-xl text-lg">🤖</span>
                    <div>
                        <h2 class="text-xl font-bold text-gray-900" x-text="editandoId ? 'Editar Agente de IA' : 'Novo Agente de IA'"></h2>
                        <p class="text-xs text-gray-500">Configure o motor de inteligência, base de conhecimento e parâmetros operacionais.</p>
                    </div>
                </div>
                <button @click="modalForm = false" class="text-gray-400 hover:text-gray-600 text-xl font-medium p-1">✕</button>
            </div>

            {{-- Formulário com Corpo Rolável e Rodapé Fixo --}}
            <form :action="editandoId ? '/equipe/agentes-ia/' + editandoId : '{{ route('equipe.agentes-ia.store') }}'" method="POST" class="flex flex-col flex-1 min-h-0 overflow-hidden">
                @csrf
                <template x-if="editandoId">
                    <input type="hidden" name="_method" value="PUT">
                </template>

                {{-- Corpo do Formulário com Barra de Rolagem Suave --}}
                <div class="p-5 sm:p-6 space-y-4 overflow-y-auto flex-1 min-h-0 overscroll-contain" style="max-height: calc(92vh - 150px);">
                    
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3.5">
                        <div class="space-y-1">
                            <label class="text-xs font-bold text-gray-800">Nome do Agente *</label>
                            <input type="text" name="nome" x-model="form.nome" required placeholder="Ex: Adriana Aviag, Nathanel ou Gabriel" class="w-full text-xs border border-gray-300 rounded-xl p-2.5 focus:ring-2 focus:ring-purple-500">
                        </div>
                        <div class="space-y-1">
                            <label class="text-xs font-bold text-gray-800">E-mail Principal *</label>
                            <input type="email" name="email" x-model="form.email" required placeholder="Ex: nathanelllfernandees@gmail.com" class="w-full text-xs border border-gray-300 rounded-xl p-2.5 focus:ring-2 focus:ring-purple-500">
                        </div>
                    </div>

                    {{-- SELETOR DE MOTOR / PROVEDOR DE IA --}}
                    <div class="space-y-2 pt-1 border-t border-gray-100">
                        <label class="text-xs font-bold text-gray-800 flex items-center gap-1.5">
                            <span>⚡</span> Escolha o Motor / Provedor de IA deste Agente:
                        </label>
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                            <label class="flex items-start gap-3 p-3 rounded-2xl border transition-all cursor-pointer select-none"
                                   :class="form.provedor_ia === 'openrouter' ? 'bg-blue-50/80 border-blue-400 ring-2 ring-blue-200' : 'bg-white border-gray-200 hover:border-gray-300'">
                                <input type="radio" name="provedor_ia" value="openrouter" x-model="form.provedor_ia" class="mt-0.5 text-blue-600 focus:ring-blue-500">
                                <div>
                                    <h4 class="text-xs font-bold text-gray-900">🟣 OpenRouter (Multi-model)</h4>
                                    <p class="text-[11px] text-gray-500 mt-0.5 leading-relaxed">
                                        Hub com GPT-4o, Claude 3.5, Llama 3.3, DeepSeek com seleção por dificuldade & custo.
                                    </p>
                                </div>
                            </label>

                            <label class="flex items-start gap-3 p-3 rounded-2xl border transition-all cursor-pointer select-none"
                                   :class="form.provedor_ia === 'gemini_direto' ? 'bg-purple-50/80 border-purple-400 ring-2 ring-purple-200' : 'bg-white border-gray-200 hover:border-gray-300'">
                                <input type="radio" name="provedor_ia" value="gemini_direto" x-model="form.provedor_ia" class="mt-0.5 text-purple-600 focus:ring-purple-500">
                                <div>
                                    <h4 class="text-xs font-bold text-gray-900">✨ Google Gemini Pro (Direto)</h4>
                                    <p class="text-[11px] text-gray-500 mt-0.5 leading-relaxed">
                                        Conexão direta na conta Google / API do Gemini Pro sem passar pelo OpenRouter.
                                    </p>
                                </div>
                            </label>
                        </div>
                    </div>

                    {{-- SELETOR DE MODELOS OPENROUTER COM ETIQUETAS DE DIFICULDADE E SUGESTÕES DE CUSTO --}}
                    <div x-show="form.provedor_ia === 'openrouter'" class="p-4 bg-blue-50/70 border border-blue-200 rounded-2xl space-y-3">
                        <div class="flex items-center justify-between">
                            <label class="text-xs font-bold text-blue-950 flex items-center gap-1.5">
                                <span>🟣</span> Selecione o Modelo Principal OpenRouter deste Agente:
                            </label>
                            <span class="text-[10px] font-semibold text-blue-700 bg-blue-100/80 px-2 py-0.5 rounded-md">
                                Multi-Model Hub
                            </span>
                        </div>
                        <p class="text-[11px] text-blue-800 leading-relaxed">
                            Escolha a IA adequada para a complexidade desta função. Modelos gratuitos ou de baixo custo são ideais para triagem e mensagens rápidas, enquanto modelos avançados são recomendados para orquestração geral e copywriting estratégico.
                        </p>

                        {{-- 1. Baixa Dificuldade (Gratuitas e Ultra Econômicas) --}}
                        <div class="space-y-1.5 pt-1">
                            <div class="flex items-center justify-between text-[10px] font-bold text-emerald-800 uppercase tracking-wider">
                                <span>🟢 Baixa Dificuldade (Triagem, Confirmações e Mensagens Rápidas)</span>
                                <span class="text-emerald-700 font-semibold lowercase">menor custo ($0 a $0,08/1M)</span>
                            </div>
                            <div class="grid grid-cols-1 sm:grid-cols-3 gap-2">
                                <!-- Llama 3.3 Free -->
                                <label class="p-2.5 rounded-xl border transition-all cursor-pointer text-xs select-none relative"
                                       :class="form.openrouter_modelo === 'meta-llama/llama-3.3-70b-instruct:free' ? 'bg-emerald-50 border-emerald-400 ring-2 ring-emerald-200' : 'bg-white border-gray-200 hover:border-gray-300'">
                                    <div class="flex items-center justify-between mb-1">
                                        <input type="radio" name="openrouter_modelo" value="meta-llama/llama-3.3-70b-instruct:free" x-model="form.openrouter_modelo" class="text-emerald-600 focus:ring-emerald-500">
                                        <span class="text-[9px] font-bold uppercase px-1.5 py-0.5 rounded bg-emerald-100 text-emerald-800">Grátis</span>
                                    </div>
                                    <div class="font-bold text-gray-900 text-xs truncate">Llama 3.3 70B</div>
                                    <p class="text-[10px] text-gray-500 mt-0.5 line-clamp-2">Triagem rápida e respostas preliminares a custo zero.</p>
                                </label>

                                <!-- Gemini 2.0 Flash Free -->
                                <label class="p-2.5 rounded-xl border transition-all cursor-pointer text-xs select-none relative"
                                       :class="form.openrouter_modelo === 'google/gemini-2.0-flash-exp:free' ? 'bg-emerald-50 border-emerald-400 ring-2 ring-emerald-200' : 'bg-white border-gray-200 hover:border-gray-300'">
                                    <div class="flex items-center justify-between mb-1">
                                        <input type="radio" name="openrouter_modelo" value="google/gemini-2.0-flash-exp:free" x-model="form.openrouter_modelo" class="text-emerald-600 focus:ring-emerald-500">
                                        <span class="text-[9px] font-bold uppercase px-1.5 py-0.5 rounded bg-emerald-100 text-emerald-800">Grátis</span>
                                    </div>
                                    <div class="font-bold text-gray-900 text-xs truncate">Gemini 2.0 Flash</div>
                                    <p class="text-[10px] text-gray-500 mt-0.5 line-clamp-2">Velocidade instantânea para saudações e triagem.</p>
                                </label>

                                <!-- Gemini 1.5 Flash -->
                                <label class="p-2.5 rounded-xl border transition-all cursor-pointer text-xs select-none relative"
                                       :class="form.openrouter_modelo === 'google/gemini-flash-1.5' ? 'bg-emerald-50 border-emerald-400 ring-2 ring-emerald-200' : 'bg-white border-gray-200 hover:border-gray-300'">
                                    <div class="flex items-center justify-between mb-1">
                                        <input type="radio" name="openrouter_modelo" value="google/gemini-flash-1.5" x-model="form.openrouter_modelo" class="text-emerald-600 focus:ring-emerald-500">
                                        <span class="text-[9px] font-bold uppercase px-1.5 py-0.5 rounded bg-emerald-100 text-emerald-800">~$0.075/1M</span>
                                    </div>
                                    <div class="font-bold text-gray-900 text-xs truncate">Gemini 1.5 Flash</div>
                                    <p class="text-[10px] text-gray-500 mt-0.5 line-clamp-2">Ultra econômico e estável para alto volume.</p>
                                </label>
                            </div>
                        </div>

                        {{-- 2. Média Dificuldade (Melhor ROI para Suporte e Vendas) --}}
                        <div class="space-y-1.5 pt-2 border-t border-blue-200/60">
                            <div class="flex items-center justify-between text-[10px] font-bold text-amber-800 uppercase tracking-wider">
                                <span>🟡 Média Dificuldade (Suporte, Atendimento Comercial e Qualificação)</span>
                                <span class="text-amber-700 font-semibold lowercase">ótimo ROI (~$0,14 a $0,25/1M)</span>
                            </div>
                            <div class="grid grid-cols-1 sm:grid-cols-3 gap-2">
                                <!-- GPT-4o Mini -->
                                <label class="p-2.5 rounded-xl border transition-all cursor-pointer text-xs select-none relative"
                                       :class="form.openrouter_modelo === 'openai/gpt-4o-mini' ? 'bg-amber-50 border-amber-400 ring-2 ring-amber-200' : 'bg-white border-gray-200 hover:border-gray-300'">
                                    <div class="flex items-center justify-between mb-1">
                                        <input type="radio" name="openrouter_modelo" value="openai/gpt-4o-mini" x-model="form.openrouter_modelo" class="text-amber-600 focus:ring-amber-500">
                                        <span class="text-[9px] font-bold uppercase px-1.5 py-0.5 rounded bg-amber-100 text-amber-900">⭐ Recomendado</span>
                                    </div>
                                    <div class="font-bold text-gray-900 text-xs truncate">GPT-4o Mini</div>
                                    <p class="text-[10px] text-gray-500 mt-0.5 line-clamp-2">Melhor equilíbrio de inteligência e negociação para Suporte e Vendas.</p>
                                </label>

                                <!-- DeepSeek V3 Chat -->
                                <label class="p-2.5 rounded-xl border transition-all cursor-pointer text-xs select-none relative"
                                       :class="form.openrouter_modelo === 'deepseek/deepseek-chat' ? 'bg-amber-50 border-amber-400 ring-2 ring-amber-200' : 'bg-white border-gray-200 hover:border-gray-300'">
                                    <div class="flex items-center justify-between mb-1">
                                        <input type="radio" name="openrouter_modelo" value="deepseek/deepseek-chat" x-model="form.openrouter_modelo" class="text-amber-600 focus:ring-amber-500">
                                        <span class="text-[9px] font-bold uppercase px-1.5 py-0.5 rounded bg-amber-100 text-amber-900">~$0.14/1M</span>
                                    </div>
                                    <div class="font-bold text-gray-900 text-xs truncate">DeepSeek V3 Chat</div>
                                    <p class="text-[10px] text-gray-500 mt-0.5 line-clamp-2">Fluidez conversacional e persuasão a custo 90% menor.</p>
                                </label>

                                <!-- Claude 3 Haiku -->
                                <label class="p-2.5 rounded-xl border transition-all cursor-pointer text-xs select-none relative"
                                       :class="form.openrouter_modelo === 'anthropic/claude-3-haiku' ? 'bg-amber-50 border-amber-400 ring-2 ring-amber-200' : 'bg-white border-gray-200 hover:border-gray-300'">
                                    <div class="flex items-center justify-between mb-1">
                                        <input type="radio" name="openrouter_modelo" value="anthropic/claude-3-haiku" x-model="form.openrouter_modelo" class="text-amber-600 focus:ring-amber-500">
                                        <span class="text-[9px] font-bold uppercase px-1.5 py-0.5 rounded bg-amber-100 text-amber-900">~$0.25/1M</span>
                                    </div>
                                    <div class="font-bold text-gray-900 text-xs truncate">Claude 3 Haiku</div>
                                    <p class="text-[10px] text-gray-500 mt-0.5 line-clamp-2">Tom empático e altamente humanizado para retenção.</p>
                                </label>
                            </div>
                        </div>

                        {{-- 3. Alta Dificuldade (Orquestrador e Análise Estratégica) --}}
                        <div class="space-y-1.5 pt-2 border-t border-blue-200/60">
                            <div class="flex items-center justify-between text-[10px] font-bold text-purple-800 uppercase tracking-wider">
                                <span>🔴 Alta Dificuldade (Orquestrador Geral, Decisões Críticas e Raciocínio)</span>
                                <span class="text-purple-700 font-semibold lowercase">alta inteligência (~$0,55 a $3,00/1M)</span>
                            </div>
                            <div class="grid grid-cols-1 sm:grid-cols-3 gap-2">
                                <!-- Claude 3.5 Sonnet -->
                                <label class="p-2.5 rounded-xl border transition-all cursor-pointer text-xs select-none relative"
                                       :class="form.openrouter_modelo === 'anthropic/claude-3.5-sonnet' ? 'bg-purple-50 border-purple-400 ring-2 ring-purple-200' : 'bg-white border-gray-200 hover:border-gray-300'">
                                    <div class="flex items-center justify-between mb-1">
                                        <input type="radio" name="openrouter_modelo" value="anthropic/claude-3.5-sonnet" x-model="form.openrouter_modelo" class="text-purple-600 focus:ring-purple-500">
                                        <span class="text-[9px] font-bold uppercase px-1.5 py-0.5 rounded bg-purple-100 text-purple-800">Top Tier</span>
                                    </div>
                                    <div class="font-bold text-gray-900 text-xs truncate">Claude 3.5 Sonnet</div>
                                    <p class="text-[10px] text-gray-500 mt-0.5 line-clamp-2">Líder em precisão e análise crítica para o Orquestrador Geral.</p>
                                </label>

                                <!-- DeepSeek R1 -->
                                <label class="p-2.5 rounded-xl border transition-all cursor-pointer text-xs select-none relative"
                                       :class="form.openrouter_modelo === 'deepseek/deepseek-r1' ? 'bg-purple-50 border-purple-400 ring-2 ring-purple-200' : 'bg-white border-gray-200 hover:border-gray-300'">
                                    <div class="flex items-center justify-between mb-1">
                                        <input type="radio" name="openrouter_modelo" value="deepseek/deepseek-r1" x-model="form.openrouter_modelo" class="text-purple-600 focus:ring-purple-500">
                                        <span class="text-[9px] font-bold uppercase px-1.5 py-0.5 rounded bg-purple-100 text-purple-800">~$0.55/1M</span>
                                    </div>
                                    <div class="font-bold text-gray-900 text-xs truncate">DeepSeek R1</div>
                                    <p class="text-[10px] text-gray-500 mt-0.5 line-clamp-2">Raciocínio analítico avançado com 80% de economia.</p>
                                </label>

                                <!-- GPT-4o -->
                                <label class="p-2.5 rounded-xl border transition-all cursor-pointer text-xs select-none relative"
                                       :class="form.openrouter_modelo === 'openai/gpt-4o' ? 'bg-purple-50 border-purple-400 ring-2 ring-purple-200' : 'bg-white border-gray-200 hover:border-gray-300'">
                                        Conexão nativa com a conta Google do usuário via Gemini API Key ou OAuth corporativo.
                                    </p>
                                </div>
                            </label>
                        </div>
                    </div>

                    {{-- BLOCO OPENROUTER: SELEÇÃO DE MODELOS COM ETIQUETAS DE DIFICULDADE E CUSTO --}}
                    <div x-show="form.provedor_ia === 'openrouter'" x-transition class="space-y-4 p-4 bg-blue-50/60 border border-blue-200/80 rounded-2xl">
                        <div>
                            <div class="flex items-center justify-between">
                                <h4 class="text-xs font-bold text-blue-950 flex items-center gap-1.5">
                                    <span>🤖</span> Escolha o Modelo OpenRouter para este Agente:
                                </h4>
                                <span class="text-[10px] font-semibold text-blue-800 bg-blue-100 px-2 py-0.5 rounded-md">
                                    Selecione 1 Modelo Principal
                                </span>
                            </div>
                            <p class="text-[11px] text-blue-800 mt-0.5">
                                As funções foram categorizadas por nível de complexidade para ajudar na escolha do menor custo mensal.
                            </p>
                        </div>

                        {{-- Categoria 1: Baixa Dificuldade --}}
                        <div class="space-y-2 bg-white/80 p-3 rounded-xl border border-emerald-200">
                            <div class="flex items-center justify-between">
                                <span class="text-[11px] font-bold text-emerald-800 flex items-center gap-1">
                                    <span class="w-2 h-2 rounded-full bg-emerald-500"></span>
                                    BAIXA DIFICULDADE (Triagem, Confirmações e Mensagens Rápidas)
                                </span>
                                <span class="text-[10px] font-semibold text-emerald-700 bg-emerald-50 px-2 py-0.5 rounded border border-emerald-200">
                                    Menor Custo ($0 a $0.08/1M)
                                </span>
                            </div>
                            <div class="grid grid-cols-1 sm:grid-cols-3 gap-2">
                                @foreach($openrouterModelos['baixo'] as $m)
                                <label class="p-2.5 rounded-xl border transition-all cursor-pointer flex flex-col justify-between text-left relative"
                                       :class="form.openrouter_modelo === '{{ $m['id'] }}' ? 'bg-emerald-50/90 border-emerald-500 ring-2 ring-emerald-200' : 'bg-white border-gray-200 hover:border-emerald-300'">
                                    <div class="flex items-start justify-between gap-1">
                                        <div class="font-bold text-xs text-gray-900 leading-tight">{{ $m['nome'] }}</div>
                                        <span class="text-[9px] font-bold px-1.5 py-0.5 rounded bg-emerald-100 text-emerald-800 flex-shrink-0">{{ $m['badge'] }}</span>
                                    </div>
                                    <p class="text-[10px] text-gray-500 mt-1 leading-snug">{{ $m['desc'] }}</p>
                                    <div class="mt-2 pt-1 border-t border-gray-100 flex items-center justify-between text-[10px]">
                                        <span class="text-gray-400">{{ $m['empresa'] }}</span>
                                        <input type="radio" name="openrouter_modelo" value="{{ $m['id'] }}" x-model="form.openrouter_modelo" class="text-emerald-600 focus:ring-emerald-500">
                                    </div>
                                </label>
                                @endforeach
                            </div>
                        </div>

                        {{-- Categoria 2: Média Dificuldade --}}
                        <div class="space-y-2 bg-white/80 p-3 rounded-xl border border-amber-200">
                            <div class="flex items-center justify-between">
                                <span class="text-[11px] font-bold text-amber-800 flex items-center gap-1">
                                    <span class="w-2 h-2 rounded-full bg-amber-500"></span>
                                    MÉDIA DIFICULDADE (Suporte, Atendimento Comercial e Qualificação)
                                </span>
                                <span class="text-[10px] font-semibold text-amber-700 bg-amber-50 px-2 py-0.5 rounded border border-amber-200">
                                    Ótimo ROI (~$0.14 a $0.25/1M)
                                </span>
                            </div>
                            <div class="grid grid-cols-1 sm:grid-cols-3 gap-2">
                                @foreach($openrouterModelos['medio'] as $m)
                                <label class="p-2.5 rounded-xl border transition-all cursor-pointer flex flex-col justify-between text-left relative"
                                       :class="form.openrouter_modelo === '{{ $m['id'] }}' ? 'bg-amber-50/90 border-amber-500 ring-2 ring-amber-200' : 'bg-white border-gray-200 hover:border-amber-300'">
                                    <div class="flex items-start justify-between gap-1">
                                        <div class="font-bold text-xs text-gray-900 leading-tight">{{ $m['nome'] }}</div>
                                        <span class="text-[9px] font-bold px-1.5 py-0.5 rounded {{ str_contains($m['badge'], 'Recomendado') ? 'bg-amber-200 text-amber-900' : 'bg-amber-100 text-amber-800' }} flex-shrink-0">{{ $m['badge'] }}</span>
                                    </div>
                                    <p class="text-[10px] text-gray-500 mt-1 leading-snug">{{ $m['desc'] }}</p>
                                    <div class="mt-2 pt-1 border-t border-gray-100 flex items-center justify-between text-[10px]">
                                        <span class="text-gray-400">{{ $m['empresa'] }}</span>
                                        <input type="radio" name="openrouter_modelo" value="{{ $m['id'] }}" x-model="form.openrouter_modelo" class="text-amber-600 focus:ring-amber-500">
                                    </div>
                                </label>
                                @endforeach
                            </div>
                        </div>

                        {{-- Categoria 3: Alta Dificuldade --}}
                        <div class="space-y-2 bg-white/80 p-3 rounded-xl border border-rose-200">
                            <div class="flex items-center justify-between">
                                <span class="text-[11px] font-bold text-rose-800 flex items-center gap-1">
                                    <span class="w-2 h-2 rounded-full bg-rose-500"></span>
                                    ALTA DIFICULDADE (Orquestrador Geral, Decisões Críticas e Raciocínio)
                                </span>
                                <span class="text-[10px] font-semibold text-rose-700 bg-rose-50 px-2 py-0.5 rounded border border-rose-200">
                                    Alta Inteligência (~$0.55 a $3.00/1M)
                                </span>
                            </div>
                            <div class="grid grid-cols-1 sm:grid-cols-3 gap-2">
                                @foreach($openrouterModelos['alto'] as $m)
                                <label class="p-2.5 rounded-xl border transition-all cursor-pointer flex flex-col justify-between text-left relative"
                                       :class="form.openrouter_modelo === '{{ $m['id'] }}' ? 'bg-rose-50/90 border-rose-500 ring-2 ring-rose-200' : 'bg-white border-gray-200 hover:border-rose-300'">
                                    <div class="flex items-start justify-between gap-1">
                                        <div class="font-bold text-xs text-gray-900 leading-tight">{{ $m['nome'] }}</div>
                                        <span class="text-[9px] font-bold px-1.5 py-0.5 rounded bg-rose-100 text-rose-800 flex-shrink-0">{{ $m['badge'] }}</span>
                                    </div>
                                    <p class="text-[10px] text-gray-500 mt-1 leading-snug">{{ $m['desc'] }}</p>
                                    <div class="mt-2 pt-1 border-t border-gray-100 flex items-center justify-between text-[10px]">
                                        <span class="text-gray-400">{{ $m['empresa'] }}</span>
                                        <input type="radio" name="openrouter_modelo" value="{{ $m['id'] }}" x-model="form.openrouter_modelo" class="text-rose-600 focus:ring-rose-500">
                                    </div>
                                </label>
                                @endforeach
                            </div>
                        </div>
                    </div>

                    {{-- BLOCO GOOGLE GEMINI PRO DIRETO --}}
                    <div x-show="form.provedor_ia === 'gemini_direto'" x-transition class="space-y-3 p-4 bg-purple-50/60 border border-purple-200/80 rounded-2xl">
                        <div class="flex items-center justify-between">
                            <h4 class="text-xs font-bold text-purple-950 flex items-center gap-1.5">
                                <span>✨</span> Credenciais & Modelo Google Gemini Pro
                            </h4>
                            <span class="text-[10px] font-semibold text-purple-800 bg-purple-100 px-2 py-0.5 rounded-md">
                                Conexão Direta Google AI
                            </span>
                        </div>

                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                            <div class="space-y-1">
                                <label class="text-xs font-medium text-purple-800">E-mail da Conta Google (Gemini Pro)</label>
                                <input type="email" name="gemini_email" x-model="form.gemini_email" placeholder="Ex: nathanelllfernandees@gmail.com" class="w-full text-xs border border-purple-300 bg-white rounded-xl p-2.5 focus:ring-2 focus:ring-purple-500">
                            </div>
                            <div class="space-y-1">
                                <label class="text-xs font-medium text-purple-800">Modelo Gemini</label>
                                <select name="gemini_modelo" x-model="form.gemini_modelo" class="w-full text-xs border border-purple-300 bg-white rounded-xl p-2.5 focus:ring-2 focus:ring-purple-500">
                                    <option value="gemini-1.5-pro">Gemini 1.5 Pro (Raciocínio & Janela de 2M tokens)</option>
                                    <option value="gemini-2.0-flash">Gemini 2.0 Flash (Velocidade & Resposta Instantânea)</option>
                                    <option value="gemini-1.5-flash">Gemini 1.5 Flash (Leve & Econômico)</option>
                                </select>
                            </div>
                        </div>
                        <div class="space-y-1">
                            <label class="text-xs font-medium text-purple-800">Chave de API do Google AI Studio (Opcional se usar chave global)</label>
                            <input type="password" name="gemini_api_key" x-model="form.gemini_api_key" placeholder="AIzaSy..." class="w-full text-xs border border-purple-300 bg-white rounded-xl p-2.5 focus:ring-2 focus:ring-purple-500">
                        </div>
                    </div>

                    {{-- BASE DE CONHECIMENTO & APRENDIZADO CONTÍNUO DA EMPRESA --}}
                    <div class="space-y-2 p-4 bg-amber-50/70 border border-amber-200/80 rounded-2xl">
                        <div class="flex items-center justify-between">
                            <label class="text-xs font-bold text-amber-950 flex items-center gap-1.5">
                                <span>📚</span> Base de Conhecimento & Aprendizado Contínuo da Empresa
                            </label>
                            <span class="text-[10px] font-semibold text-amber-800 bg-amber-100/80 px-2 py-0.5 rounded-md">
                                Injetado no Atendimento
                            </span>
                        </div>
                        <p class="text-[11px] text-amber-800 leading-relaxed">
                            Insira todas as informações, produtos, regras do negócio, formas de pagamento, restrições e FAQs que a empresa quiser fornecer para instruir este agente. É neste campo que a IA atualiza e refina seu conhecimento à medida que tira dúvidas e acompanha os atendimentos no Kanban.
                        </p>
                        <textarea name="base_conhecimento" x-model="form.base_conhecimento" rows="8"
                                  placeholder="Ex:
- Horário de atendimento: Segunda a Sábado das 08h às 19h.
- Região atendida: Rio de Janeiro e Grande Rio.
- Formas de pagamento: PIX, Cartão em até 12x, Boleto para empresas.
- Serviços principais: Fretes rápidos, mudanças residenciais e comerciais com montagem.
- Instruções aprendidas: Se o cliente perguntar sobre seguro, informar que temos apólice inclusa..."
                                  class="w-full text-xs font-mono border border-amber-300 bg-white rounded-xl p-3 focus:ring-2 focus:ring-amber-500 leading-relaxed min-h-[180px] resize-y"></textarea>
                    </div>

                    {{-- FOTO / AVATAR DO AGENTE COM UPLOAD E DIMENSÕES --}}
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3.5">
                        <div class="space-y-1">
                            <label class="text-xs font-bold text-gray-800">WhatsApp de Notificação / Recuperação</label>
                            <input type="text" name="whatsapp" x-model="form.whatsapp" placeholder="Ex: 21984503924" class="w-full text-xs border border-gray-300 rounded-xl p-2.5 focus:ring-2 focus:ring-purple-500">
                        </div>

                        <div class="space-y-1.5 p-3.5 bg-gray-50/80 rounded-2xl border border-gray-200/80">
                            <div class="flex items-center justify-between">
                                <label class="text-xs font-bold text-gray-800 flex items-center gap-1.5">
                                    <span>📸</span> Foto / Avatar do Agente
                                </label>
                                <span class="text-[10px] font-bold text-purple-700 bg-purple-100 px-2 py-0.5 rounded-md border border-purple-200/60">
                                    400x400 px (Quadrada)
                                </span>
                            </div>

                            <div class="flex items-center gap-3">
                                <template x-if="avatarPreview || form.avatar_url">
                                    <img :src="avatarPreview || form.avatar_url" class="w-12 h-12 rounded-2xl object-cover ring-2 ring-purple-200 flex-shrink-0 shadow-xs" alt="">
                                </template>
                                <template x-if="!avatarPreview && !form.avatar_url">
                                    <div class="w-12 h-12 rounded-2xl bg-gradient-to-tr from-purple-600 to-indigo-600 text-white flex items-center justify-center text-lg font-bold flex-shrink-0 shadow-xs">
                                        🤖
                                    </div>
                                </template>

                                <div class="flex-1 space-y-1 min-w-0">
                                    <input type="file" name="avatar_arquivo" accept="image/png,image/jpeg,image/webp"
                                           @change="handleAvatarFile($event)"
                                           class="block w-full text-xs text-gray-500 file:mr-2 file:py-1 file:px-2.5 file:rounded-lg file:border-0 file:text-[11px] file:font-semibold file:bg-purple-100 file:text-purple-700 hover:file:bg-purple-200 cursor-pointer">
                                    <input type="url" name="avatar_url" x-model="form.avatar_url" placeholder="Ou cole a URL da imagem (https://...)" class="w-full text-[11px] border border-gray-300 rounded-lg p-1.5 focus:ring-2 focus:ring-purple-500 bg-white">
                                </div>
                            </div>
                            <p class="text-[10px] text-gray-400">
                                Formato ideal: <strong>400x400 px</strong> quadrada (JPG, PNG ou WebP até 2MB).
                            </p>
                        </div>
                    </div>

                    {{-- Seleção de Funções em Grade Completa (Sem barra de rolagem) --}}
                    <div class="space-y-2 pt-1 border-t border-gray-100">
                        <div class="flex items-center justify-between">
                            <label class="text-xs font-bold text-gray-800 flex items-center gap-1.5">
                                <span>🎯</span> Selecione as Funções sob Responsabilidade deste Agente:
                            </label>
                            <span class="text-[11px] font-semibold text-purple-700 bg-purple-50 px-2.5 py-0.5 rounded-md border border-purple-100"
                                  x-text="form.cargos.length + ' função(ões) selecionada(s)'"></span>
                        </div>
                        <p class="text-[11px] text-gray-500">O agente pode acumular múltiplos papéis e funções na estrutura da Lead Certo.</p>

                        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-2.5 p-3.5 bg-gray-50/90 border border-gray-200/80 rounded-2xl">
                            @foreach($cargos as $cargo)
                            <label class="flex items-center gap-2.5 p-2.5 rounded-xl border transition-all cursor-pointer text-xs select-none"
                                   :class="form.cargos.includes({{ $cargo->id }}) ? 'bg-purple-50 border-purple-300 text-purple-900 font-semibold shadow-2xs' : 'bg-white border-gray-200 text-gray-700 hover:border-gray-300 hover:bg-gray-50/50'">
                                <input type="checkbox" name="cargos[]" value="{{ $cargo->id }}"
                                       :checked="form.cargos.includes({{ $cargo->id }})"
                                       @change="toggleCargo({{ $cargo->id }})"
                                       class="rounded text-purple-600 focus:ring-purple-500 w-4 h-4 flex-shrink-0">
                                <span class="truncate">{{ $cargo->icone ?: '💼' }} {{ $cargo->nome }}</span>
                            </label>
                            @endforeach
                        </div>
                    </div>

                    {{-- INSTRUÇÕES / DIRETRIZ GERAL DA IA (AMPLIADO) --}}
                    <div class="space-y-1.5 pt-2 border-t border-gray-100">
                        <div class="flex items-center justify-between">
                            <label class="text-xs font-bold text-gray-800 flex items-center gap-1.5">
                                <span>🧠</span> Instruções / Diretriz Geral da IA & Regras de Comportamento:
                            </label>
                            <span class="text-[10px] text-gray-400">
                                Redimensionável ↕
                            </span>
                        </div>
                        <p class="text-[11px] text-gray-500">
                            Defina a identidade, tom de voz, etapas de atendimento e as regras de restrição obrigatórias da IA.
                        </p>
                        <textarea name="gemini_instrucoes" x-model="form.gemini_instrucoes" rows="12"
                                  placeholder="Cole aqui a diretriz completa, missão e o que a IA NUNCA deve fazer..."
                                  class="w-full text-xs font-mono border border-gray-300 rounded-xl p-3 focus:ring-2 focus:ring-purple-500 leading-relaxed min-h-[260px] resize-y bg-white shadow-2xs"></textarea>
                    </div>

                </div>

                {{-- Rodapé Fixo do Formulário (Sempre Visível) --}}
                <div class="p-4 sm:p-5 border-t border-gray-100 bg-gray-50/90 flex items-center justify-between flex-shrink-0 shadow-xs">
                    <button type="button" @click="modalForm = false" class="px-5 py-2.5 bg-gray-200 text-gray-700 rounded-xl text-xs font-semibold hover:bg-gray-300 transition">Cancelar</button>
                    <button type="submit" class="px-6 py-2.5 bg-purple-600 text-white rounded-xl text-xs font-semibold hover:bg-purple-700 shadow-sm transition">Salvar Agente IA</button>
                </div>
            </form>
        </div>
    </div>
    @endif

</div>

<script>
function agentesIaModule() {
    return {
        modalDetalhes: false,
        modalForm: false,
        editandoId: null,
        agenteSelecionado: {},
        avatarPreview: null,
        form: {
            nome: '',
            email: '',
            whatsapp: '',
            avatar_url: '',
            cargos: [],
            provedor_ia: 'openrouter',
            openrouter_modelo: 'openai/gpt-4o-mini',
            gemini_email: '',
            gemini_api_key: '',
            gemini_modelo: 'gemini-1.5-pro',
            gemini_instrucoes: '',
            base_conhecimento: ''
        },
        handleAvatarFile(event) {
            const file = event.target.files[0];
            if (file) {
                this.avatarPreview = URL.createObjectURL(file);
            }
        },
        toggleCargo(id) {
            if (this.form.cargos.includes(id)) {
                this.form.cargos = this.form.cargos.filter(c => c !== id);
            } else {
                this.form.cargos.push(id);
            }
        },
        verDetalhes(agente) {
            this.agenteSelecionado = agente;
            this.modalDetalhes = true;
        },
        abrirNovo() {
            this.editandoId = null;
            this.avatarPreview = null;
            this.form = {
                nome: '',
                email: '',
                whatsapp: '',
                avatar_url: '',
                cargos: [],
                provedor_ia: 'openrouter',
                openrouter_modelo: 'openai/gpt-4o-mini',
                gemini_email: '',
                gemini_api_key: '',
                gemini_modelo: 'gemini-1.5-pro',
                gemini_instrucoes: '',
                base_conhecimento: ''
            };
            this.modalForm = true;
        },
        editar(agente, cargosIds) {
            this.editandoId = agente.id;
            this.avatarPreview = null;
            this.form = {
                nome: agente.nome,
                email: agente.email,
                whatsapp: agente.whatsapp || '',
                avatar_url: agente.avatar_url || '',
                cargos: cargosIds || [],
                provedor_ia: agente.provedor_ia || 'openrouter',
                openrouter_modelo: agente.openrouter_modelo || 'openai/gpt-4o-mini',
                gemini_email: agente.gemini_email || '',
                gemini_api_key: agente.gemini_api_key || '',
                gemini_modelo: agente.gemini_modelo || 'gemini-1.5-pro',
                gemini_instrucoes: agente.gemini_instrucoes || '',
                base_conhecimento: agente.base_conhecimento || ''
            };
            this.modalForm = true;
        }
    };
}
</script>
@endsection
