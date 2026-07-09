@extends('layouts.app')

@section('title', 'Sequências de Mensagens')

@section('content')
<div class="max-w-3xl mx-auto" x-data="sequencias()" x-init="carregar()">

    <div class="flex items-center justify-between mb-6">
        <div>
            <h1 class="text-2xl font-bold text-gray-900">Sequências de Mensagens</h1>
            <p class="mt-1 text-sm text-gray-500">Cada sequência pertence a uma coluna do Kanban e só dispara quando o lead está nela.</p>
        </div>
        <button @click="novaSequenciaAberta = true"
                class="bg-green-600 hover:bg-green-700 text-white text-sm font-medium px-4 py-2 rounded-xl transition-colors">
            + Nova sequência
        </button>
    </div>

    {{-- Lista de sequências --}}
    <div class="space-y-4">
        <template x-if="lista.length === 0">
            <div class="text-center py-16 text-gray-400 text-sm border-2 border-dashed border-gray-200 rounded-xl">
                Nenhuma sequência criada ainda.
            </div>
        </template>

        <template x-for="seq in lista" :key="seq.id">
            <div class="bg-white border border-gray-200 rounded-2xl shadow-sm overflow-hidden">

                {{-- Cabeçalho da sequência --}}
                <div class="px-5 py-4 flex items-center gap-4">
                    <button @click="toggleSequencia(seq.id)"
                            class="flex-1 flex items-center gap-3 text-left min-w-0">
                        <svg class="w-4 h-4 text-gray-400 flex-shrink-0 transition-transform duration-150"
                             :class="aberto === seq.id ? 'rotate-90' : ''"
                             fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
                        </svg>
                        <div class="min-w-0">
                            <div class="flex items-center gap-2 flex-wrap">
                                <span class="text-sm font-semibold text-gray-800" x-text="seq.nome"></span>
                                <span x-show="seq.coluna_kanban"
                                      class="text-xs px-2 py-0.5 rounded-full font-medium"
                                      :style="corColuna(seq.coluna_kanban)"
                                      x-text="labelColuna(seq.coluna_kanban)"></span>
                                <span x-show="!seq.ativo"
                                      class="text-xs px-2 py-0.5 rounded-full"
                                      style="background:#f3f4f6;color:#6b7280">Inativa</span>
                            </div>
                            <p class="text-xs text-gray-400 mt-0.5 truncate" x-text="seq.descricao || '—'"></p>
                        </div>
                        <span class="ml-auto flex-shrink-0 text-xs text-gray-400"
                              x-text="seq.mensagens_count + ' msg'"></span>
                    </button>

                    {{-- Ações da sequência --}}
                    <div class="flex items-center gap-1.5 flex-shrink-0">
                        <label class="flex items-center gap-1 cursor-pointer">
                            <input type="checkbox" :checked="seq.ativo"
                                   @change="toggleAtivo(seq)"
                                   class="w-3.5 h-3.5 accent-green-600">
                            <span class="text-xs text-gray-400">ativa</span>
                        </label>
                        <button @click="editarSequencia(seq)"
                                class="text-gray-400 hover:text-gray-600 p-1">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                      d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/>
                            </svg>
                        </button>
                        <button @click="excluirSequencia(seq.id)"
                                class="text-red-300 hover:text-red-500 p-1">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                      d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                            </svg>
                        </button>
                    </div>
                </div>

                {{-- Mensagens da sequência (expandível) --}}
                <div x-show="aberto === seq.id" style="display:none"
                     class="border-t border-gray-100 px-5 py-4 space-y-3 bg-gray-50">

                    <template x-if="(mensagensPor[seq.id] || []).length === 0">
                        <p class="text-sm text-gray-400 text-center py-4">Nenhuma mensagem ainda.</p>
                    </template>

                    <template x-for="(msg, idx) in (mensagensPor[seq.id] || [])" :key="msg.id">
                        <div class="bg-white border border-gray-200 rounded-xl p-4">
                            <div class="flex items-start gap-3">
                                <span class="w-6 h-6 rounded-full text-xs font-bold flex items-center justify-center flex-shrink-0"
                                      style="background:#dcfce7;color:#16a34a"
                                      x-text="idx + 1"></span>
                                <div class="flex-1 min-w-0">
                                    <template x-if="editandoMsgId !== msg.id">
                                        <div>
                                            <template x-if="msg.imagem_url">
                                                <img :src="msg.imagem_url" class="mb-2 max-h-24 rounded-lg object-cover border border-gray-200">
                                            </template>
                                            <p class="text-sm text-gray-800 whitespace-pre-wrap break-words" x-text="msg.conteudo || '(só imagem)'"></p>
                                            <div class="mt-1 flex items-center gap-3">
                                                <span class="text-xs text-gray-400"
                                                      x-text="msg.delay_segundos === 0 ? 'Envio imediato' : 'Aguarda ' + formatDelay(msg.delay_segundos)"></span>
                                                <label class="flex items-center gap-1 cursor-pointer">
                                                    <input type="checkbox" :checked="msg.ativo"
                                                           @change="toggleAtivoMsg(seq.id, msg)"
                                                           class="w-3 h-3 accent-green-600">
                                                    <span class="text-xs text-gray-400">ativa</span>
                                                </label>
                                            </div>
                                        </div>
                                    </template>
                                    <template x-if="editandoMsgId === msg.id">
                                        <div class="space-y-2">
                                            <template x-if="editMsgImagemPreview || msg.imagem_url">
                                                <div class="relative inline-block">
                                                    <img :src="editMsgImagemPreview || msg.imagem_url"
                                                         class="max-h-24 rounded-lg object-cover border border-gray-200">
                                                    <button @click="removerImagemMsg(msg)"
                                                            class="absolute -top-1 -right-1 w-4 h-4 bg-red-500 text-white rounded-full text-xs flex items-center justify-center">✕</button>
                                                </div>
                                            </template>
                                            <textarea x-model="editMsgConteudo" rows="3"
                                                      class="w-full text-sm border border-gray-300 rounded-lg p-2 focus:outline-none focus:ring-2 focus:ring-green-500"
                                                      placeholder="Texto da mensagem..."></textarea>
                                            <div class="flex items-center gap-3 flex-wrap">
                                                <label class="flex items-center gap-1.5 cursor-pointer text-xs text-gray-500 hover:text-green-600 border border-gray-200 rounded-lg px-2 py-1.5">
                                                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                                                    </svg>
                                                    <span x-text="(editMsgImagemPreview || msg.imagem_url) ? 'Trocar imagem' : 'Imagem'"></span>
                                                    <input type="file" accept="image/*" class="hidden" @change="selecionarImagemMsg($event)">
                                                </label>
                                                <div class="flex items-center gap-1.5">
                                                    <span class="text-xs text-gray-500">Aguarda</span>
                                                    <input type="number" x-model.number="editMsgDelay" min="0"
                                                           class="w-16 text-xs border border-gray-300 rounded px-2 py-1">
                                                    <span class="text-xs text-gray-500">seg</span>
                                                </div>
                                            </div>
                                            <div class="flex gap-2">
                                                <button @click="salvarMsg(seq.id, msg)"
                                                        class="text-xs bg-green-600 text-white px-3 py-1.5 rounded-lg hover:bg-green-700">Salvar</button>
                                                <button @click="cancelarMsg()"
                                                        class="text-xs text-gray-500 hover:text-gray-700 px-2 py-1.5">Cancelar</button>
                                            </div>
                                        </div>
                                    </template>
                                </div>
                                <template x-if="editandoMsgId !== msg.id">
                                    <div class="flex items-center gap-1 flex-shrink-0">
                                        <button @click="iniciarEditarMsg(msg)" class="text-gray-400 hover:text-gray-600 p-1">
                                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                                      d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/>
                                            </svg>
                                        </button>
                                        <button @click="excluirMsg(seq.id, msg.id)" class="text-red-300 hover:text-red-500 p-1">
                                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                                      d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                                            </svg>
                                        </button>
                                    </div>
                                </template>
                            </div>
                        </div>
                    </template>

                    {{-- Adicionar mensagem --}}
                    <div class="bg-white border border-dashed border-gray-300 rounded-xl p-4">
                        <p class="text-xs font-semibold text-gray-500 mb-3">Adicionar mensagem</p>
                        <template x-if="novaImagemPreview[seq.id]">
                            <div class="relative inline-block mb-2">
                                <img :src="novaImagemPreview[seq.id]" class="max-h-24 rounded-lg object-cover border border-gray-200">
                                <button @click="novaImagem[seq.id] = null; novaImagemPreview[seq.id] = null"
                                        class="absolute -top-1 -right-1 w-4 h-4 bg-red-500 text-white rounded-full text-xs flex items-center justify-center">✕</button>
                            </div>
                        </template>
                        <textarea :x-model="`novoConteudo['${seq.id}']`"
                                  x-model.fill="novoConteudo[seq.id]"
                                  @input="novoConteudo[seq.id] = $event.target.value"
                                  :value="novoConteudo[seq.id] || ''"
                                  rows="2"
                                  placeholder="Texto... Use {nome} para personalizar."
                                  class="w-full text-sm border border-gray-300 rounded-lg p-2 focus:outline-none focus:ring-2 focus:ring-green-500"></textarea>
                        <div class="mt-2 flex items-center gap-3 flex-wrap">
                            <label class="flex items-center gap-1.5 cursor-pointer text-xs text-gray-500 hover:text-green-600 border border-gray-200 rounded-lg px-2 py-1.5">
                                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                                </svg>
                                Imagem
                                <input type="file" accept="image/*" class="hidden"
                                       @change="selecionarNovaImagem($event, seq.id)">
                            </label>
                            <div class="flex items-center gap-1.5">
                                <span class="text-xs text-gray-500">Aguarda</span>
                                <input type="number" :value="novoDelay[seq.id] || 0"
                                       @input="novoDelay[seq.id] = parseInt($event.target.value) || 0"
                                       min="0"
                                       class="w-16 text-xs border border-gray-300 rounded px-2 py-1">
                                <span class="text-xs text-gray-500">seg</span>
                            </div>
                            <button @click="adicionarMsg(seq.id)"
                                    class="ml-auto text-xs bg-green-600 text-white px-4 py-1.5 rounded-lg hover:bg-green-700">
                                Adicionar
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </template>
    </div>

    {{-- Modal: nova / editar sequência --}}
    <template x-if="novaSequenciaAberta || editando">
        <div class="fixed inset-0 bg-black/50 z-50 flex items-center justify-center p-4">
            <div class="bg-white rounded-2xl p-6 w-full max-w-md shadow-2xl">
                <h2 class="font-semibold text-gray-800 mb-4"
                    x-text="editando ? 'Editar sequência' : 'Nova sequência'"></h2>

                <div class="space-y-4">
                    <div>
                        <label class="block text-xs font-medium text-gray-600 mb-1">Nome *</label>
                        <input type="text" x-model="form.nome" placeholder="Ex: Boas-vindas Lead Novo"
                               class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-green-500">
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-600 mb-1">Descrição</label>
                        <textarea x-model="form.descricao" rows="2"
                                  placeholder="O que essa sequência faz?"
                                  class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-green-500"></textarea>
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-600 mb-1">Coluna do Kanban</label>
                        <select x-model="form.coluna_kanban"
                                class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-green-500">
                            <option value="">Sem coluna (manual)</option>
                            <option value="lead_novo">Novo</option>
                            <option value="em_atendimento">Em Atendimento</option>
                            <option value="aguardando_orcamento">Aguardando Orçamento</option>
                            <option value="aguardando_lead">Aguardando Lead</option>
                            <option value="servico_agendado">Serviço Agendado</option>
                            <option value="encerrado">Encerrado</option>
                        </select>
                    </div>
                </div>

                <div class="flex gap-2 mt-5">
                    <button @click="fecharModal()"
                            class="flex-1 border border-gray-300 text-gray-600 py-2 rounded-lg text-sm hover:bg-gray-50">
                        Cancelar
                    </button>
                    <button @click="salvarSequencia()"
                            :disabled="!form.nome.trim()"
                            class="flex-1 bg-green-600 hover:bg-green-700 disabled:opacity-40 text-white py-2 rounded-lg text-sm transition-colors">
                        Salvar
                    </button>
                </div>
            </div>
        </div>
    </template>

