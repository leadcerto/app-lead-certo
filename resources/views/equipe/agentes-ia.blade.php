@extends('layouts.app')
@section('title', 'Agentes de IA — Equipe Lead Certo')

@section('content')
<div x-data="agentesIaModule()" class="max-w-7xl mx-auto space-y-6">

    {{-- Topo / Header --}}
    <div class="flex flex-col md:flex-row md:items-center justify-between gap-4">
        <div>
            <h1 class="text-2xl font-bold text-gray-800 flex items-center gap-2">
                <span>🤖</span> Agentes Inteligentes (IA)
            </h1>
            <p class="text-sm text-gray-500 mt-1">
                Agentes virtuais com IA responsáveis pelas operações de suporte, marketing, comercial e mentoria.
            </p>
        </div>

        @if(auth()->user()->isAdmin())
        <button @click="abrirNovo()"
                class="bg-purple-600 hover:bg-purple-700 text-white text-sm font-semibold px-4 py-2.5 rounded-xl shadow-sm hover:shadow transition flex items-center gap-2 self-start md:self-auto">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
            </svg>
            Novo Agente IA
        </button>
        @endif
    </div>

    @if(session('sucesso'))
        <div class="p-4 bg-emerald-50 border border-emerald-200 text-emerald-800 rounded-xl text-sm flex items-center gap-2">
            <span>✅</span> {{ session('sucesso') }}
        </div>
    @endif

    {{-- Grid de Agentes IA --}}
    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-5">
        @forelse($agentes as $agente)
        <div class="bg-white rounded-2xl border border-gray-200 shadow-sm hover:shadow-md transition-all flex flex-col justify-between overflow-hidden">
            <div class="p-5 space-y-4">
                {{-- Cabeçalho do Card --}}
                <div class="flex items-start justify-between gap-3">
                    <div class="flex items-center gap-3">
                        @if($agente->avatar_url)
                            <img src="{{ $agente->avatar_url }}" class="w-14 h-14 rounded-2xl object-cover ring-2 ring-purple-100 flex-shrink-0" alt="">
                        @else
                            <div class="w-14 h-14 rounded-2xl bg-gradient-to-tr from-purple-600 to-indigo-500 text-white flex items-center justify-center text-xl font-bold flex-shrink-0 shadow-sm">
                                {{ mb_substr($agente->nome, 0, 1) }}
                            </div>
                        @endif
                        <div class="min-w-0">
                            <h3 class="font-bold text-gray-800 text-base truncate">{{ $agente->nome }}</h3>
                            <p class="text-xs text-gray-400 truncate">{{ $agente->email }}</p>
                            @if($agente->gemini_email)
                                <span class="inline-flex items-center gap-1 text-[11px] text-purple-700 bg-purple-50 px-2 py-0.5 rounded-md mt-1">
                                    <span>✨</span> Gemini: {{ $agente->gemini_email }}
                                </span>
                            @endif
                        </div>
                    </div>

                    @if(auth()->user()->isAdmin())
                    <button @click="editar({{ json_encode($agente) }}, {{ json_encode($agente->cargos->pluck('id')) }})"
                            class="text-xs text-gray-400 hover:text-purple-600 p-1.5 rounded-lg hover:bg-gray-100 transition"
                            title="Editar Agente IA">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z"/>
                        </svg>
                    </button>
                    @endif
                </div>

                {{-- Funções Atribuídas --}}
                <div>
                    <label class="block text-[11px] font-semibold uppercase tracking-wider text-gray-400 mb-1.5">
                        Funções sob Responsabilidade:
                    </label>
                    <div class="flex flex-wrap gap-1.5">
                        @forelse($agente->cargos as $c)
                            <span class="inline-flex items-center gap-1 text-xs bg-indigo-50 text-indigo-700 font-medium px-2.5 py-1 rounded-lg border border-indigo-100">
                                <span>{{ $c->icone ?: '💼' }}</span> {{ $c->nome }}
                            </span>
                        @empty
                            <span class="text-xs text-gray-400 italic">Nenhuma função associada</span>
                        @endforelse
                    </div>
                </div>

                {{-- Instruções / System Prompt (Preview) --}}
                @if($agente->gemini_instrucoes)
                <div class="bg-gray-50 rounded-xl p-3 border border-gray-100">
                    <p class="text-[11px] font-medium text-gray-500 mb-1">Diretriz da IA:</p>
                    <p class="text-xs text-gray-600 line-clamp-3 leading-relaxed">
                        {{ $agente->gemini_instrucoes }}
                    </p>
                </div>
                @endif
            </div>

            {{-- Rodapé do Card --}}
            <div class="px-5 py-3 bg-gray-50/80 border-t border-gray-100 flex items-center justify-between text-xs">
                <span class="text-gray-500">
                    WhatsApp: <strong>{{ $agente->whatsapp ?: 'Não configurado' }}</strong>
                </span>
                <span class="font-semibold {{ $agente->ativo ? 'text-emerald-600' : 'text-gray-400' }}">
                    {{ $agente->ativo ? '● Online / Ativo' : '○ Inativo' }}
                </span>
            </div>
        </div>
        @empty
        <div class="col-span-3 text-center py-16 bg-white rounded-2xl border border-gray-200">
            <p class="text-gray-500 font-medium text-base">Nenhum agente inteligente cadastrado.</p>
            <p class="text-gray-400 text-xs mt-1">Clique em "Novo Agente IA" para registrar o primeiro.</p>
        </div>
        @endforelse
    </div>

    {{-- Modal Novo / Editar Agente IA (Super Admin) --}}
    @if(auth()->user()->isAdmin())
    <div x-show="modalAberto" x-transition class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4">
        <div @click.outside="modalAberto = false" class="bg-white rounded-2xl shadow-2xl w-full max-w-2xl overflow-y-auto max-h-[90vh]">
            <div class="p-5 border-b border-gray-100 flex items-center justify-between">
                <h3 class="text-base font-bold text-gray-800" x-text="editando ? 'Editar Agente de IA' : 'Cadastrar Novo Agente IA'"></h3>
                <button @click="modalAberto = false" class="text-gray-400 hover:text-gray-600">✕</button>
            </div>

            <form :action="formAction" method="POST" class="p-6 space-y-4">
                @csrf
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-xs font-semibold text-gray-700 mb-1">Nome do Agente *</label>
                        <input type="text" name="nome" x-model="form.nome" required placeholder="Ex: Nathanel Fernandes"
                               class="w-full text-sm border border-gray-300 rounded-xl px-3 py-2 focus:ring-2 focus:ring-purple-400 outline-none">
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-gray-700 mb-1">E-mail Principal *</label>
                        <input type="email" name="email" x-model="form.email" required placeholder="agente@leadcerto.com"
                               class="w-full text-sm border border-gray-300 rounded-xl px-3 py-2 focus:ring-2 focus:ring-purple-400 outline-none">
                    </div>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-xs font-semibold text-gray-700 mb-1">Conta do Gmail (Gemini Pro)</label>
                        <input type="email" name="gemini_email" x-model="form.gemini_email" placeholder="exemplo@gmail.com"
                               class="w-full text-sm border border-gray-300 rounded-xl px-3 py-2 focus:ring-2 focus:ring-purple-400 outline-none">
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-gray-700 mb-1">WhatsApp</label>
                        <input type="text" name="whatsapp" x-model="form.whatsapp" placeholder="Ex: 21984503924"
                               class="w-full text-sm border border-gray-300 rounded-xl px-3 py-2 focus:ring-2 focus:ring-purple-400 outline-none">
                    </div>
                </div>

                <div>
                    <label class="block text-xs font-semibold text-gray-700 mb-1">Avatar / Foto URL</label>
                    <input type="url" name="avatar_url" x-model="form.avatar_url" placeholder="https://..."
                           class="w-full text-sm border border-gray-300 rounded-xl px-3 py-2 focus:ring-2 focus:ring-purple-400 outline-none">
                </div>

                {{-- Seleção Múltipla de Funções --}}
                <div class="border border-gray-200 rounded-2xl p-4 bg-gray-50/50">
                    <label class="block text-xs font-bold text-gray-800 mb-1">
                        🎯 Selecione as Funções sob Responsabilidade deste Agente:
                    </label>
                    <p class="text-[11px] text-gray-500 mb-3">O agente pode acumular múltiplos papéis na estrutura da Lead Certo.</p>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-2 max-h-48 overflow-y-auto pr-1">
                        @foreach($cargos as $c)
                        <label class="flex items-center gap-2 p-2 rounded-xl bg-white border border-gray-200 hover:border-purple-300 transition cursor-pointer text-xs">
                            <input type="checkbox" name="cargos[]" value="{{ $c->id }}"
                                   :checked="form.cargos.includes({{ $c->id }})"
                                   @change="toggleCargo({{ $c->id }})"
                                   class="rounded text-purple-600 focus:ring-purple-400">
                            <span class="font-medium text-gray-700">{{ $c->icone ?: '💼' }} {{ $c->nome }}</span>
                        </label>
                        @endforeach
                    </div>
                </div>

                <div>
                    <label class="block text-xs font-semibold text-gray-700 mb-1">Instruções / Diretriz Geral da IA</label>
                    <textarea name="gemini_instrucoes" x-model="form.gemini_instrucoes" rows="3"
                              class="w-full text-sm border border-gray-300 rounded-xl px-3 py-2 focus:ring-2 focus:ring-purple-400 outline-none"
                              placeholder="Comportamento, regras de negócio e tom de atuação..."></textarea>
                </div>

                <div class="flex items-center justify-between pt-2">
                    <label class="flex items-center gap-2 text-xs text-gray-600">
                        <input type="checkbox" name="ativo" value="1" x-model="form.ativo" class="rounded text-purple-600">
                        Agente Ativo no Sistema
                    </label>

                    <div class="flex items-center gap-2">
                        <button type="button" @click="modalAberto = false" class="px-4 py-2 text-xs font-semibold text-gray-500 hover:text-gray-700">
                            Cancelar
                        </button>
                        <button type="submit" class="px-5 py-2.5 bg-purple-600 hover:bg-purple-700 text-white rounded-xl text-xs font-semibold shadow">
                            Salvar Agente IA
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>
    @endif

