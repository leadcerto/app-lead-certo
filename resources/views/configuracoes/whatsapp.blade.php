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

    <div x-data="whatsapp()" x-init="verificarStatus()">

    <h1 class="text-xl font-bold text-gray-800 mb-6">Conexão WhatsApp</h1>

    <div class="bg-white rounded-2xl shadow-sm p-6 space-y-5">

        {{-- Status atual --}}
        <div class="flex items-center gap-3">
            <template x-if="status === 'connected'">
                <span class="flex items-center gap-2 text-green-600 font-medium">
                    <span class="w-2.5 h-2.5 rounded-full bg-green-500"></span>
                    Conectado
                </span>
            </template>
            <template x-if="status === 'connecting'">
                <span class="flex items-center gap-2 text-yellow-600 font-medium">
                    <span class="w-2.5 h-2.5 rounded-full bg-yellow-400 animate-pulse"></span>
                    Conectando...
                </span>
            </template>
            <template x-if="status === 'disconnected' || !status">
                <span class="flex items-center gap-2 text-gray-500 font-medium">
                    <span class="w-2.5 h-2.5 rounded-full bg-gray-400"></span>
                    Desconectado
                </span>
            </template>
            <template x-if="phone">
                <span class="text-sm text-gray-500" x-text="phone"></span>
            </template>
        </div>

        {{-- QR Code --}}
        <template x-if="status !== 'connected'">
            <div class="space-y-4">
                <p class="text-sm text-gray-600">
                    Escaneie o QR Code abaixo com o WhatsApp do número que vai receber os leads.
                </p>

                <div class="flex justify-center">
                    <template x-if="qrcode">
                        <img :src="'data:image/png;base64,' + qrcode"
                             alt="QR Code WhatsApp"
                             class="w-56 h-56 border border-gray-200 rounded-xl p-2">
                    </template>
                    <template x-if="!qrcode && !carregando">
                        <button @click="gerarQr()"
                                class="w-56 h-56 border-2 border-dashed border-gray-300 rounded-xl flex flex-col items-center justify-center text-gray-400 hover:border-green-400 hover:text-green-500 transition-colors cursor-pointer">
                            <svg class="w-8 h-8 mb-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 4v16m8-8H4"/>
                            </svg>
                            <span class="text-sm font-medium">Gerar QR Code</span>
                        </button>
                    </template>
                    <template x-if="carregando">
                        <div class="w-56 h-56 border border-gray-200 rounded-xl flex items-center justify-center text-gray-400">
                            <svg class="w-8 h-8 animate-spin" fill="none" viewBox="0 0 24 24">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8H4z"/>
                            </svg>
                        </div>
                    </template>
                </div>

                <template x-if="erro">
                    <p class="text-sm text-red-500 text-center" x-text="erro"></p>
                </template>

                <template x-if="qrcode">
                    <p class="text-xs text-gray-400 text-center">
                        O QR Code expira em alguns minutos.
                        <button @click="gerarQr()" class="text-green-600 hover:underline">Gerar novo</button>
                    </p>
                </template>
            </div>
        </template>

        {{-- Conectado --}}
        <template x-if="status === 'connected'">
            <div class="text-sm text-gray-500 space-y-1">
                <p>O WhatsApp está conectado e pronto para receber leads.</p>
                <template x-if="connectedSince">
                    <p class="text-xs text-gray-400" x-text="'Conectado desde ' + new Date(connectedSince).toLocaleString('pt-BR')"></p>
                </template>
            </div>
        </template>

    </div>

    </div>

</div>

<script>
function whatsapp() {
    return {
        status: null,
        phone: null,
        connectedSince: null,
        qrcode: null,
        carregando: false,
        erro: null,
        intervalo: null,

        async verificarStatus() {
            const res = await fetch('/api/painel/whatsapp/status', {
                headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content }
            });
            if (res.ok) {
                const data = await res.json();
                this.status = data.status;
                this.phone  = data.phone;
                this.connectedSince = data.connected_since;

                if (this.status === 'connected') {
                    clearInterval(this.intervalo);
                }
            }
        },

        async gerarQr() {
            this.carregando = true;
            this.erro = null;
            this.qrcode = null;

            const res = await fetch('/api/painel/whatsapp/qrcode', {
                headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content }
            });

            this.carregando = false;

            if (res.ok) {
                const data = await res.json();
                this.qrcode = data.qrcode;
                this.iniciarPolling();
            } else {
                const err = await res.json();
                this.erro = err.message || 'Erro ao gerar QR Code.';
            }
        },

        iniciarPolling() {
            clearInterval(this.intervalo);
            this.intervalo = setInterval(async () => {
                await this.verificarStatus();
                if (this.status === 'connected') {
                    this.qrcode = null;
                    clearInterval(this.intervalo);
                }
            }, 3000);
        },

        destroy() {
            clearInterval(this.intervalo);
        }
    };
}
</script>
@endsection
