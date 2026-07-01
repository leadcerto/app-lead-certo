@extends('layouts.app')
@section('title', 'SDR Personas')

@section('content')
<div x-data="personas()" x-init="carregar()">

    <div class="flex items-center justify-between mb-6">
        <div>
            <h1 class="text-2xl font-bold text-gray-800">SDR Personas</h1>
            <p class="text-sm text-gray-500 mt-1">Perfis de personalidade dos agentes de IA que entram em contato com leads</p>
        </div>
        <button @click="abrirNova()"
                class="bg-purple-600 hover:bg-purple-700 text-white text-sm px-4 py-2 rounded-lg flex items-center gap-2">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
            </svg>
            Nova Persona
        </button>
    </div>

    {{-- Cards de personas --}}
    <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-5">
        <template x-for="p in personas" :key="p.id">
            <div class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden"
                 :class="! p.ativo ? 'opacity-50' : ''">
                <div class="p-5">
                    <div class="flex items-start justify-between mb-3">
                        <div>
                            <h3 class="font-semibold text-gray-800" x-text="p.nome_display"></h3>
                            <p class="text-xs text-gray-400 font-mono" x-text="p.nome_interno"></p>
                        </div>
                        <div class="flex items-center gap-1.5">
                            <span x-show="p.is_default"
                                  class="text-xs bg-yellow-100 text-yellow-700 px-2 py-0.5 rounded-full font-medium">
                                Default
                            </span>
                            <span :class="p.ativo ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-500'"
                                  class="text-xs px-2 py-0.5 rounded-full font-medium"
                                  x-text="p.ativo ? 'Ativa' : 'Inativa'">
                            </span>
                        </div>
                    </div>

                    <div class="grid grid-cols-3 gap-3 text-center mb-4 bg-gray-50 rounded-lg p-3">
                        <div>
                            <p class="text-xs text-gray-400">Gênero</p>
                            <p class="text-sm font-medium text-gray-700 capitalize" x-text="p.genero ?? '—'"></p>
                        </div>
                        <div>
                            <p class="text-xs text-gray-400">Idade</p>
                            <p class="text-sm font-medium text-gray-700" x-text="p.idade_aparente ? p.idade_aparente + ' anos' : '—'"></p>
                        </div>
                        <div>
                            <p class="text-xs text-gray-400">Tom</p>
                            <p class="text-sm font-medium text-gray-700 capitalize" x-text="p.tom_de_voz ?? '—'"></p>
                        </div>
                    </div>

                    <div x-show="p.localidade" class="text-xs text-gray-500 mb-3">
                        📍 <span x-text="p.localidade"></span>
                    </div>

                    {{-- Tags de roteamento --}}
                    <div class="flex flex-wrap gap-1.5 mb-4" x-show="p.tags.length > 0">
                        <template x-for="tag in p.tags" :key="tag">
                            <span class="text-xs bg-purple-100 text-purple-700 px-2 py-0.5 rounded-full"
                                  x-text="tag"></span>
                        </template>
                    </div>
                    <p x-show="p.tags.length === 0" class="text-xs text-gray-400 mb-4 italic">Sem tags de roteamento</p>

                    {{-- System Prompt (preview) --}}
                    <div class="text-xs text-gray-600 bg-gray-50 rounded-lg p-2.5 mb-4 max-h-20 overflow-hidden leading-relaxed"
                         x-text="p.system_prompt.substring(0, 200) + (p.system_prompt.length > 200 ? '...' : '')">
                    </div>

                    <button @click="editar(p)"
                            class="w-full text-sm text-center border border-gray-200 hover:border-purple-400 hover:text-purple-700 py-1.5 rounded-lg transition-colors">
                        Editar Persona
                    </button>
                </div>
            </div>
        </template>

        <template x-if="personas.length === 0">
            <div class="col-span-3 text-center py-20 text-gray-400">
                <svg class="w-14 h-14 mx-auto mb-4 opacity-30" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/>
                </svg>
                <p class="text-sm">Nenhuma persona criada ainda.</p>
                <p class="text-xs mt-1">Clique em "Nova Persona" para começar.</p>
            </div>
        </template>
    </div>

    {{-- Modal Criar / Editar ─────────────────────────────────────────────── --}}
    <div x-show="modal" x-transition class="fixed inset-0 z-50 flex items-center justify-center bg-black/40">
        <div @click.outside="modal = false" class="bg-white rounded-2xl shadow-2xl w-full max-w-2xl mx-4 overflow-y-auto max-h-[90vh]">
            <div class="p-6 border-b border-gray-100 flex items-center justify-between">
                <h2 class="text-lg font-bold text-gray-800" x-text="editando ? 'Editar Persona' : 'Nova Persona'"></h2>
                <button @click="modal = false" class="text-gray-400 hover:text-gray-600">✕</button>
            </div>

            <form @submit.prevent="salvar" class="p-6 space-y-5">
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-xs font-medium text-gray-600 mb-1">Nome de Display *</label>
                        <input x-model="form.nome_display" type="text" required
                               placeholder="Ex: Lucas - jovem universitário"
                               class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-purple-400 outline-none">
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-600 mb-1">Código Interno *</label>
                        <input x-model="form.nome_interno" type="text" required
                               placeholder="Ex: sdr_lucas_sp"
                               class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm font-mono focus:ring-2 focus:ring-purple-400 outline-none">
                    </div>
                </div>

                <div class="grid grid-cols-3 gap-4">
                    <div>
                        <label class="block text-xs font-medium text-gray-600 mb-1">Gênero *</label>
                        <select x-model="form.genero" required
                                class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-purple-400 outline-none">
                            <option value="">Selecionar...</option>
                            <option value="masculino">Masculino</option>
                            <option value="feminino">Feminino</option>
                            <option value="neutro">Neutro</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-600 mb-1">Idade Aparente</label>
                        <input x-model="form.idade_aparente" type="number" min="18" max="80"
                               placeholder="Ex: 28"
                               class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-purple-400 outline-none">
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-600 mb-1">Tom de Voz *</label>
                        <select x-model="form.tom_de_voz" required
                                class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-purple-400 outline-none">
                            <option value="">Selecionar...</option>
                            <option value="suave">Suave</option>
                            <option value="formal">Formal</option>
                            <option value="jovial">Jovial</option>
                            <option value="direto">Direto</option>
                            <option value="tecnico">Técnico</option>
                        </select>
                    </div>
                </div>

                <div>
                    <label class="block text-xs font-medium text-gray-600 mb-1">Localidade</label>
                    <input x-model="form.localidade" type="text"
                           placeholder="Ex: Rio de Janeiro, RJ"
                           class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-purple-400 outline-none">
                </div>

                {{-- Tags de roteamento --}}
                <div>
                    <label class="block text-xs font-medium text-gray-600 mb-1">Tags de Roteamento</label>
                    <p class="text-xs text-gray-400 mb-2">Definem qual tipo de lead este SDR atende. Separe por vírgula.</p>
                    <input x-model="tagsInput" type="text"
                           placeholder="Ex: atende_b2b, atende_jovens, atende_rj"
                           class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm font-mono focus:ring-2 focus:ring-purple-400 outline-none">
                    <p class="text-xs text-gray-400 mt-1">Tags comuns: atende_b2b · atende_b2c · atende_pj · atende_pf · atende_jovens · atende_sp · atende_rj</p>
                </div>

                {{-- System Prompt --}}
                <div>
                    <label class="block text-xs font-medium text-gray-600 mb-1">System Prompt *</label>
                    <p class="text-xs text-gray-400 mb-2">
                        Instrua o tom, vocabulário e comportamento. <strong>Não invente vida pessoal real</strong> — foque no estilo de comunicação.
                    </p>
                    <textarea x-model="form.system_prompt" required rows="8"
                              placeholder="Você é um assistente de vendas com um estilo comunicativo jovem e descontraído. Use linguagem acessível, seja empático, mostre entusiasmo sem ser invasivo. Foque em entender a dor do cliente e apresentar soluções objetivas..."
                              class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-purple-400 outline-none resize-y"></textarea>
                </div>

                <div class="flex items-center gap-6">
                    <label class="flex items-center gap-2 text-sm cursor-pointer">
                        <input x-model="form.ativo" type="checkbox" class="rounded">
                        <span class="text-gray-700">Persona ativa</span>
                    </label>
                    <label class="flex items-center gap-2 text-sm cursor-pointer">
                        <input x-model="form.is_default" type="checkbox" class="rounded">
                        <span class="text-gray-700">Usar como fallback (default)</span>
                    </label>
                </div>

                <div x-show="erro" class="p-3 bg-red-50 border border-red-200 rounded-lg text-sm text-red-700" x-text="erro"></div>

                <div class="flex justify-end gap-3 pt-2">
                    <button type="button" @click="modal = false"
                            class="px-4 py-2 text-sm text-gray-600 hover:text-gray-800 border border-gray-300 rounded-lg">
                        Cancelar
                    </button>
                    <button type="submit" :disabled="salvando"
                            class="px-6 py-2 text-sm bg-purple-600 hover:bg-purple-700 text-white rounded-lg disabled:opacity-50">
                        <span x-text="salvando ? 'Salvando...' : (editando ? 'Salvar Alterações' : 'Criar Persona')"></span>
                    </button>
                </div>
            </form>
        </div>
    </div>