</div>

<script>
function agentesIaModule() {
    return {
        modalAberto: false,
        editando: false,
        formAction: '{{ route("equipe.agentes-ia.store") }}',
        form: {
            nome: '',
            email: '',
            whatsapp: '',
            avatar_url: '',
            gemini_email: '',
            gemini_instrucoes: '',
            ativo: true,
            cargos: []
        },
        toggleCargo(id) {
            const index = this.form.cargos.indexOf(id);
            if (index > -1) {
                this.form.cargos.splice(index, 1);
            } else {
                this.form.cargos.push(id);
            }
        },
        abrirNovo() {
            this.editando = false;
            this.formAction = '{{ route("equipe.agentes-ia.store") }}';
            this.form = {
                nome: '',
                email: '',
                whatsapp: '',
                avatar_url: '',
                gemini_email: '',
                gemini_instrucoes: '',
                ativo: true,
                cargos: []
            };
            this.modalAberto = true;
        },
        editar(agente, cargosIds) {
            this.editando = true;
            this.formAction = '/equipe/agentes-ia/' + agente.id;
            this.form = {
                nome: agente.nome,
                email: agente.email,
                whatsapp: agente.whatsapp || '',
                avatar_url: agente.avatar_url || '',
                gemini_email: agente.gemini_email || '',
                gemini_instrucoes: agente.gemini_instrucoes || '',
                ativo: !!agente.ativo,
                cargos: cargosIds || []
            };
            this.modalAberto = true;
        }
    };
}
</script>
@endsection
