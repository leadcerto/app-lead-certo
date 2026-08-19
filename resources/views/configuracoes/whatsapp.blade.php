@extends('layouts.app')

@section('title', 'Configurações — Lead Certo')

@section('content')
<div class="max-w-2xl">

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

    {{--
        Achado real 2026-08-19 (Leonardo): existem 3 tipos de conexão distintos, não 2.
        Escanear o QR de um bloco com o app errado dá problema — cada bloco abaixo é
        isolado (JS próprio, filtro próprio por `app`), pra nunca misturar QR de um
        tipo com outro. Nomes exatamente como definidos por ele, pra ninguém confundir.
    --}}

    <div x-data="whatsappCanais('business')" x-init="carregar()">

    <div class="flex items-center justify-between mb-2">
        <h1 class="text-xl font-bold text-gray-800">WhatsApp Business (API Não Oficial — uazapi)</h1>
        <button @click="conectarNovo()" :disabled="conectando"
                class="px-4 py-2 rounded-lg bg-green-600 hover:bg-green-700 text-white text-sm font-medium transition-colors disabled:opacity-50">
            + Conectar novo número
        </button>
    </div>
    <p class="text-xs text-gray-500 mb-6">
        Conexão direta via QR Code com o app <strong>WhatsApp Business</strong> (tecnologia Baileys) — sem garantias de entrega da Meta.
        Use para prospecção. Escaneie este QR com o app WhatsApp Business, nunca com o Messenger comum.
    </p>

    <template x-if="erro">
        <div class="mb-4 p-3 rounded-lg bg-red-50 border border-red-200">
            <p class="text-sm text-red-600" x-text="erro"></p>
        </div>
    </template>

    <div class="space-y-4">
        <template x-for="canal in canais" :key="canal.id">
            <div class="bg-white rounded-2xl shadow-sm p-5">
                <div class="flex items-center justify-between gap-3 mb-3">
                    <div class="flex items-center gap-2">
                        <template x-if="canal.status === 'connected'">
                            <span class="flex items-center gap-2 text-green-600 font-medium text-sm">
                                <span class="w-2.5 h-2.5 rounded-full bg-green-500"></span>
                                Conectado <span class="text-gray-400 font-normal" x-text="canal.phone"></span>
                            </span>
                        </template>
                        <template x-if="canal.status !== 'connected'">
                            <span class="flex items-center gap-2 text-gray-500 font-medium text-sm">
                                <span class="w-2.5 h-2.5 rounded-full bg-gray-400"></span>
                                Desconectado
                            </span>
                        </template>
                    </div>
                    <button @click="excluirCanal(canal)" class="text-red-300 hover:text-red-500 text-xs">Remover</button>
                </div>

                <template x-if="canal.status !== 'connected'">
                    <div class="flex justify-center">
                        <template x-if="canal.qrcode">
                            <img :src="'data:image/png;base64,' + canal.qrcode" class="w-48 h-48 border border-gray-200 rounded-xl p-2">
                        </template>
                        <template x-if="!canal.qrcode">
                            <button @click="gerarQr(canal)"
                                    class="w-48 h-48 border-2 border-dashed border-gray-300 rounded-xl flex items-center justify-center text-gray-400 hover:border-green-400 hover:text-green-500 text-sm font-medium transition-colors">
                                Gerar QR Code
                            </button>
                        </template>
                    </div>
                </template>
            </div>
        </template>

        <template x-if="canais.length === 0 && !conectando">
            <div class="text-center py-8 text-gray-400 text-sm border-2 border-dashed border-gray-200 rounded-xl">
                Nenhum número WhatsApp Business (não-oficial) conectado ainda.
            </div>
        </template>
    </div>

    </div>

    <div class="mt-10" x-data="whatsappCanais('messenger')" x-init="carregar()">

    <div class="flex items-center justify-between mb-2">
        <h1 class="text-xl font-bold text-gray-800">WhatsApp Messenger (API Não Oficial — uazapi)</h1>
        <button @click="conectarNovo()" :disabled="conectando"
                class="px-4 py-2 rounded-lg bg-green-600 hover:bg-green-700 text-white text-sm font-medium transition-colors disabled:opacity-50">
            + Conectar novo número
        </button>
    </div>
    <p class="text-xs text-gray-500 mb-6">
        Conexão direta via QR Code com o app <strong>WhatsApp Messenger</strong> comum (tecnologia Baileys) — sem garantias de entrega da Meta.
        Permite Comunidades e Grupos. Escaneie este QR com o app WhatsApp Messenger, nunca com o Business.
    </p>

    <template x-if="erro">
        <div class="mb-4 p-3 rounded-lg bg-red-50 border border-red-200">
            <p class="text-sm text-red-600" x-text="erro"></p>
        </div>
    </template>

    <div class="space-y-4">
        <template x-for="canal in canais" :key="canal.id">
            <div class="bg-white rounded-2xl shadow-sm p-5">
                <div class="flex items-center justify-between gap-3 mb-3">
                    <div class="flex items-center gap-2">
                        <template x-if="canal.status === 'connected'">
                            <span class="flex items-center gap-2 text-green-600 font-medium text-sm">
                                <span class="w-2.5 h-2.5 rounded-full bg-green-500"></span>
                                Conectado <span class="text-gray-400 font-normal" x-text="canal.phone"></span>
                            </span>
                        </template>
                        <template x-if="canal.status !== 'connected'">
                            <span class="flex items-center gap-2 text-gray-500 font-medium text-sm">
                                <span class="w-2.5 h-2.5 rounded-full bg-gray-400"></span>
                                Desconectado
                            </span>
                        </template>
                    </div>
                    <button @click="excluirCanal(canal)" class="text-red-300 hover:text-red-500 text-xs">Remover</button>
                </div>

                <template x-if="canal.status !== 'connected'">
                    <div class="flex justify-center">
                        <template x-if="canal.qrcode">
                            <img :src="'data:image/png;base64,' + canal.qrcode" class="w-48 h-48 border border-gray-200 rounded-xl p-2">
                        </template>
                        <template x-if="!canal.qrcode">
                            <button @click="gerarQr(canal)"
                                    class="w-48 h-48 border-2 border-dashed border-gray-300 rounded-xl flex items-center justify-center text-gray-400 hover:border-green-400 hover:text-green-500 text-sm font-medium transition-colors">
                                Gerar QR Code
                            </button>
                        </template>
                    </div>
                </template>
            </div>
        </template>

        <template x-if="canais.length === 0 && !conectando">
            <div class="text-center py-8 text-gray-400 text-sm border-2 border-dashed border-gray-200 rounded-xl">
                Nenhum número WhatsApp Messenger conectado ainda.
            </div>
        </template>
    </div>

    </div>

    <div class="mt-10" x-data="whatsappCanaisOficiais()" x-init="carregar()">

    <div class="flex items-center justify-between mb-2">
        <h1 class="text-xl font-bold text-gray-800">WhatsApp Business (API Oficial — CoverCut)</h1>
        <button @click="mostrarFormulario = true" x-show="!mostrarFormulario"
                class="px-4 py-2 rounded-lg bg-green-600 hover:bg-green-700 text-white text-sm font-medium transition-colors">
            + Conectar número oficial
        </button>
    </div>
    <p class="text-xs text-gray-500 mb-6">
        API oficial da Meta (sempre WhatsApp Business), hoje via CoverCut. Cadastre o número primeiro no painel da CoverCut, depois cole os dados aqui.
        Só responde quem já escreveu — nunca envia mensagem por conta própria.
    </p>

    <template x-if="erro">
        <div class="mb-4 p-3 rounded-lg bg-red-50 border border-red-200">
            <p class="text-sm text-red-600" x-text="erro"></p>
        </div>
    </template>

    <template x-if="mostrarFormulario">
        <div class="bg-white rounded-2xl shadow-sm p-5 mb-4 space-y-3">
            <div>
                <label class="text-xs font-medium text-gray-600">Phone Number ID (painel da Covercut)</label>
                <input x-model="novo.phone_number_id" type="text" placeholder="Ex: 123456789012345"
                       class="mt-1 w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-green-500 focus:ring-1 focus:ring-green-500 focus:outline-none">
            </div>
            <div>
                <label class="text-xs font-medium text-gray-600">Telefone</label>
                <input x-model="novo.telefone" type="text" placeholder="Ex: 5521981813106"
                       class="mt-1 w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-green-500 focus:ring-1 focus:ring-green-500 focus:outline-none">
            </div>
            <div>
                <label class="text-xs font-medium text-gray-600">Apelido (opcional)</label>
                <input x-model="novo.apelido" type="text" placeholder="Ex: Principal"
                       class="mt-1 w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-green-500 focus:ring-1 focus:ring-green-500 focus:outline-none">
            </div>
            <div class="flex gap-2 pt-1">
                <button @click="conectar()" :disabled="conectando"
                        class="px-4 py-2 rounded-lg bg-green-600 hover:bg-green-700 text-white text-sm font-medium transition-colors disabled:opacity-50">
                    <span x-show="!conectando">Conectar</span>
                    <span x-show="conectando">Conectando...</span>
                </button>
                <button @click="mostrarFormulario = false" class="px-4 py-2 rounded-lg text-gray-500 hover:text-gray-700 text-sm">
                    Cancelar
                </button>
            </div>
        </div>
    </template>

    <div class="space-y-4">
        <template x-for="canal in canais" :key="canal.id">
            <div class="bg-white rounded-2xl shadow-sm p-5">
                <div class="flex items-center justify-between gap-3">
                    <div class="flex items-center gap-2">
                        <span class="w-2.5 h-2.5 rounded-full bg-green-500"></span>
                        <span class="text-sm font-medium text-gray-800" x-text="canal.phone"></span>
                        <span class="text-xs text-gray-400" x-text="'ID: ' + canal.phone_number_id"></span>
                    </div>
                    <button @click="excluirCanal(canal)" class="text-red-300 hover:text-red-500 text-xs">Remover</button>
                </div>
            </div>
        </template>

        <template x-if="canais.length === 0 && !mostrarFormulario">
            <div class="text-center py-8 text-gray-400 text-sm border-2 border-dashed border-gray-200 rounded-xl">
                Nenhum número oficial conectado ainda.
            </div>
        </template>
    </div>

    </div>

