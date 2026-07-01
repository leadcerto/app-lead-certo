@extends('layouts.app')
@section('title', 'Campanhas de Mineração')

@section('content')
<div x-data="campanhas()" x-init="carregar()">

    <div class="flex items-center justify-between mb-6">
        <div>
            <h1 class="text-2xl font-bold text-gray-800">Campanhas de Mineração</h1>
            <p class="text-sm text-gray-500 mt-1">Defina o alvo, ative os agentes e acompanhe a captação de contatos</p>
        </div>
        <button @click="abrirNova()"
                class="bg-orange-600 hover:bg-orange-700 text-white text-sm px-4 py-2 rounded-lg flex items-center gap-2">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
            </svg>
            Nova Campanha
        </button>
    </div>

    {{-- Cards de Campanhas --}}
    <div class="grid grid-cols-1 lg:grid-cols-2 xl:grid-cols-3 gap-5">
        <template x-for="c in lista" :key="c.id">
            <div class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden">
                <div class="p-5">
                    {{-- Header --}}
                    <div class="flex items-start justify-between mb-3">
                        <h3 class="font-semibold text-gray-800 leading-snug" x-text="c.nome"></h3>
                        <span class="ml-2 flex-shrink-0 text-xs px-2 py-0.5 rounded-full font-medium"
                              :class="{
                                  'bg-gray-100 text-gray-600':   c.status === 'rascunho',
                                  'bg-green-100 text-green-700': c.status === 'ativa',
                                  'bg-yellow-100 text-yellow-700': c.status === 'pausada',
                                  'bg-blue-100 text-blue-700':  c.status === 'concluida',
                              }"
                              x-text="c.status"></span>
                    </div>

                    {{-- Alvo --}}
                    <div class="text-xs text-gray-500 space-y-1 mb-3">
                        <p x-show="c.nicho"><span class="font-medium text-gray-600">Nicho:</span> <span x-text="c.nicho"></span></p>
                        <p x-show="c.regiao_alvo"><span class="font-medium text-gray-600">Região:</span> <span x-text="c.regiao_alvo"></span></p>
                        <p x-show="c.data_inicio || c.data_fim">
                            <span class="font-medium text-gray-600">Período:</span>
                            <span x-text="(c.data_inicio ?? '?') + ' → ' + (c.data_fim ?? 'aberto')"></span>
                        </p>
                    </div>

                    {{-- Progresso --}}
                    <div class="mb-4">
                        <div class="flex justify-between text-xs text-gray-500 mb-1">
                            <span x-text="c.contatos_importados + ' contatos'"></span>
                            <span x-show="c.meta_contatos" x-text="'meta: ' + c.meta_contatos"></span>
                        </div>
                        <div class="w-full bg-gray-100 rounded-full h-1.5">
                            <div class="bg-orange-500 h-1.5 rounded-full transition-all"
                                 :style="'width: ' + (c.meta_contatos ? c.progresso + '%' : '100%')"></div>
                        </div>
                    </div>

                    {{-- Agentes --}}
                    <div class="flex items-center gap-2 mb-4 text-xs text-gray-500">
                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.75 17L9 20l-1 1h8l-1-1-.75-3M3 13h18M5 17H3a2 2 0 01-2-2V5a2 2 0 012-2h14a2 2 0 012 2v10a2 2 0 01-2 2h-2"/>
                        </svg>
                        <span x-text="c.agentes_count + ' agente(s) minerador(es)'"></span>
                    </div>

                    {{-- Ações --}}
                    <div class="flex gap-2">
                        <button @click="gerenciar(c)"
                                class="flex-1 text-sm text-center border border-gray-200 hover:border-orange-400 hover:text-orange-700 py-1.5 rounded-lg transition-colors">
                            Agentes & Chaves
                        </button>
                        <button @click="editar(c)"
                                class="flex-1 text-sm text-center border border-gray-200 hover:border-blue-400 hover:text-blue-700 py-1.5 rounded-lg transition-colors">
                            Editar
                        </button>
                    </div>
                </div>
            </div>
        </template>

        <template x-if="lista.length === 0">
            <div class="col-span-3 text-center py-20 text-gray-400">
                <svg class="w-14 h-14 mx-auto mb-4 opacity-30" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
                </svg>
                <p class="text-sm">Nenhuma campanha criada.</p>
                <p class="text-xs mt-1">Clique em "Nova Campanha" para definir o primeiro alvo dos mineradores.</p>
            </div>
        </template>
    </div>

    {{-- Modal Criar / Editar Campanha ───────────────────────────────────── --}}
    <div x-show="modalCampanha" x-transition class="fixed inset-0 z-50 flex items-center justify-center bg-black/40">
        <div @click.outside="modalCampanha = false" class="bg-white rounded-2xl shadow-2xl w-full max-w-xl mx-4 overflow-y-auto max-h-[90vh]">
            <div class="p-6 border-b border-gray-100 flex items-center justify-between">
                <h2 class="text-lg font-bold text-gray-800" x-text="editandoCampanha ? 'Editar Campanha' : 'Nova Campanha'"></h2>
                <button @click="modalCampanha = false" class="text-gray-400 hover:text-gray-600">✕</button>
            </div>

            <form @submit.prevent="salvarCampanha" class="p-6 space-y-4">
                <div>
                    <label class="block text-xs font-medium text-gray-600 mb-1">Nome da Campanha *</label>
                    <input x-model="formC.nome" type="text" required
                           placeholder="Ex: Clínicas de Estética SP — Junho 2026"
                           class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-orange-400 outline-none">
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-xs font-medium text-gray-600 mb-1">Nicho / Segmento</label>
                        <input x-model="formC.nicho" type="text"
                               placeholder="Ex: clínicas de estética"
                               class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-orange-400 outline-none">
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-600 mb-1">Região Alvo</label>
                        <input x-model="formC.regiao_alvo" type="text"
                               placeholder="Ex: São Paulo, SP"
                               class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-orange-400 outline-none">
                    </div>
                </div>

                <div>
                    <label class="block text-xs font-medium text-gray-600 mb-1">Palavras-chave para busca</label>
                    <textarea x-model="formC.palavras_chave" rows="2"
                              placeholder="Ex: clínica estética, salão de beleza, spa, dermato..."
                              class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-orange-400 outline-none resize-none"></textarea>
                </div>

                <div class="grid grid-cols-3 gap-4">
                    <div>
                        <label class="block text-xs font-medium text-gray-600 mb-1">Status</label>
                        <select x-model="formC.status"
                                class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-orange-400 outline-none">
                            <option value="rascunho">Rascunho</option>
                            <option value="ativa">Ativa</option>
                            <option value="pausada">Pausada</option>
                            <option value="concluida">Concluída</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-600 mb-1">Data Início</label>
                        <input x-model="formC.data_inicio" type="date"
                               class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-orange-400 outline-none">
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-600 mb-1">Data Fim</label>
                        <input x-model="formC.data_fim" type="date"
                               class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-orange-400 outline-none">
                    </div>
                </div>

                <div>
                    <label class="block text-xs font-medium text-gray-600 mb-1">Meta de Contatos</label>
                    <input x-model="formC.meta_contatos" type="number" min="1"
                           placeholder="Ex: 500"
                           class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-orange-400 outline-none">
                </div>

                <div x-show="erroC" class="p-3 bg-red-50 border border-red-200 rounded-lg text-sm text-red-700" x-text="erroC"></div>

                <div class="flex justify-end gap-3 pt-2">
                    <button type="button" @click="modalCampanha = false"
                            class="px-4 py-2 text-sm text-gray-600 border border-gray-300 rounded-lg">Cancelar</button>
                    <button type="submit" :disabled="salvandoC"
                            class="px-6 py-2 text-sm bg-orange-600 hover:bg-orange-700 text-white rounded-lg disabled:opacity-50">
                        <span x-text="salvandoC ? 'Salvando...' : (editandoCampanha ? 'Salvar' : 'Criar Campanha')"></span>
                    </button>
                </div>
            </form>
        </div>
    </div>

    {{-- Modal Gerenciar Agentes ─────────────────────────────────────────── --}}
    <div x-show="modalAgentes" x-transition class="fixed inset-0 z-50 flex items-center justify-center bg-black/40">
        <div @click.outside="fecharAgentes()" class="bg-white rounded-2xl shadow-2xl w-full max-w-2xl mx-4 overflow-y-auto max-h-[90vh]">
            <div class="p-6 border-b border-gray-100 flex items-center justify-between">
                <div>
                    <h2 class="text-lg font-bold text-gray-800">Agentes Mineradores</h2>
                    <p class="text-xs text-gray-500 mt-0.5" x-text="campanhaAtiva?.nome"></p>
                </div>
                <button @click="fecharAgentes()" class="text-gray-400 hover:text-gray-600">✕</button>
            </div>

            <div class="p-6">
                {{-- Nova chave exibida --}}
                <div x-show="novaChave" class="mb-5 p-4 bg-green-50 border border-green-300 rounded-xl">
                    <p class="text-xs font-bold text-green-800 mb-1">⚠️ Copie esta chave AGORA — ela não será exibida novamente</p>
                    <div class="flex items-center gap-2">
                        <code class="flex-1 text-sm font-mono bg-white border border-green-200 rounded px-3 py-2 text-green-800 break-all"
                              x-text="novaChave"></code>
                        <button @click="copiarChave()"
                                class="text-xs bg-green-600 text-white px-3 py-2 rounded-lg hover:bg-green-500">
                            Copiar
                        </button>
                    </div>
                    <p class="text-xs text-green-700 mt-2">Use o header <code class="bg-green-100 px-1 rounded">X-Minerador-Key: [chave]</code> nas chamadas à API.</p>
                </div>

                {{-- Lista de agentes --}}
                <div class="space-y-3 mb-5">
                    <template x-for="a in agentes" :key="a.id">
                        <div class="flex items-center justify-between p-3 bg-gray-50 rounded-xl border border-gray-200">
                            <div>
                                <p class="text-sm font-medium text-gray-800" x-text="a.nome"></p>
                                <div class="flex items-center gap-2 mt-0.5">
                                    <span class="text-xs text-gray-400 font-mono" x-text="a.api_key_prefix + '...'"></span>
                                    <span class="text-xs bg-blue-100 text-blue-700 px-1.5 py-0.5 rounded capitalize" x-text="a.tipo"></span>
                                    <span :class="a.status === 'ativo' ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-700'"
                                          class="text-xs px-1.5 py-0.5 rounded" x-text="a.status"></span>
                                </div>
                                <p class="text-xs text-gray-400 mt-0.5" x-text="a.contatos_importados + ' contatos • última exec: ' + (a.ultima_execucao_em ?? 'nunca')"></p>
                            </div>
                            <div class="flex gap-1.5 ml-2">
                                <button @click="regenerarChave(a.id)"
                                        title="Gerar nova chave"
                                        class="text-xs bg-yellow-100 hover:bg-yellow-200 text-yellow-800 px-2 py-1.5 rounded-lg">
                                    🔑
                                </button>
                                <button @click="a.status === 'ativo' ? suspenderAgente(a.id) : ativarAgente(a.id)"
                                        :class="a.status === 'ativo' ? 'bg-red-100 hover:bg-red-200 text-red-700' : 'bg-green-100 hover:bg-green-200 text-green-700'"
                                        class="text-xs px-2 py-1.5 rounded-lg"
                                        x-text="a.status === 'ativo' ? 'Suspender' : 'Ativar'">
                                </button>
                            </div>
                        </div>
                    </template>
                    <p x-show="agentes.length === 0" class="text-sm text-gray-400 text-center py-6">
                        Nenhum agente cadastrado. Crie um abaixo.
                    </p>
                </div>

                {{-- Criar novo agente --}}
                <div class="border-t border-gray-200 pt-5">
                    <p class="text-sm font-medium text-gray-700 mb-3">Adicionar novo agente</p>
                    <div class="grid grid-cols-2 gap-3 mb-3">
                        <input x-model="formA.nome" type="text" placeholder="Nome do agente"
                               class="border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-orange-400 outline-none">
                        <select x-model="formA.tipo"
                                class="border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-orange-400 outline-none">
                            <option value="">Tipo...</option>
                            <option value="instagram">Instagram</option>
                            <option value="facebook">Facebook</option>
                            <option value="google">Google</option>
                            <option value="email">E-mail</option>
                            <option value="whatsapp">WhatsApp</option>
                            <option value="linkedin">LinkedIn</option>
                            <option value="tiktok">TikTok</option>
                            <option value="custom">Custom</option>
                        </select>
                    </div>
                    <button @click="criarAgente()"
                            :disabled="!formA.nome || !formA.tipo"
                            class="w-full bg-orange-600 hover:bg-orange-700 text-white text-sm py-2 rounded-lg disabled:opacity-40">
                        Criar Agente & Gerar Chave
                    </button>
                    <p class="text-xs text-gray-400 mt-2 text-center">
                        A chave API será exibida uma única vez após a criação.
                        Endpoint: <code class="bg-gray-100 px-1 rounded">POST /api/minerador/contato</code>
                    </p>
                </div>
            </div>
        </div>
    </div>

