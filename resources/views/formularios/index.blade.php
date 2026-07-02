@extends('layouts.app')

@section('title', 'Formulários — Lead Certo')

@section('content')
<div class="max-w-4xl" x-data="formularios()" x-init="carregar()">

    <div class="flex items-center justify-between mb-6">
        <div>
            <h1 class="text-xl font-bold text-gray-800">Formulários de Captação</h1>
            <p class="text-sm text-gray-500 mt-1">Crie formulários e cole no site do seu cliente.</p>
        </div>
        <button @click="abrirNovo()"
                class="px-4 py-2 bg-green-600 text-white text-sm font-medium rounded-lg hover:bg-green-700 transition-colors">
            + Novo formulário
        </button>
    </div>

    {{-- Lista de formulários --}}
    <template x-if="!editando && formularios.length === 0">
        <div class="bg-white rounded-2xl shadow-sm p-12 text-center text-gray-400">
            <p class="text-sm">Nenhum formulário criado ainda.</p>
        </div>
    </template>

    <template x-if="!editando">
        <div class="space-y-4">
            <template x-for="form in formularios" :key="form.id">
                <div class="bg-white rounded-2xl shadow-sm p-5">
                    <div class="flex items-start justify-between">
                        <div>
                            <div class="flex items-center gap-2">
                                <span class="font-semibold text-gray-800" x-text="form.nome"></span>
                                <span x-show="!form.ativo"
                                      class="text-xs bg-gray-100 text-gray-500 px-2 py-0.5 rounded-full">Inativo</span>
                            </div>
                            <div class="text-xs text-gray-400 mt-1">
                                <span x-text="form.campos.length + ' campo(s)'"></span>
                                · <span x-text="form.dominios.join(', ') || 'nenhum domínio'"></span>
                            </div>
                        </div>
                        <div class="flex gap-2">
                            <button @click="editarForm(form)"
                                    class="text-sm text-blue-600 hover:text-blue-800">Editar</button>
                            <button @click="excluirForm(form.id)"
                                    class="text-sm text-red-500 hover:text-red-700">Excluir</button>
                        </div>
                    </div>

                    {{-- Código embed --}}
                    <div class="mt-4 bg-gray-50 rounded-lg p-3">
                        <div class="text-xs font-medium text-gray-500 mb-1">Cole no site do cliente:</div>
                        <div class="flex items-center gap-2">
                            <code class="text-xs text-gray-700 flex-1 overflow-x-auto whitespace-nowrap"
                                  x-text="form.embed_code"></code>
                            <button @click="copiar(form.embed_code, form.id)"
                                    class="shrink-0 text-xs px-3 py-1.5 bg-gray-800 text-white rounded-md hover:bg-gray-700">
                                <span x-text="copiado === form.id ? 'Copiado!' : 'Copiar'"></span>
                            </button>
                        </div>
                    </div>

                    <div class="mt-2">
                        <a :href="form.form_url" target="_blank"
                           class="text-xs text-blue-500 hover:underline">
                            Visualizar formulário →
                        </a>
                    </div>
                </div>
            </template>
        </div>
    </template>

    {{-- Editor --}}
    <template x-if="editando">
        <div class="bg-white rounded-2xl shadow-sm p-6">
            <div class="flex items-center justify-between mb-5">
                <h2 class="text-base font-semibold text-gray-800"
                    x-text="formAtual.id ? 'Editar formulário' : 'Novo formulário'"></h2>
                <button @click="editando = false" class="text-sm text-gray-400 hover:text-gray-600">Cancelar</button>
            </div>

            <div class="space-y-5">

                {{-- Nome --}}
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Nome do formulário</label>
                    <input type="text" x-model="formAtual.nome" placeholder="Ex: Captação Landing Page Frete"
                           class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:border-green-500">
                </div>

                {{-- Ação pós-envio --}}
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">O que acontece quando alguém envia?</label>
                    <select x-model="formAtual.acao_pos_envio"
                            class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:border-green-500">
                        <option value="bot_sdr">João (bot SDR) assume o atendimento</option>
                        <option value="mensagem_unica">Enviar mensagem única e aguardar humano</option>
                    </select>
                </div>

                {{-- Mensagem custom --}}
                <template x-if="formAtual.acao_pos_envio === 'mensagem_unica'">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Mensagem a enviar</label>
                        <textarea x-model="formAtual.mensagem_custom" rows="3"
                                  placeholder="Ex: Aqui está seu e-book: https://link.com/ebook.pdf"
                                  class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:border-green-500"></textarea>
                    </div>
                </template>

                {{-- Double opt-in --}}
                <div class="flex items-center gap-3">
                    <button @click="formAtual.double_optin = !formAtual.double_optin"
                            :class="formAtual.double_optin ? 'bg-green-500' : 'bg-gray-200'"
                            class="relative inline-flex h-6 w-11 items-center rounded-full transition-colors">
                        <span :class="formAtual.double_optin ? 'translate-x-6' : 'translate-x-1'"
                              class="inline-block w-4 h-4 bg-white rounded-full transition-transform shadow"></span>
                    </button>
                    <div>
                        <div class="text-sm font-medium text-gray-700">Double Opt-in</div>
                        <div class="text-xs text-gray-400">Lead recebe confirmação no WhatsApp antes de entrar no CRM</div>
                    </div>
                </div>

                {{-- Domínios --}}
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">
                        Domínios autorizados
                        <span class="font-normal text-gray-400">(whitelist — obrigatório)</span>
                    </label>
                    <div class="space-y-2">
                        <template x-for="(d, i) in formAtual.dominios" :key="i">
                            <div class="flex gap-2">
                                <input type="text" x-model="formAtual.dominios[i]"
                                       placeholder="meusite.com.br"
                                       class="flex-1 border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:border-green-500">
                                <button @click="formAtual.dominios.splice(i, 1)"
                                        class="text-red-400 hover:text-red-600 px-2">✕</button>
                            </div>
                        </template>
                        <button @click="formAtual.dominios.push('')"
                                class="text-sm text-blue-600 hover:text-blue-800">+ Adicionar domínio</button>
                    </div>
                </div>

                {{-- Campos --}}
                <div>
                    <div class="flex items-center justify-between mb-2">
                        <label class="text-sm font-medium text-gray-700">Campos do formulário</label>
                        <button @click="adicionarCampo()"
                                class="text-sm text-blue-600 hover:text-blue-800">+ Adicionar campo</button>
                    </div>

                    <div class="text-xs text-gray-400 mb-3">
                        O campo <strong>Telefone</strong> é sempre incluído automaticamente.
                    </div>

                    <div class="space-y-3">
                        <template x-for="(campo, i) in formAtual.campos" :key="i">
                            <div class="flex gap-2 items-start bg-gray-50 p-3 rounded-lg">
                                <div class="flex-1 grid grid-cols-3 gap-2">
                                    <input type="text" x-model="campo.rotulo" placeholder="Nome do campo"
                                           class="border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:border-green-500">
                                    <select x-model="campo.tipo"
                                            class="border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:border-green-500">
                                        <option value="texto">Texto</option>
                                        <option value="email">E-mail</option>
                                        <option value="telefone">Telefone</option>
                                        <option value="numero">Número</option>
                                        <option value="selecao">Seleção</option>
                                        <option value="area_texto">Área de texto</option>
                                    </select>
                                    <div class="flex items-center gap-2">
                                        <input type="checkbox" x-model="campo.obrigatorio" :id="'ob-' + i"
                                               class="rounded">
                                        <label :for="'ob-' + i" class="text-sm text-gray-600">Obrigatório</label>
                                    </div>
                                </div>
                                <button @click="formAtual.campos.splice(i, 1)"
                                        class="text-red-400 hover:text-red-600 mt-2">✕</button>
                            </div>
                        </template>
                    </div>
                </div>

                {{-- Salvar --}}
                <div class="flex justify-end gap-3 pt-2 border-t border-gray-100">
                    <button @click="editando = false"
                            class="px-4 py-2 text-sm text-gray-500 hover:text-gray-700">Cancelar</button>
                    <button @click="salvar()" :disabled="salvando"
                            class="px-5 py-2 bg-green-600 text-white text-sm font-medium rounded-lg hover:bg-green-700 disabled:opacity-60">
                        <span x-text="salvando ? 'Salvando...' : 'Salvar formulário'"></span>
                    </button>
                </div>
            </div>
        </div>
    </template>