</div>
@endsection

@push('scripts')
<script>
function sequencias() {
    return {
        lista: [],
        aberto: null,
        mensagensPor: {},
        novaSequenciaAberta: false,
        editando: null,
        form: { nome: '', descricao: '', coluna_kanban: '' },

        // estado por sequência
        novoConteudo: {},
        novoDelay: {},
        novaImagem: {},
        novaImagemPreview: {},

        // edição de mensagem
        editandoMsgId: null,
        editMsgConteudo: '',
        editMsgDelay: 0,
        editMsgImagem: null,
        editMsgImagemPreview: null,
        editMsgRemoverImagem: false,

        async carregar() {
            const res = await this.api('/api/painel/sequencias');
            if (res.ok) this.lista = await res.json();
        },

        async toggleSequencia(id) {
            if (this.aberto === id) { this.aberto = null; return; }
            this.aberto = id;
            if (!this.mensagensPor[id]) await this.carregarMsgs(id);
        },

        async carregarMsgs(seqId) {
            const res = await this.api(`/api/painel/sequencias/${seqId}/mensagens`);
            if (res.ok) this.mensagensPor[seqId] = await res.json();
        },

        // ── Sequência CRUD ────────────────────────────────────────────────────

        editarSequencia(seq) {
            this.editando = seq;
            this.form = { nome: seq.nome, descricao: seq.descricao || '', coluna_kanban: seq.coluna_kanban || '' };
            this.novaSequenciaAberta = true;
        },

        fecharModal() {
            this.novaSequenciaAberta = false;
            this.editando = null;
            this.form = { nome: '', descricao: '', coluna_kanban: '' };
        },

        async salvarSequencia() {
            if (!this.form.nome.trim()) return;

            const body = { ...this.form, coluna_kanban: this.form.coluna_kanban || null };

            let res;
            if (this.editando) {
                res = await this.api(`/api/painel/sequencias/${this.editando.id}`, 'PUT', body);
            } else {
                res = await this.api('/api/painel/sequencias', 'POST', body);
            }
            if (res.ok) { this.fecharModal(); await this.carregar(); }
        },

        async toggleAtivo(seq) {
            await this.api(`/api/painel/sequencias/${seq.id}`, 'PUT', { ativo: !seq.ativo });
            await this.carregar();
        },

        async excluirSequencia(id) {
            if (!confirm('Excluir esta sequência e todas as suas mensagens?')) return;
            await this.api(`/api/painel/sequencias/${id}`, 'DELETE');
            if (this.aberto === id) this.aberto = null;
            await this.carregar();
        },

        // ── Mensagem CRUD ─────────────────────────────────────────────────────

        selecionarNovaImagem(e, seqId) {
            const file = e.target.files[0]; if (!file) return;
            this.novaImagem[seqId] = file;
            this.novaImagemPreview[seqId] = URL.createObjectURL(file);
        },

        async adicionarMsg(seqId) {
            const conteudo = this.novoConteudo[seqId] || '';
            const imagem   = this.novaImagem[seqId];
            if (!conteudo.trim() && !imagem) return;

            const fd = new FormData();
            fd.append('conteudo', conteudo);
            fd.append('delay_segundos', this.novoDelay[seqId] || 0);
            if (imagem) fd.append('imagem', imagem);

            const res = await fetch(`/api/painel/sequencias/${seqId}/mensagens`, {
                method: 'POST',
                headers: { 'X-CSRF-TOKEN': '{{ csrf_token() }}' },
                body: fd,
            });
            if (res.ok) {
                this.novoConteudo[seqId] = '';
                this.novoDelay[seqId] = 0;
                this.novaImagem[seqId] = null;
                this.novaImagemPreview[seqId] = null;
                await this.carregarMsgs(seqId);
                await this.carregar();
            }
        },

        iniciarEditarMsg(msg) {
            this.editandoMsgId    = msg.id;
            this.editMsgConteudo  = msg.conteudo;
            this.editMsgDelay     = msg.delay_segundos;
            this.editMsgImagem    = null;
            this.editMsgImagemPreview = null;
            this.editMsgRemoverImagem = false;
        },

        cancelarMsg() {
            this.editandoMsgId = null;
            this.editMsgImagem = null;
            this.editMsgImagemPreview = null;
        },

        selecionarImagemMsg(e) {
            const file = e.target.files[0]; if (!file) return;
            this.editMsgImagem = file;
            this.editMsgImagemPreview = URL.createObjectURL(file);
            this.editMsgRemoverImagem = false;
        },

        removerImagemMsg(msg) {
            this.editMsgImagem = null;
            this.editMsgImagemPreview = null;
            this.editMsgRemoverImagem = true;
            msg.imagem_url = null;
        },

        async salvarMsg(seqId, msg) {
            const fd = new FormData();
            fd.append('_method', 'PUT');
            fd.append('conteudo', this.editMsgConteudo);
            fd.append('delay_segundos', this.editMsgDelay);
            if (this.editMsgImagem) fd.append('imagem', this.editMsgImagem);
            if (this.editMsgRemoverImagem) fd.append('remover_imagem', '1');

            await fetch(`/api/painel/sequencias/${seqId}/mensagens/${msg.id}`, {
                method: 'POST',
                headers: { 'X-CSRF-TOKEN': '{{ csrf_token() }}' },
                body: fd,
            });
            this.cancelarMsg();
            await this.carregarMsgs(seqId);
        },

        async toggleAtivoMsg(seqId, msg) {
            await this.api(`/api/painel/sequencias/${seqId}/mensagens/${msg.id}`, 'PUT', { ativo: !msg.ativo });
            await this.carregarMsgs(seqId);
        },

        async excluirMsg(seqId, id) {
            if (!confirm('Remover esta mensagem?')) return;
            await this.api(`/api/painel/sequencias/${seqId}/mensagens/${id}`, 'DELETE');
            await this.carregarMsgs(seqId);
            await this.carregar();
        },

        // ── Helpers ───────────────────────────────────────────────────────────

        formatDelay(s) {
            if (s < 60)   return s + 's';
            if (s < 3600) return Math.floor(s/60) + 'min';
            return Math.floor(s/3600) + 'h';
        },

        labelColuna(key) {
            const m = {
                lead_novo: 'Novo', em_atendimento: 'Em Atendimento',
                aguardando_orcamento: 'Ag. Orçamento', aguardando_lead: 'Ag. Lead',
                servico_agendado: 'Serviço Agendado', encerrado: 'Encerrado',
            };
            return m[key] || key;
        },

        corColuna(key) {
            const m = {
                lead_novo:            'background:#dcfce7;color:#15803d',
                em_atendimento:       'background:#dbeafe;color:#1d4ed8',
                aguardando_orcamento: 'background:#fef9c3;color:#a16207',
                aguardando_lead:      'background:#ffedd5;color:#c2410c',
                servico_agendado:     'background:#f0fdf4;color:#166534',
                encerrado:            'background:#f1f5f9;color:#475569',
            };
            return m[key] || 'background:#f3f4f6;color:#6b7280';
        },

        async api(url, method = 'GET', body = null) {
            return fetch(url, {
                method,
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                },
                body: body ? JSON.stringify(body) : null,
            });
        },
    };
}
</script>
@endpush
