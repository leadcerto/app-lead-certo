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
                                        <span>✨</span> Gemini Pro Direto
                                    </span>
                                @else
                                    <span class="inline-flex items-center gap-1 text-[10px] font-bold text-blue-800 bg-blue-100/70 px-2 py-0.5 rounded-md border border-blue-200/60">
                                        <span>🟣</span> OpenRouter
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
                    <div class="flex flex-wrap gap-1.5">
                        @forelse($agente->cargos as $c)
                            <span class="inline-flex items-center gap-1.5 text-xs bg-indigo-50 text-indigo-700 font-medium px-2.5 py-1 rounded-xl border border-indigo-100/80">
                                <span>{{ $c->icone ?: '💼' }}</span> {{ $c->nome }}
                            </span>
                        @empty
                            <span class="text-xs text-gray-400 italic">Nenhuma função associada</span>
                        @endforelse
                    </div>
                </div>

                {{-- Instruções / System Prompt (Preview) --}}
                @if($agente->gemini_instrucoes)
                <div class="bg-gray-50/80 rounded-2xl p-3.5 border border-gray-100">
                    <p class="text-[10px] font-bold uppercase tracking-wider text-gray-400 mb-1">Diretriz Operacional:</p>
                    <p class="text-xs text-gray-600 line-clamp-2 leading-relaxed">
                        {{ $agente->gemini_instrucoes }}
                    </p>
                </div>
                @endif

                {{-- Base de Conhecimento (Preview) --}}
                @if($agente->base_conhecimento)
                <div class="bg-amber-50/60 rounded-2xl p-3.5 border border-amber-200/60">
                    <div class="flex items-center gap-1.5 text-[10px] font-bold uppercase tracking-wider text-amber-800 mb-1">
                        <span>📚</span> Base de Conhecimento & Aprendizado:
                    </div>
                    <p class="text-xs text-amber-950 font-mono text-[11px] line-clamp-2 leading-relaxed">
                        {{ $agente->base_conhecimento }}
                    </p>
                </div>
                @endif
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
    <div x-show="modalDetalhes" x-transition.opacity class="fixed inset-0 z-50 flex items-center justify-center bg-black/60 backdrop-blur-xs p-4 overflow-y-auto">
        <div @click.outside="modalDetalhes = false" class="bg-white rounded-3xl shadow-2xl w-full max-w-2xl overflow-hidden max-h-[88vh] flex flex-col my-auto">
            <div class="p-6 border-b border-gray-100 flex items-start justify-between bg-gradient-to-r from-purple-50/70 via-white to-indigo-50/70 flex-shrink-0">
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

            <div class="p-6 space-y-4 text-sm overflow-y-auto flex-1">
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
                       x-text="agenteSelecionado.provedor_ia === 'gemini_direto' ? 'Google Gemini Pro (Conexão Direta Nativa)' : 'OpenRouter (Multi-model Gateway)'"></p>
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
    <div x-show="modalForm" x-transition.opacity class="fixed inset-0 z-50 flex items-center justify-center bg-black/60 backdrop-blur-xs p-3 sm:p-4 overflow-hidden">
        <div @click.outside="modalForm = false" class="bg-white rounded-3xl shadow-2xl w-full max-w-4xl max-h-[88vh] flex flex-col my-auto overflow-hidden">
            
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
            <form :action="editandoId ? '/equipe/agentes-ia/' + editandoId : '{{ route('equipe.agentes-ia.store') }}'" method="POST" class="flex flex-col flex-1 overflow-hidden min-h-0">
                @csrf
                <template x-if="editandoId">
                    <input type="hidden" name="_method" value="PUT">
                </template>

                {{-- Corpo do Formulário (Scroll Interno Suave) --}}
                <div class="p-5 sm:p-6 space-y-4 overflow-y-auto flex-1">
                    
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
                                        Utiliza o hub multi-modelos (GPT-4o, Claude 3.5 Sonnet/Haiku, Llama) com fallback automático.
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

                    {{-- CONFIGURAÇÕES DA CONTA / API GOOGLE GEMINI PRO --}}
                    <div class="p-4 bg-purple-50/60 border border-purple-200 rounded-2xl space-y-3">
                        <div class="flex items-center gap-1.5 text-xs font-bold text-purple-900">
                            <span>✨</span> Credenciais Google Gemini Pro
                        </div>
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                            <div class="space-y-1">
                                <label class="text-xs font-medium text-purple-800">E-mail da Conta Google</label>
                                <input type="email" name="gemini_email" x-model="form.gemini_email" placeholder="nathanelllfernandees@gmail.com" class="w-full text-xs border border-purple-300 bg-white rounded-xl p-2.5 focus:ring-2 focus:ring-purple-500">
                            </div>
                            <div class="space-y-1">
                                <label class="text-xs font-medium text-purple-800">Modelo Nativo do Gemini</label>
                                <select name="gemini_modelo" x-model="form.gemini_modelo" class="w-full text-xs border border-purple-300 bg-white rounded-xl p-2.5 focus:ring-2 focus:ring-purple-500">
                                    <option value="gemini-1.5-pro">Gemini 1.5 Pro (Raciocínio & Precisão Máxima)</option>
                                    <option value="gemini-2.0-flash">Gemini 2.0 Flash (Velocidade & Resposta Instantânea)</option>
                                    <option value="gemini-1.5-flash">Gemini 1.5 Flash (Leve & Econômico)</option>
                                </select>
                            </div>
                        </div>
                        <div class="space-y-1">
                            <label class="text-xs font-medium text-purple-800">Chave de API do Google AI Studio / Gemini Key (Opcional se usar chave global)</label>
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
                        <textarea name="base_conhecimento" x-model="form.base_conhecimento" rows="6"
                                  placeholder="Ex:
