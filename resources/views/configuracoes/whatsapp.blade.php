@extends('layouts.app')

@section('title', 'Configurações — Lead Certo')

@section('content')
<div class="max-w-lg">

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

    {{-- Toggle IA SDR --}}
    <div class="mb-6 bg-white rounded-2xl shadow-sm p-5"
         x-data="{ sdrAtivo: {{ $sdrAtivo ? 'true' : 'false' }}, salvando: false }"
         @change.stop>
        <div class="flex items-center justify-between gap-4">
            <div>
                <p class="text-sm font-semibold text-gray-800">Agente IA (SDR)</p>
                <p class="text-xs text-gray-500 mt-0.5">
                    Quando ativado, a IA responde automaticamente após o cliente enviar mensagem.
                    Desativado, apenas as mensagens de sequência são enviadas.
                </p>
            </div>
            <button
                @click="sdrAtivo = !sdrAtivo; toggleIa(sdrAtivo)"
                :class="sdrAtivo ? 'bg-green-600' : 'bg-gray-300'"
                class="relative inline-flex h-7 w-12 flex-shrink-0 cursor-pointer rounded-full transition-colors duration-200 focus:outline-none"
                :disabled="salvando">
                <span
                    :class="sdrAtivo ? 'translate-x-5' : 'translate-x-1'"
                    class="inline-block h-5 w-5 mt-1 transform rounded-full bg-white shadow transition-transform duration-200">
                </span>
            </button>
        </div>
        <p class="mt-2 text-xs font-medium" :class="sdrAtivo ? 'text-green-600' : 'text-gray-400'"
           x-text="sdrAtivo ? '✓ IA ativa — respondendo automaticamente' : '✗ IA pausada — apenas sequência automática'">
        </p>
    </div>

    <script>
    async function toggleIa(valor) {
        await fetch('/api/painel/ia/sdr-ativo', {
            method: 'PUT',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
            },
            body: JSON.stringify({ sdr_ativo: valor }),
        });
    }
    </script>

    {{-- Retenção de conversas --}}
    <div class="mb-6 bg-white rounded-2xl shadow-sm p-5"
         x-data="{
             dias: {{ $retencaoDias ?? 'null' }},
             salvando: false,
             mensagem: null,
             async salvar() {
                 this.salvando = true;
                 this.mensagem = null;
                 const res = await fetch('/api/painel/whatsapp/retencao', {
                     method: 'PUT',
                     headers: {
                         'Content-Type': 'application/json',
                         'X-CSRF-TOKEN': document.querySelector('meta[name=\'csrf-token\']').content,
                     },
                     body: JSON.stringify({ dias: this.dias ? parseInt(this.dias) : null }),
                 });
                 this.salvando = false;
                 this.mensagem = res.ok ? 'Salvo!' : 'Erro ao salvar.';
                 setTimeout(() => this.mensagem = null, 3000);
             }
         }">
        <p class="text-sm font-semibold text-gray-800 mb-1">Retenção de conversas</p>
        <p class="text-xs text-gray-500 mb-3">
            Conversas mais antigas que o prazo definido são apagadas automaticamente toda madrugada.
            Deixe em branco para nunca apagar.
        </p>
        <div class="flex items-center gap-3">
            <input
                type="number"
                x-model="dias"
                min="1"
                max="3650"
                placeholder="Ex: 90"
                class="w-28 rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-green-500 focus:ring-1 focus:ring-green-500 focus:outline-none">
            <span class="text-sm text-gray-500">dias</span>
            <button
                @click="salvar()"
                :disabled="salvando"
                class="px-4 py-2 rounded-lg bg-green-600 hover:bg-green-700 text-white text-sm font-medium transition-colors disabled:opacity-50">
                <span x-show="!salvando">Salvar</span>
                <span x-show="salvando">Salvando...</span>
            </button>
            <span x-show="mensagem" x-text="mensagem"
                  :class="mensagem === 'Salvo!' ? 'text-green-600' : 'text-red-500'"
                  class="text-sm font-medium"></span>
        </div>
        <p x-show="dias" class="mt-2 text-xs text-amber-600">
            ⚠ Conversas com mais de <span x-text="dias"></span> dias serão apagadas permanentemente.
        </p>
    </div>

    <div x-data="whatsappCanais()" x-init="carregar()">

    <div class="flex items-center justify-between mb-2">
        <h1 class="text-xl font-bold text-gray-800">WhatsApp Não-Oficial</h1>
        <button @click="conectarNovo()" :disabled="conectando"
                class="px-4 py-2 rounded-lg bg-green-600 hover:bg-green-700 text-white text-sm font-medium transition-colors disabled:opacity-50">
            + Conectar novo número
        </button>
    </div>
    <p class="text-xs text-gray-500 mb-6">
        Conexão direta via QR Code (WhatsApp comum, tecnologia Baileys) — sem garantias de entrega da Meta.
        Use para prospecção. Para o número que recebe leads de anúncios, veja a API Oficial (em breve nesta página).
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
                Nenhum número não-oficial conectado ainda.
            </div>
        </template>
    </div>

    </div>

</div>

<script>
function whatsappCanais() {
    return {
        canais: [],
        conectando: false,
        erro: null,
        intervalos: {},

        async carregar() {
            const res = await fetch('/api/painel/whatsapp/canais', {
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
</script>
@endsection
