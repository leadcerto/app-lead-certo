@extends('layouts.app')

@section('title', 'Respostas Prontas — Lead Certo')

@section('content')
<div class="max-w-3xl">

    <div class="flex items-center gap-1 mb-6 border-b border-gray-200">
        <a href="{{ route('configuracoes') }}"
           class="px-4 py-2.5 text-sm font-medium border-b-2 {{ request()->routeIs('configuracoes') ? 'border-green-600 text-green-700' : 'border-transparent text-gray-400 hover:text-gray-600' }}">
            WhatsApp
        </a>
        <a href="{{ route('configuracoes.respostas-prontas') }}"
           class="px-4 py-2.5 text-sm font-medium border-b-2 {{ request()->routeIs('configuracoes.respostas-prontas') ? 'border-green-600 text-green-700' : 'border-transparent text-gray-400 hover:text-gray-600' }}">
            Respostas Prontas
        </a>
        <a href="{{ route('configuracoes.agentes') }}"
           class="px-4 py-2.5 text-sm font-medium border-b-2 {{ request()->routeIs('configuracoes.agentes') ? 'border-green-600 text-green-700' : 'border-transparent text-gray-400 hover:text-gray-600' }}">
            Agentes
        </a>
    </div>

    <div x-data="respostasProntas()" x-init="carregar()">

    <div class="flex items-center justify-between mb-6">
        <div>
            <h1 class="text-xl font-bold text-gray-800">Respostas Prontas</h1>
            <p class="text-sm text-gray-400 mt-0.5">No chat, digite <kbd class="bg-gray-100 border border-gray-300 rounded px-1.5 py-0.5 text-xs font-mono">/código</kbd> para inserir uma resposta rapidamente.</p>
        </div>
        <button @click="abrirModal()"
                class="bg-green-600 hover:bg-green-500 text-white text-sm px-4 py-2 rounded-lg transition-colors">
            + Adicionar
        </button>
    </div>

    {{-- Tabela --}}
    <div class="bg-white rounded-xl shadow-sm overflow-hidden">
        <template x-if="respostas.length === 0">
            <div class="text-center py-16 text-gray-400 text-sm">
                Nenhuma resposta pronta cadastrada ainda.
            </div>
        </template>

        <template x-if="respostas.length > 0">
            <table class="w-full text-sm">
                <thead class="bg-gray-50 border-b border-gray-100">
                    <tr>
                        <th class="text-left px-5 py-3 text-xs text-gray-500 font-semibold w-40">Código curto</th>
                        <th class="text-left px-5 py-3 text-xs text-gray-500 font-semibold">Mensagem</th>
                        <th class="px-5 py-3 w-24"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-50">
                    <template x-for="r in respostas" :key="r.id">
                        <tr class="hover:bg-gray-50 transition-colors" :class="!r.ativo ? 'opacity-40' : ''">
                            <td class="px-5 py-3">
                                <code class="bg-gray-100 text-green-700 px-2 py-0.5 rounded text-xs font-mono"
                                      x-text="'/' + r.codigo_curto"></code>
                            </td>
                            <td class="px-5 py-3 text-gray-700 leading-snug" x-text="r.conteudo"></td>
                            <td class="px-5 py-3 text-right">
                                <div class="flex items-center justify-end gap-2">
                                    <button @click="abrirModal(r)"
                                            class="text-gray-400 hover:text-blue-500 transition-colors text-xs">Editar</button>
                                    <button @click="excluir(r.id)"
                                            class="text-gray-400 hover:text-red-500 transition-colors text-xs">Excluir</button>
                                </div>
                            </td>
                        </tr>
                    </template>
                </tbody>
            </table>
        </template>
    </div>

    {{-- Modal add/edit --}}
    <template x-if="modal">
        <div class="fixed inset-0 bg-black/50 z-50 flex items-center justify-center p-4">
            <div class="bg-white rounded-2xl p-6 w-full max-w-md shadow-2xl">
                <h3 class="font-semibold text-gray-800 mb-5"
                    x-text="editando ? 'Editar resposta' : 'Nova resposta pronta'"></h3>

                <div class="space-y-4">
                    <div>
                        <label class="block text-xs text-gray-500 mb-1 font-medium">Código curto</label>
                        <div class="flex items-center border border-gray-300 rounded-lg overflow-hidden focus-within:ring-2 focus-within:ring-green-500">
                            <span class="px-3 py-2 bg-gray-50 text-gray-400 text-sm border-r border-gray-300">/</span>
                            <input x-model="form.codigo_curto" type="text"
                                   placeholder="agendamento"
                                   :disabled="!!editando"
                                   class="flex-1 px-3 py-2 text-sm focus:outline-none disabled:bg-gray-50 disabled:text-gray-400">
                        </div>
                        <p class="text-xs text-gray-400 mt-1">Somente letras, números e hífen. Sem espaços.</p>
                    </div>

                    <div>
                        <label class="block text-xs text-gray-500 mb-1 font-medium">Mensagem</label>
                        <textarea x-model="form.conteudo" rows="4"
                                  placeholder="Texto da resposta que será inserida no chat..."
                                  class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-green-500 resize-none"></textarea>
                    </div>

                    <template x-if="editando">
                        <label class="flex items-center gap-2 cursor-pointer">
                            <input type="checkbox" x-model="form.ativo" class="rounded">
                            <span class="text-sm text-gray-600">Ativa</span>
                        </label>
                    </template>
                </div>

                <div x-show="erro" class="mt-3 text-xs text-red-500" x-text="erro"></div>

                <div class="flex gap-2 mt-6">
                    <button @click="fecharModal()"
                            class="flex-1 border border-gray-300 text-gray-600 py-2 rounded-lg text-sm hover:bg-gray-50">
                        Cancelar
                    </button>
                    <button @click="salvar()"
                            :disabled="salvando"
                            class="flex-1 bg-green-600 hover:bg-green-500 disabled:opacity-40 text-white py-2 rounded-lg text-sm transition-colors">
                        <span x-text="salvando ? 'Salvando...' : 'Salvar'"></span>
                    </button>
                </div>
            </div>
        </div>
    </template>

    </div>