</div>
@endsection

@push('scripts')
<script>
function formularios() {
    return {
        formularios: [],
        editando: false,
        salvando: false,
        copiado: null,
        formAtual: {},

        async carregar() {
            const res = await fetch('/api/painel/formularios');
            this.formularios = await res.json();
        },

        abrirNovo() {
            this.formAtual = {
                id: null,
                nome: '',
                acao_pos_envio: 'bot_sdr',
                mensagem_custom: '',
                double_optin: false,
                dominios: [''],
                campos: [],
            };
            this.editando = true;
        },

        editarForm(form) {
            this.formAtual = JSON.parse(JSON.stringify({
                ...form,
                dominios: [...(form.dominios || [])],
                campos: [...(form.campos || [])],
            }));
            this.editando = true;
        },

        adicionarCampo() {
            this.formAtual.campos.push({ rotulo: '', tipo: 'texto', obrigatorio: false });
        },

        async salvar() {
            this.salvando = true;
            const payload = {
                ...this.formAtual,
                dominios: this.formAtual.dominios.filter(d => d.trim()),
                campos: this.formAtual.campos.map((c, i) => ({
                    ...c,
                    chave: c.rotulo.toLowerCase().replace(/\s+/g, '_').replace(/[^a-z0-9_]/g, ''),
                    ordem: i,
                })),
            };

            const url    = payload.id ? `/api/painel/formularios/${payload.id}` : '/api/painel/formularios';
            const method = payload.id ? 'PUT' : 'POST';

            const res = await fetch(url, {
                method,
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                },
                body: JSON.stringify(payload),
            });

            if (res.ok) {
                await this.carregar();
                this.editando = false;
            } else {
                alert('Erro ao salvar. Verifique os campos.');
            }

            this.salvando = false;
        },

        async excluirForm(id) {
            if (!confirm('Excluir este formulário? Os envios já registrados serão preservados.')) return;
            await fetch(`/api/painel/formularios/${id}`, {
                method: 'DELETE',
                headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content },
            });
            await this.carregar();
        },

        async copiar(texto, id) {
            await navigator.clipboard.writeText(texto);
            this.copiado = id;
            setTimeout(() => this.copiado = null, 2000);
        },
    }
}
</script>
@endpush