</div>

<script>
function whatsappCanais(app) {
    return {
        app: app, // 'business' ou 'messenger' — isola cada bloco do outro, nunca mistura
        canais: [],
        conectando: false,
        erro: null,
        intervalos: {},

        async carregar() {
            const res = await fetch('/api/painel/whatsapp/canais?app=' + this.app, {
                headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content }
            });
            if (res.ok) this.canais = await res.json();
        },

        async conectarNovo() {
            this.conectando = true;
            this.erro = null;
            const res = await fetch('/api/painel/whatsapp/canais', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                },
                body: JSON.stringify({ app: this.app }),
            });
            this.conectando = false;
            if (res.ok) {
                await this.carregar();
            } else {
                try {
                    const err = await res.json();
                    this.erro = err.message || 'Erro ao conectar novo número. Tente novamente.';
                } catch {
                    this.erro = 'Erro ao conectar novo número. Tente novamente.';
                }
            }
        },

        async gerarQr(canal) {
            this.erro = null;
            const res = await fetch(`/api/painel/whatsapp/canais/${canal.id}/qrcode`, {
                headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content }
            });
            if (res.ok) {
                const data = await res.json();
                canal.qrcode = data.qrcode;
                this.iniciarPolling(canal);
            } else {
                try {
                    const err = await res.json();
                    this.erro = err.message || 'Erro ao gerar QR Code. Tente novamente.';
                } catch {
                    this.erro = 'Erro ao gerar QR Code. Tente novamente.';
                }
            }
        },

        iniciarPolling(canal) {
            clearInterval(this.intervalos[canal.id]);
            this.intervalos[canal.id] = setInterval(async () => {
                const res = await fetch(`/api/painel/whatsapp/canais/${canal.id}/status`, {
                    headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content }
                });
                if (res.ok) {
                    const data = await res.json();
                    canal.status = data.status;
                    canal.phone  = data.phone;
                    if (canal.status === 'connected') {
                        canal.qrcode = null;
                        clearInterval(this.intervalos[canal.id]);
                    }
                }
            }, 3000);
        },

        async excluirCanal(canal) {
            if (!confirm('Remover este número? Essa ação não pode ser desfeita.')) return;
            this.erro = null;
            clearInterval(this.intervalos[canal.id]);
            delete this.intervalos[canal.id];
            const res = await fetch(`/api/painel/whatsapp/canais/${canal.id}`, {
                method: 'DELETE',
                headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content },
            });
            if (res.ok) {
                await this.carregar();
            } else {
                try {
                    const err = await res.json();
                    this.erro = err.message || 'Erro ao remover número. Tente novamente.';
                } catch {
                    this.erro = 'Erro ao remover número. Tente novamente.';
                }
            }
        },
    };
}

