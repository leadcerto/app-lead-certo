@extends('layouts.app')

@section('title', 'Agentes — Lead Certo')

@section('content')
<div class="max-w-3xl">

    {{-- Tab bar --}}
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

    <div x-data="agentes()" x-init="carregar()">

    <div class="flex items-center justify-between mb-6">
        <div>
            <h1 class="text-xl font-bold text-gray-800">Agentes</h1>
            <p class="text-sm text-gray-400 mt-0.5">Gerencie os membros da equipe e seus acessos.</p>
        </div>
        <button @click="modalConvite = true"
                class="bg-green-600 hover:bg-green-500 text-white text-sm px-4 py-2 rounded-lg transition-colors">
            + Convidar agente
        </button>
    </div>

    {{-- Agentes ativos --}}
    <div class="bg-white rounded-xl shadow-sm overflow-hidden mb-6">
        <div class="px-5 py-3 border-b border-gray-100 text-xs font-semibold text-gray-400 uppercase tracking-wide">
            Equipe
        </div>
        <template x-if="agentes.length === 0">
            <div class="text-center py-10 text-gray-400 text-sm">Nenhum agente além de você.</div>
        </template>
        <template x-for="ag in agentes" :key="ag.id">
            <div class="flex items-center justify-between px-5 py-3.5 border-b border-gray-50 last:border-0">
                <div>
                    <p class="text-sm font-medium text-gray-800" x-text="ag.nome"></p>
                    <p class="text-xs text-gray-400" x-text="ag.email"></p>
                </div>
                <div class="flex items-center gap-3">
                    <select @change="alterarPerfil(ag.id, $event.target.value)"
                            class="text-xs border border-gray-200 rounded-lg px-2 py-1.5 text-gray-700 bg-white focus:ring-1 focus:ring-green-400 focus:outline-none">
                        <template x-for="p in perfis" :key="p.value">
                            <option :value="p.value" :selected="ag.perfil === p.value" x-text="p.label"></option>
                        </template>
                    </select>
                    <span x-show="!ag.ativo" class="text-xs bg-red-50 text-red-500 px-2 py-0.5 rounded-full">Inativo</span>
                    <button @click="toggleAtivo(ag)"
                            :class="ag.ativo ? 'text-gray-400 hover:text-red-500' : 'text-gray-400 hover:text-green-600'"
                            :title="ag.ativo ? 'Desativar' : 'Reativar'"
                            class="text-sm transition-colors">
                        <template x-if="ag.ativo">
                            <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18.364 18.364A9 9 0 005.636 5.636m12.728 12.728A9 9 0 015.636 5.636m12.728 12.728L5.636 5.636"/></svg>
                        </template>
                        <template x-if="!ag.ativo">
                            <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                        </template>
                    </button>
                </div>
            </div>
        </template>
    </div>

    {{-- Convites pendentes --}}
    <template x-if="convites.length > 0">
        <div class="bg-white rounded-xl shadow-sm overflow-hidden mb-6">
            <div class="px-5 py-3 border-b border-gray-100 text-xs font-semibold text-gray-400 uppercase tracking-wide">
                Convites pendentes
            </div>
            <template x-for="cv in convites" :key="cv.id">
                <div class="flex items-center justify-between px-5 py-3.5 border-b border-gray-50 last:border-0">
                    <div>
                        <p class="text-sm font-medium text-gray-800" x-text="cv.email"></p>
                        <p class="text-xs text-gray-400">
                            <span x-text="perfilLabel(cv.perfil)"></span>
                            &middot; expira <span x-text="formatarData(cv.expires_at)"></span>
                        </p>
                    </div>
                    <div class="flex items-center gap-2">
                        <button @click="copiarLink(cv.token)"
                                class="text-xs text-green-700 hover:text-green-600 border border-green-200 px-2.5 py-1 rounded-lg transition-colors">
                            Copiar link
                        </button>
                        <button @click="cancelarConvite(cv.id)"
                                class="text-xs text-gray-400 hover:text-red-500 transition-colors" title="Cancelar convite">
                            &times;
                        </button>
                    </div>
                </div>
            </template>
        </div>
    </template>

    {{-- Modal: convidar agente --}}
    <template x-if="modalConvite">
        <div class="fixed inset-0 bg-black/40 flex items-center justify-center z-50" @click.self="fecharModal()">
            <div class="bg-white rounded-2xl shadow-xl w-full max-w-sm p-6">
                <h2 class="text-base font-bold text-gray-800 mb-4">Convidar agente</h2>

                <template x-if="linkGerado">
                    <div>
                        <p class="text-sm text-gray-600 mb-3">Convite criado! Copie o link abaixo e envie para o agente:</p>
                        <div class="flex items-center gap-2 bg-gray-50 border border-gray-200 rounded-lg px-3 py-2.5 mb-4">
                            <span class="text-xs text-gray-600 truncate flex-1" x-text="linkGerado"></span>
                            <button @click="copiarTexto(linkGerado)"
                                    class="text-green-700 text-xs font-medium shrink-0">Copiar</button>
                        </div>
                        <button @click="fecharModal()"
                                class="w-full bg-gray-100 hover:bg-gray-200 text-gray-700 text-sm px-4 py-2 rounded-lg transition-colors">
                            Fechar
                        </button>
                    </div>
                </template>

                <template x-if="!linkGerado">
                    <div>
                        <div class="space-y-3 mb-4">
                            <div>
                                <label class="text-xs font-medium text-gray-600 block mb-1">Nome (opcional)</label>
                                <input x-model="form.nome" type="text" placeholder="Nome do agente"
                                       class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-green-400 focus:outline-none" />
                            </div>
                            <div>
                                <label class="text-xs font-medium text-gray-600 block mb-1">E-mail *</label>
                                <input x-model="form.email" type="email" placeholder="agente@empresa.com"
                                       class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-green-400 focus:outline-none" />
                            </div>
                            <div>
                                <label class="text-xs font-medium text-gray-600 block mb-1">Perfil de acesso *</label>
                                <select x-model="form.perfil"
                                        class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-green-400 focus:outline-none bg-white">
                                    <template x-for="p in perfis" :key="p.value">
                                        <option :value="p.value" x-text="p.label"></option>
                                    </template>
                                </select>
                            </div>
                        </div>
                        <p x-show="erro" class="text-xs text-red-500 mb-3" x-text="erro"></p>
                        <div class="flex gap-2">
                            <button @click="fecharModal()"
                                    class="flex-1 bg-gray-100 hover:bg-gray-200 text-gray-700 text-sm px-4 py-2 rounded-lg transition-colors">
                                Cancelar
                            </button>
                            <button @click="enviarConvite()" :disabled="enviando"
                                    class="flex-1 bg-green-600 hover:bg-green-500 disabled:opacity-50 text-white text-sm px-4 py-2 rounded-lg transition-colors">
                                <span x-text="enviando ? 'Gerando...' : 'Gerar convite'"></span>
                            </button>
                        </div>
                    </div>
                </template>
            </div>
        </div>
    </template>

    </div>{{-- x-data --}}