- Horário de atendimento: Segunda a Sábado das 08h às 19h.
- Região atendida: Rio de Janeiro e Grande Rio.
- Formas de pagamento: PIX, Cartão em até 12x, Boleto para empresas.
- Serviços principais: Fretes rápidos, mudanças residenciais e comerciais com montagem.
- Instruções aprendidas: Se o cliente perguntar sobre seguro, informar que temos apólice inclusa..."
                                  class="w-full text-xs font-mono border border-amber-300 bg-white rounded-xl p-3 focus:ring-2 focus:ring-amber-500 leading-relaxed"></textarea>
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3.5">
                        <div class="space-y-1">
                            <label class="text-xs font-bold text-gray-800">WhatsApp de Notificação / Recuperação</label>
                            <input type="text" name="whatsapp" x-model="form.whatsapp" placeholder="Ex: 21984503924" class="w-full text-xs border border-gray-300 rounded-xl p-2.5 focus:ring-2 focus:ring-purple-500">
                        </div>
                        <div class="space-y-1">
                            <label class="text-xs font-bold text-gray-800">Avatar / Foto URL</label>
                            <input type="url" name="avatar_url" x-model="form.avatar_url" placeholder="https://..." class="w-full text-xs border border-gray-300 rounded-xl p-2.5 focus:ring-2 focus:ring-purple-500">
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

                    <div class="space-y-1 pt-1">
                        <label class="text-xs font-bold text-gray-800">Instruções / Diretriz Geral da IA</label>
                        <textarea name="gemini_instrucoes" x-model="form.gemini_instrucoes" rows="3" placeholder="Instruções para o comportamento e tom de voz da IA..." class="w-full text-xs border border-gray-300 rounded-xl p-2.5 focus:ring-2 focus:ring-purple-500 leading-relaxed"></textarea>
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
        form: {
            nome: '',
            email: '',
            whatsapp: '',
            avatar_url: '',
            cargos: [],
            provedor_ia: 'openrouter',
            gemini_email: '',
            gemini_api_key: '',
            gemini_modelo: 'gemini-1.5-pro',
            gemini_instrucoes: '',
            base_conhecimento: ''
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
            this.form = {
                nome: '',
                email: '',
                whatsapp: '',
                avatar_url: '',
                cargos: [],
                provedor_ia: 'openrouter',
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
            this.form = {
                nome: agente.nome,
                email: agente.email,
                whatsapp: agente.whatsapp || '',
                avatar_url: agente.avatar_url || '',
                cargos: cargosIds || [],
                provedor_ia: agente.provedor_ia || 'openrouter',
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