function whatsappCanaisOficiais() {
    return {
        canais: [],
        mostrarFormulario: false,
        conectando: false,
        erro: null,
        novo: { phone_number_id: '', telefone: '', apelido: '' },

        async carregar() {
            const res = await fetch('/api/painel/whatsapp/canais-oficiais', {
                headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content }
            });
            if (res.ok) this.canais = await res.json();
        },

        async conectar() {
            this.conectando = true;
            this.erro = null;
            const res = await fetch('/api/painel/whatsapp/canais-oficiais', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                },
                body: JSON.stringify(this.novo),
            });
            this.conectando = false;
            if (res.ok) {
                this.novo = { phone_number_id: '', telefone: '', apelido: '' };
                this.mostrarFormulario = false;
                await this.carregar();
            } else {
                try {
                    const err = await res.json();
                    this.erro = err.message || 'Erro ao conectar número oficial. Confira os dados.';
                } catch {
                    this.erro = 'Erro ao conectar número oficial. Confira os dados.';
                }
            }
        },

        async excluirCanal(canal) {
            if (!confirm('Remover este número oficial? Essa ação não pode ser desfeita — o webhook será desregistrado na Covercut.')) return;
            this.erro = null;
            const res = await fetch(`/api/painel/whatsapp/canais-oficiais/${canal.id}`, {
                method: 'DELETE',
                headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content },
            });
            if (res.ok) {
                await this.carregar();
            } else {
                try {
                    const err = await res.json();
                    this.erro = err.message || 'Erro ao remover número. Tente novamente.';
                } catch {
                    this.erro = 'Erro ao remover número. Tente novamente.';
                }
            }
        },
    };
}
</script>
@endsection