</div>

<script>
function respostasProntas() {
    return {
        respostas: [],
        modal: false,
        editando: null,
        salvando: false,
        erro: '',
        form: { codigo_curto: '', conteudo: '', ativo: true },

        async carregar() {
            const res = await this.api('/api/painel/respostas-prontas');
            if (res.ok) {
                const json = await res.json();
                this.respostas = json.data;
            }
        },

        abrirModal(resposta = null) {
            this.editando = resposta;
            this.erro = '';
            this.form = resposta
                ? { codigo_curto: resposta.codigo_curto, conteudo: resposta.conteudo, ativo: resposta.ativo }
                : { codigo_curto: '', conteudo: '', ativo: true };
            this.modal = true;
        },

        fecharModal() {
            this.modal = false;
            this.editando = null;
        },

        async salvar() {
            this.erro = '';
            if (!this.form.codigo_curto.trim() || !this.form.conteudo.trim()) {
                this.erro = 'Preencha o código e a mensagem.';
                return;
            }

            this.salvando = true;
            const url    = this.editando ? `/api/painel/respostas-prontas/${this.editando.id}` : '/api/painel/respostas-prontas';
            const method = this.editando ? 'PUT' : 'POST';
            const res    = await this.api(url, method, this.form);
            this.salvando = false;

            if (res.ok) {
                this.fecharModal();
                await this.carregar();
            } else {
                const json = await res.json();
                this.erro = json.message || 'Erro ao salvar.';
            }
        },

        async excluir(id) {
            if (!confirm('Excluir esta resposta pronta?')) return;
            const res = await this.api(`/api/painel/respostas-prontas/${id}`, 'DELETE');
            if (res.ok) await this.carregar();
        },

        api(url, method = 'GET', body = null) {
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
@endsection