</div>

<script>
function agentes() {
    return {
        agentes: [],
        convites: [],
        modalConvite: false,
        linkGerado: null,
        enviando: false,
        erro: '',
        form: { nome: '', email: '', perfil: 'vendedor' },

        perfis: [
            { value: 'dono',           label: 'Dono' },
            { value: 'diretor',        label: 'Diretor' },
            { value: 'gerente',        label: 'Gerente' },
            { value: 'gestor',         label: 'Gestor' },
            { value: 'vendedor',       label: 'Vendedor' },
            { value: 'pos_venda',      label: 'Pós-Venda' },
            { value: 'auditor',        label: 'Auditor' },
            { value: 'growth_manager', label: 'Growth Manager' },
            { value: 'revops',         label: 'RevOps' },
        ],

        async carregar() {
            const res = await fetch('/api/painel/agentes');
            if (!res.ok) return;
            const data = await res.json();
            this.agentes = data.agentes;
            this.convites = data.convites;
        },

        async enviarConvite() {
            if (!this.form.email) { this.erro = 'Informe o e-mail.'; return; }
            this.enviando = true;
            this.erro = '';
            const res = await fetch('/api/painel/agentes/convidar', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content },
                body: JSON.stringify(this.form),
            });
            const data = await res.json();
            this.enviando = false;
            if (!res.ok) { this.erro = data.message || 'Erro ao gerar convite.'; return; }
            this.linkGerado = data.link;
            await this.carregar();
        },

        async alterarPerfil(id, perfil) {
            await fetch(`/api/painel/agentes/${id}`, {
                method: 'PUT',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content },
                body: JSON.stringify({ perfil }),
            });
        },

        async toggleAtivo(ag) {
            await fetch(`/api/painel/agentes/${ag.id}`, {
                method: 'PUT',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content },
                body: JSON.stringify({ perfil: ag.perfil, ativo: !ag.ativo }),
            });
            ag.ativo = !ag.ativo;
        },

        async cancelarConvite(id) {
            await fetch(`/api/painel/agentes/convite/${id}`, {
                method: 'DELETE',
                headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content },
            });
            this.convites = this.convites.filter(c => c.id !== id);
        },

        copiarLink(token) {
            this.copiarTexto(`${window.location.origin}/convite/${token}`);
        },

        copiarTexto(texto) {
            navigator.clipboard.writeText(texto).then(() => alert('Link copiado!'));
        },

        fecharModal() {
            this.modalConvite = false;
            this.linkGerado = null;
            this.erro = '';
            this.form = { nome: '', email: '', perfil: 'vendedor' };
        },

        perfilLabel(val) {
            return this.perfis.find(p => p.value === val)?.label ?? val;
        },

        formatarData(iso) {
            return new Date(iso).toLocaleDateString('pt-BR');
        },
    };
}
</script>
@endsection