</div>

<script>
function personas() {
    return {
        personas:   [],
        modal:      false,
        editando:   null,
        salvando:   false,
        erro:       '',
        tagsInput:  '',
        form: {
            nome_interno: '', nome_display: '', genero: '', idade_aparente: '',
            localidade: '', tom_de_voz: '', system_prompt: '', ativo: true, is_default: false,
        },

        async carregar() {
            const res = await fetch('/api/painel/personas', { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
            if (res.ok) {
                const d = await res.json();
                this.personas = d.data;
            }
        },

        abrirNova() {
            this.editando  = null;
            this.tagsInput = '';
            this.form      = { nome_interno: '', nome_display: '', genero: '', idade_aparente: '',
                                localidade: '', tom_de_voz: '', system_prompt: '', ativo: true, is_default: false };
            this.erro  = '';
            this.modal = true;
        },

        editar(p) {
            this.editando  = p.id;
            this.tagsInput = p.tags.join(', ');
            this.form      = {
                nome_interno:   p.nome_interno,
                nome_display:   p.nome_display,
                genero:         p.genero,
                idade_aparente: p.idade_aparente ?? '',
                localidade:     p.localidade ?? '',
                tom_de_voz:     p.tom_de_voz,
                system_prompt:  p.system_prompt,
                ativo:          p.ativo,
                is_default:     p.is_default,
            };
            this.erro  = '';
            this.modal = true;
        },

        async salvar() {
            this.salvando = true;
            this.erro     = '';

            const tags = this.tagsInput
                .split(',')
                .map(t => t.trim().toLowerCase().replace(/\s+/g, '_'))
                .filter(Boolean);

            const payload = { ...this.form, tags };

            const url    = this.editando ? `/api/painel/personas/${this.editando}` : '/api/painel/personas';
            const method = this.editando ? 'PUT' : 'POST';

            const res = await fetch(url, {
                method,
                headers: {
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
                },
                body: JSON.stringify(payload),
            });

            if (res.ok) {
                this.modal = false;
                await this.carregar();
            } else {
                const d = await res.json();
                this.erro = d.message ?? 'Erro ao salvar.';
            }

            this.salvando = false;
        },
    };
}
</script>
@endsection
