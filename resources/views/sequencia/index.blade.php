@extends('layouts.app')

@section('title', 'Sequência de Mensagens')

@section('content')
<div class="max-w-3xl mx-auto px-4 py-8" x-data="sequencia()">

    <div class="mb-6">
        <h1 class="text-2xl font-bold text-gray-900">Sequência de Mensagens</h1>
        <p class="mt-1 text-sm text-gray-500">
            Mensagens enviadas automaticamente quando um novo contato entra (ligação ou WhatsApp).<br>
            Use <code class="bg-gray-100 px-1 rounded">{nome}</code> para personalizar com o nome do contato.
        </p>
    </div>

    {{-- Lista de mensagens --}}
    <div class="space-y-3 mb-6">
        <template x-if="mensagens.length === 0">
            <div class="text-center py-10 text-gray-400 text-sm border-2 border-dashed border-gray-200 rounded-xl">
                Nenhuma mensagem configurada. Adicione a primeira abaixo.
            </div>
        </template>

        <template x-for="(msg, index) in mensagens" :key="msg.id">
            <div class="bg-white border border-gray-200 rounded-xl p-4 shadow-sm">
                <div class="flex items-start justify-between gap-4">
                    <div class="flex items-center gap-3 flex-1 min-w-0">
                        <span class="flex-shrink-0 w-7 h-7 rounded-full bg-green-100 text-green-700 text-xs font-bold flex items-center justify-center" x-text="index + 1"></span>
                        <div class="flex-1 min-w-0">
                            <template x-if="editandoId !== msg.id">
                                <p class="text-sm text-gray-800 whitespace-pre-wrap break-words" x-text="msg.conteudo"></p>
                            </template>
                            <template x-if="editandoId === msg.id">
                                <textarea x-model="editConteudo"
                                    class="w-full text-sm border border-gray-300 rounded-lg p-2 focus:outline-none focus:ring-2 focus:ring-green-500"
                                    rows="3"></textarea>
                            </template>
                            <div class="mt-1 flex items-center gap-3">
                                <template x-if="editandoId !== msg.id">
                                    <span class="text-xs text-gray-400">
                                        <span x-text="msg.delay_minutos === 0 ? 'Envio imediato' : 'Aguarda ' + msg.delay_minutos + ' min'"></span>
                                    </span>
                                </template>
                                <template x-if="editandoId === msg.id">
                                    <div class="flex items-center gap-2">
                                        <label class="text-xs text-gray-500">Aguarda</label>
                                        <input type="number" x-model.number="editDelay" min="0" max="10080"
                                            class="w-20 text-xs border border-gray-300 rounded px-2 py-1 focus:outline-none focus:ring-1 focus:ring-green-500">
                                        <label class="text-xs text-gray-500">minutos antes de enviar</label>
                                    </div>
                                </template>
                                <label class="flex items-center gap-1 cursor-pointer">
                                    <input type="checkbox" :checked="msg.ativo" @change="toggleAtivo(msg)"
                                        class="w-3 h-3 accent-green-600">
                                    <span class="text-xs text-gray-400">ativa</span>
                                </label>
                            </div>
                        </div>
                    </div>
                    <div class="flex items-center gap-2 flex-shrink-0">
                        <template x-if="editandoId === msg.id">
                            <button @click="salvarEdicao(msg)" class="text-xs bg-green-600 text-white px-3 py-1 rounded-lg hover:bg-green-700">Salvar</button>
                        </template>
                        <template x-if="editandoId === msg.id">
                            <button @click="editandoId = null" class="text-xs text-gray-500 hover:text-gray-700 px-2 py-1">Cancelar</button>
                        </template>
                        <template x-if="editandoId !== msg.id">
                            <button @click="iniciarEdicao(msg)" class="text-gray-400 hover:text-gray-600">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/>
                                </svg>
                            </button>
                        </template>
                        <template x-if="editandoId !== msg.id">
                            <button @click="remover(msg.id)" class="text-red-300 hover:text-red-500">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                                </svg>
                            </button>
                        </template>
                    </div>
                </div>
            </div>
        </template>
    </div>

    {{-- Adicionar nova mensagem --}}
    <div class="bg-white border border-gray-200 rounded-xl p-5 shadow-sm">
        <h2 class="text-sm font-semibold text-gray-700 mb-3">Adicionar mensagem</h2>
        <textarea x-model="novoConteudo" placeholder="Digite a mensagem... Use {nome} para o nome do contato."
            class="w-full text-sm border border-gray-300 rounded-lg p-3 focus:outline-none focus:ring-2 focus:ring-green-500"
            rows="3"></textarea>
        <div class="mt-3 flex items-center gap-4">
            <div class="flex items-center gap-2">
                <label class="text-sm text-gray-600">Aguarda</label>
                <input type="number" x-model.number="novoDelay" min="0" max="10080"
                    class="w-20 text-sm border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-green-500">
                <label class="text-sm text-gray-600">minutos antes de enviar</label>
            </div>
            <button @click="adicionar"
                :disabled="!novoConteudo.trim() || adicionando"
                class="ml-auto bg-green-600 text-white text-sm font-medium px-5 py-2 rounded-lg hover:bg-green-700 disabled:opacity-50 disabled:cursor-not-allowed">
                <span x-text="adicionando ? 'Adicionando...' : 'Adicionar'"></span>
            </button>
        </div>
    </div>

</div>
@endsection

@push('scripts')
<script>
function sequencia() {
    return {
        mensagens: [],
        novoConteudo: '',
        novoDelay: 0,
        adicionando: false,
        editandoId: null,
        editConteudo: '',
        editDelay: 0,

        async init() {
            await this.carregar();
        },

        async carregar() {
            const res = await fetch('/api/painel/sequencia/mensagens');
            this.mensagens = await res.json();
        },

        async adicionar() {
            if (!this.novoConteudo.trim()) return;
            this.adicionando = true;
            await fetch('/api/painel/sequencia/mensagens', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': '{{ csrf_token() }}' },
                body: JSON.stringify({ conteudo: this.novoConteudo, delay_minutos: this.novoDelay }),
            });
            this.novoConteudo = '';
            this.novoDelay = 0;
            this.adicionando = false;
            await this.carregar();
        },

        iniciarEdicao(msg) {
            this.editandoId = msg.id;
            this.editConteudo = msg.conteudo;
            this.editDelay = msg.delay_minutos;
        },

        async salvarEdicao(msg) {
            await fetch(`/api/painel/sequencia/mensagens/${msg.id}`, {
                method: 'PUT',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': '{{ csrf_token() }}' },
                body: JSON.stringify({ conteudo: this.editConteudo, delay_minutos: this.editDelay }),
            });
            this.editandoId = null;
            await this.carregar();
        },

        async toggleAtivo(msg) {
            await fetch(`/api/painel/sequencia/mensagens/${msg.id}`, {
                method: 'PUT',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': '{{ csrf_token() }}' },
                body: JSON.stringify({ ativo: !msg.ativo }),
            });
            await this.carregar();
        },

        async remover(id) {
            if (!confirm('Remover esta mensagem?')) return;
            await fetch(`/api/painel/sequencia/mensagens/${id}`, {
                method: 'DELETE',
                headers: { 'X-CSRF-TOKEN': '{{ csrf_token() }}' },
            });
            await this.carregar();
        },
    }
}
</script>
@endpush