</div>

<script>
function campanhas() {
    return {
        lista:           [],
        agentes:         [],
        campanhaAtiva:   null,
        novaChave:       '',
        modalCampanha:   false,
        modalAgentes:    false,
        editandoCampanha: null,
        salvandoC:       false,
        erroC:           '',
        formC: {
            nome: '', nicho: '', regiao_alvo: '', palavras_chave: '',
            status: 'rascunho', data_inicio: '', data_fim: '', meta_contatos: '',
        },
        formA: { nome: '', tipo: '' },

        async carregar() {
            const res = await fetch('/api/painel/campanhas', { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
            if (res.ok) this.lista = (await res.json()).data;
        },

        abrirNova() {
            this.editandoCampanha = null;
            this.erroC  = '';
            this.formC  = { nome: '', nicho: '', regiao_alvo: '', palavras_chave: '',
                             status: 'rascunho', data_inicio: '', data_fim: '', meta_contatos: '' };
            this.modalCampanha = true;
        },

        editar(c) {
            this.editandoCampanha = c.id;
            this.erroC = '';
            this.formC = {
                nome:           c.nome,
                nicho:          c.nicho ?? '',
                regiao_alvo:    c.regiao_alvo ?? '',
                palavras_chave: c.palavras_chave ?? '',
                status:         c.status,
                data_inicio:    c.data_inicio_raw ?? '',
                data_fim:       c.data_fim_raw ?? '',
                meta_contatos:  c.meta_contatos ?? '',
            };
            this.modalCampanha = true;
        },

        async salvarCampanha() {
            this.salvandoC = true;
            this.erroC     = '';
            const url    = this.editandoCampanha ? `/api/painel/campanhas/${this.editandoCampanha}` : '/api/painel/campanhas';
            const method = this.editandoCampanha ? 'PUT' : 'POST';
            const res = await fetch(url, {
                method,
                headers: {
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
                },
                body: JSON.stringify(this.formC),
            });
            if (res.ok) {
                this.modalCampanha = false;
                await this.carregar();
            } else {
                const d = await res.json();
                this.erroC = d.message ?? 'Erro ao salvar.';
            }
            this.salvandoC = false;
        },

        async gerenciar(c) {
            this.campanhaAtiva = c;
            this.novaChave     = '';
            this.formA         = { nome: '', tipo: '' };
            this.modalAgentes  = true;
            await this.carregarAgentes(c.id);
        },

        fecharAgentes() {
            this.modalAgentes = false;
            this.novaChave    = '';
            this.carregar();
        },

        async carregarAgentes(campanhaId) {
            const res = await fetch(`/api/painel/campanhas/${campanhaId}/agentes`, { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
            if (res.ok) this.agentes = (await res.json()).data;
        },

        async criarAgente() {
            const res = await fetch(`/api/painel/campanhas/${this.campanhaAtiva.id}/agentes`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
                },
                body: JSON.stringify(this.formA),
            });
            if (res.ok) {
                const d = await res.json();
                this.novaChave = d.api_key;
                this.formA = { nome: '', tipo: '' };
                await this.carregarAgentes(this.campanhaAtiva.id);
            }
        },

        async regenerarChave(agenteId) {
            if (! confirm('Gerar nova chave? A chave atual será invalidada imediatamente.')) return;
            const res = await fetch(`/api/painel/campanhas/agentes/${agenteId}/regenerar-chave`, {
                method: 'POST',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
                },
            });
            if (res.ok) {
                const d = await res.json();
                this.novaChave = d.api_key;
            }
        },

        async ativarAgente(id) {
            await this.acao(`/api/painel/campanhas/agentes/${id}/ativar`);
        },
        async suspenderAgente(id) {
            await this.acao(`/api/painel/campanhas/agentes/${id}/suspender`);
        },

        async acao(url) {
            await fetch(url, {
                method: 'POST',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
                },
            });
            await this.carregarAgentes(this.campanhaAtiva.id);
        },

        copiarChave() {
            navigator.clipboard.writeText(this.novaChave);
            alert('Chave copiada!');
        },
    };
}
</script>
@endsection
