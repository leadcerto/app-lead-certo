@extends('layouts.app')

@section('title', 'Motivos de Encerramento')

@section('content')
<div class="max-w-2xl mx-auto" x-data="motivosDesfecho()" x-init="carregar()">
    <div class="flex items-center gap-3 mb-6">
        <a href="{{ route('kanban.config') }}"
           class="text-gray-400 hover:text-gray-600 p-1 rounded-lg hover:bg-gray-100 transition-colors">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/>
            </svg>
        </a>
        <div>
            <h1 class="text-xl font-bold text-gray-800">Motivos de Encerramento</h1>
            <p class="text-sm text-gray-500 mt-0.5">Opções mostradas ao encerrar um atendimento no Kanban</p>
        </div>
    </div>

    <div class="bg-white rounded-xl border border-gray-200 overflow-hidden mb-5">
        <template x-if="motivos.length === 0">
            <p class="text-sm text-gray-400 text-center py-8">Nenhum motivo cadastrado ainda.</p>
        </template>
        <template x-for="(motivo, i) in motivos" :key="motivo.id">
            <div class="flex items-center gap-3 px-4 py-3 border-b border-gray-100 last:border-0">
                <template x-if="editandoId !== motivo.id">
                    <div class="flex-1 flex items-center gap-2">
                        <span class="text-sm text-gray-800" x-text="motivo.label"></span>
                        <span x-show="motivo.e_venda"
                              class="text-xs bg-green-100 text-green-700 px-2 py-0.5 rounded-full font-medium">Conta como venda</span>
                    </div>
                </template>
                <template x-if="editandoId === motivo.id">
                    <div class="flex-1 flex items-center gap-2">
                        <input x-model="labelEdit" type="text"
                               class="flex-1 border rounded-lg px-2 py-1 text-sm focus:outline-none focus:ring-2 focus:ring-green-500">
                        <label class="flex items-center gap-1 text-xs text-gray-500">
                            <input type="checkbox" x-model="eVendaEdit" class="rounded">
                            Conta como venda
                        </label>
                    </div>
                </template>

                <div class="flex items-center gap-1.5 flex-shrink-0">
                    <template x-if="editandoId !== motivo.id">
                        <button @click="editandoId = motivo.id; labelEdit = motivo.label; eVendaEdit = motivo.e_venda"
                                class="text-xs text-blue-600 hover:text-blue-800 px-2 py-1">Editar</button>
                    </template>
                    <template x-if="editandoId === motivo.id">
                        <button @click="salvarEdicao(motivo.id)"
                                class="text-xs bg-green-600 hover:bg-green-700 text-white px-2.5 py-1 rounded-lg font-medium">Salvar</button>
                    </template>
                    <template x-if="editandoId === motivo.id">
                        <button @click="editandoId = null"
                                class="text-xs text-gray-400 hover:text-gray-600 px-2 py-1">Cancelar</button>
                    </template>
                    <button @click="excluir(motivo.id)"
                            class="text-xs text-red-500 hover:text-red-700 px-2 py-1">Excluir</button>
                </div>
            </div>
        </template>
    </div>

    <div class="bg-white rounded-xl border border-gray-200 p-4">
        <p class="text-xs font-semibold text-gray-500 uppercase tracking-wide mb-2">Novo motivo</p>
        <div class="flex items-center gap-2">
            <input x-model="novoLabel" type="text" placeholder="Ex: Não atendemos essa região"
                   @keydown.enter="criar()"
                   class="flex-1 border rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-green-500">
            <label class="flex items-center gap-1 text-xs text-gray-500 whitespace-nowrap">
                <input type="checkbox" x-model="novoEVenda" class="rounded">
                Conta como venda
            </label>
            <button @click="criar()" :disabled="!novoLabel.trim()"
                    class="bg-green-600 hover:bg-green-500 disabled:opacity-40 text-white px-4 py-2 rounded-lg text-sm font-medium transition-colors">
                Adicionar
            </button>
        </div>
        <p x-show="erro" x-text="erro" class="text-xs text-red-500 mt-2"></p>
    </div>
</div>

<script>
function motivosDesfecho() {
    return {
        motivos: [],
        novoLabel: '',
        novoEVenda: false,
        editandoId: null,
        labelEdit: '',
        eVendaEdit: false,
        erro: '',

        async carregar() {
            const res = await fetch('/api/painel/kanban/motivos-desfecho');
            if (res.ok) {
                const json = await res.json();
                this.motivos = json.data;
            }
        },

        async criar() {
            const label = this.novoLabel.trim();
            if (!label) return;
            this.erro = '';

            const res = await fetch('/api/painel/kanban/motivos-desfecho', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                },
                body: JSON.stringify({ label, e_venda: this.novoEVenda }),
            });

            if (res.ok) {
                this.novoLabel = '';
                this.novoEVenda = false;
                await this.carregar();
            } else {
                const json = await res.json().catch(() => ({}));
                this.erro = json.message || 'Erro ao criar motivo.';
            }
        },

        async salvarEdicao(id) {
            const res = await fetch(`/api/painel/kanban/motivos-desfecho/${id}`, {
                method: 'PUT',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                },
                body: JSON.stringify({ label: this.labelEdit, e_venda: this.eVendaEdit }),
            });

            if (res.ok) {
                this.editandoId = null;
                await this.carregar();
            }
        },

        async excluir(id) {
            if (!confirm('Excluir este motivo? Não afeta atendimentos já encerrados com ele.')) return;

            const res = await fetch(`/api/painel/kanban/motivos-desfecho/${id}`, {
                method: 'DELETE',
                headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content },
            });

            if (res.ok) await this.carregar();
        },
    };
}
</script>
@endsection
