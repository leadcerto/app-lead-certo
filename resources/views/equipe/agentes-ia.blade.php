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

        @if(auth()->user()->isAdmin())
        <button @click="abrirNovo()"
                class="bg-purple-600 hover:bg-purple-700 text-white text-sm font-semibold px-4 py-2.5 rounded-xl shadow-sm hover:shadow transition-all flex items-center gap-2 self-start sm:self-auto">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
            </svg>
            Novo Agente IA
        </button>
        @endif
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
                            @if($agente->gemini_email)
                                <span class="inline-flex items-center gap-1 text-[11px] font-medium text-purple-700 bg-purple-50 px-2 py-0.5 rounded-md mt-1 border border-purple-100/80">
                                    <span>✨</span> Gemini: {{ $agente->gemini_email }}
                                </span>
                            @endif
                        </div>
                    </div>

                    @if(auth()->user()->isAdmin())
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
                    <p class="text-xs text-gray-600 line-clamp-3 leading-relaxed">
                        {{ $agente->gemini_instrucoes }}
                    </p>
                </div>
                @endif
            </div>

            {{-- Rodapé do Card --}}
            <div class="px-6 py-3.5 bg-gray-50/60 border-t border-gray-100 flex items-center justify-between text-xs">
                <span class="inline-flex items-center gap-1.5 text-emerald-700 bg-emerald-50 px-2 py-0.5 rounded-md font-medium text-[11px] border border-emerald-100">
                    <span class="w-1.5 h-1.5 rounded-full bg-emerald-500 animate-pulse"></span>
                    Agente IA Ativo
                </span>

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
    <div x-show="modalDetalhes" x-transition.opacity class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 backdrop-blur-xs p-4">
        <div @click.outside="modalDetalhes = false" class="bg-white rounded-3xl shadow-2xl w-full max-w-xl overflow-hidden max-h-[90vh] flex flex-col">
            <div class="p-6 border-b border-gray-100 flex items-start justify-between bg-gradient-to-r from-purple-50/70 via-white to-indigo-50/70">
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

                {{-- Conexão Gemini Pro --}}
                <div class="bg-purple-50/70 border border-purple-100 p-4 rounded-2xl space-y-1">
                    <h3 class="text-xs font-bold text-purple-900 flex items-center gap-1.5">
                        <span>✨</span> Motor de Inteligência Artificial:
                    </h3>
                    <p class="text-xs text-purple-700" x-text="agenteSelecionado.gemini_email ? 'Conta Google Gemini Pro: ' + agenteSelecionado.gemini_email : 'Motor: Gemini Pro / OpenRouter Padrão'"></p>
                </div>

                {{-- Instruções do Modelo --}}
                <template x-if="agenteSelecionado.gemini_instrucoes">
                    <div>
                        <h3 class="text-xs font-bold text-gray-400 uppercase tracking-wider mb-1.5">Diretrizes & Instruções Operacionais:</h3>
                        <div class="text-gray-700 bg-gray-50 p-4 rounded-2xl border border-gray-100 text-xs leading-relaxed whitespace-pre-line"
                             x-text="agenteSelecionado.gemini_instrucoes"></div>
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

    {{-- Modal de Criar / Editar Agente IA (Apenas Super Admin) --}}
    @if(auth()->user()->isAdmin())
    <div x-show="modalForm" x-transition.opacity class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 backdrop-blur-xs p-4">
        <div @click.outside="modalForm = false" class="bg-white rounded-3xl shadow-2xl w-full max-w-xl overflow-hidden max-h-[90vh] flex flex-col">
            <div class="p-6 border-b border-gray-100 flex items-center justify-between">
                <h2 class="text-lg font-bold text-gray-900" x-text="editandoId ? 'Editar Agente IA' : 'Novo Agente IA'"></h2>
                <button @click="modalForm = false" class="text-gray-400 hover:text-gray-600 text-xl font-medium">✕</button>
            </div>

            <form :action="editandoId ? '/equipe/agentes-ia/' + editandoId : '{{ route('equipe.agentes-ia.store') }}'" method="POST" class="p-6 space-y-4 overflow-y-auto flex-1">
                @csrf
                <template x-if="editandoId">
                    <input type="hidden" name="_method" value="PUT">
                </template>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <div class="space-y-1">
                        <label class="text-xs font-bold text-gray-700">Nome do Agente IA</label>
                        <input type="text" name="nome" x-model="form.nome" required placeholder="Ex: Adriana Aviag" class="w-full text-xs border border-gray-300 rounded-xl p-2.5 focus:ring-2 focus:ring-purple-500">
                    </div>
                    <div class="space-y-1">
                        <label class="text-xs font-bold text-gray-700">E-mail do Sistema</label>
                        <input type="email" name="email" x-model="form.email" required placeholder="Ex: adriana@leadcerto.app.br" class="w-full text-xs border border-gray-300 rounded-xl p-2.5 focus:ring-2 focus:ring-purple-500">
                    </div>
                </div>

                <div class="space-y-1">
                    <label class="text-xs font-bold text-gray-700">URL do Avatar / Foto</label>
                    <input type="url" name="avatar_url" x-model="form.avatar_url" placeholder="https://..." class="w-full text-xs border border-gray-300 rounded-xl p-2.5 focus:ring-2 focus:ring-purple-500">
                </div>

                <div class="space-y-1">
                    <label class="text-xs font-bold text-gray-700">Funções Sob Responsabilidade (Selecione uma ou mais)</label>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-2 max-h-40 overflow-y-auto p-2 bg-gray-50 border border-gray-200 rounded-2xl">
                        @foreach($cargos as $cargo)
                        <label class="flex items-center gap-2 p-2 rounded-xl hover:bg-white transition cursor-pointer text-xs">
                            <input type="checkbox" name="cargos[]" value="{{ $cargo->id }}"
                                   :checked="form.cargos.includes({{ $cargo->id }})"
                                   @change="toggleCargo({{ $cargo->id }})"
                                   class="rounded text-purple-600 focus:ring-purple-500">
                            <span class="font-medium text-gray-800">{{ $cargo->icone ?: '💼' }} {{ $cargo->nome }}</span>
                        </label>
                        @endforeach
                    </div>
                </div>

                <div class="p-4 bg-purple-50/60 border border-purple-200 rounded-2xl space-y-3">
                    <div class="flex items-center gap-1.5 text-xs font-bold text-purple-900">
                        <span>✨</span> Configuração Google Gemini Pro
                    </div>
                    <div class="space-y-1">
                        <label class="text-xs font-medium text-purple-800">E-mail da Conta Google (Gemini Pro)</label>
                        <input type="email" name="gemini_email" x-model="form.gemini_email" placeholder="email@gmail.com" class="w-full text-xs border border-purple-300 bg-white rounded-xl p-2 focus:ring-2 focus:ring-purple-500">
                    </div>
                    <div class="space-y-1">
                        <label class="text-xs font-medium text-purple-800">Senha / Token de Acesso</label>
                        <input type="password" name="gemini_senha" x-model="form.gemini_senha" placeholder="••••••••" class="w-full text-xs border border-purple-300 bg-white rounded-xl p-2 focus:ring-2 focus:ring-purple-500">
                    </div>
                </div>

                <div class="space-y-1">
                    <label class="text-xs font-bold text-gray-700">Diretrizes & Instruções Operacionais</label>
                    <textarea name="gemini_instrucoes" x-model="form.gemini_instrucoes" rows="3" placeholder="Instruções para o comportamento da IA..." class="w-full text-xs border border-gray-300 rounded-xl p-2.5 focus:ring-2 focus:ring-purple-500"></textarea>
                </div>

                <div class="pt-3 border-t border-gray-100 flex justify-end gap-2">
                    <button type="button" @click="modalForm = false" class="px-4 py-2 bg-gray-100 text-gray-600 rounded-xl text-xs font-semibold hover:bg-gray-200">Cancelar</button>
                    <button type="submit" class="px-5 py-2 bg-purple-600 text-white rounded-xl text-xs font-semibold hover:bg-purple-700 shadow-sm">Salvar Agente IA</button>
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
            avatar_url: '',
            cargos: [],
            gemini_email: '',
            gemini_senha: '',
            gemini_instrucoes: ''
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
                avatar_url: '',
                cargos: [],
                gemini_email: '',
                gemini_senha: '',
                gemini_instrucoes: ''
            };
            this.modalForm = true;
        },
        editar(agente, cargosIds) {
            this.editandoId = agente.id;
            this.form = {
                nome: agente.nome,
                email: agente.email,
                avatar_url: agente.avatar_url || '',
                cargos: cargosIds || [],
                gemini_email: agente.gemini_email || '',
                gemini_senha: '',
                gemini_instrucoes: agente.gemini_instrucoes || ''
            };
            this.modalForm = true;
        }
    };
}
</script>
@endsection
