@extends('layouts.app')

@section('title', 'Contatos — Lead Certo')

@section('content')
<div x-data="contatos()" x-init="inicializar()">

    {{-- Header --}}
    <div class="flex items-start justify-between mb-6">
        <div>
            <h1 class="text-xl font-bold text-gray-800">Contatos</h1>
            <p class="text-sm text-gray-500">{{ number_format($total) }} contatos vinculados · {{ $ultimo_sync ? 'Último sync ' . $ultimo_sync : 'Nunca sincronizado' }}</p>
        </div>
        <div class="flex items-center gap-2">

            @if($google_conectado)
            <button @click="atualizarSobrenome()" :disabled="carregandoSobrenome"
                    title="Grava o ID do banco no campo Sobrenome de cada contato no Google"
                    class="flex items-center gap-2 bg-white border border-gray-300 hover:bg-gray-50 disabled:opacity-60 text-gray-600 text-sm px-3 py-2 rounded-xl shadow-sm transition-colors">
                <svg class="w-4 h-4 flex-shrink-0" :class="carregandoSobrenome && 'animate-spin'" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
                </svg>
                <span x-text="carregandoSobrenome ? 'Atualizando...' : 'Atualizar Google'"></span>
            </button>
            <button @click="sincronizarGoogle()" :disabled="carregandoGoogle"
                    class="flex items-center gap-2 bg-white border border-gray-300 hover:bg-gray-50 disabled:opacity-60 text-gray-700 text-sm font-medium px-4 py-2 rounded-xl shadow-sm transition-colors">
                <template x-if="!carregandoGoogle">
                    <svg viewBox="0 0 24 24" class="w-4 h-4 flex-shrink-0">
                        <path fill="#4285F4" d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09z"/>
                        <path fill="#34A853" d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z"/>
                        <path fill="#FBBC05" d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.07H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.93l2.85-2.22.81-.62z"/>
                        <path fill="#EA4335" d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.07l3.66 2.84c.87-2.6 3.3-4.53 6.16-4.53z"/>
                    </svg>
                </template>
                <template x-if="carregandoGoogle">
                    <svg class="w-4 h-4 animate-spin text-gray-400" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8H4z"/>
                    </svg>
                </template>
                <span x-text="carregandoGoogle ? 'Sincronizando...' : 'Sincronizar Google'"></span>
            </button>
            @else
            <a href="{{ route('integracoes') }}"
               class="flex items-center gap-2 bg-white border border-dashed border-gray-300 text-gray-400 text-sm px-4 py-2 rounded-xl shadow-sm hover:border-gray-400 transition-colors">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.656 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1"/>
                </svg>
                Conectar Google
            </a>
            @endif

            <button @click="mostrarCsv = !mostrarCsv"
                    class="flex items-center gap-1.5 bg-white border border-gray-300 hover:bg-gray-50 text-gray-600 text-sm px-3 py-2 rounded-xl shadow-sm transition-colors">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                </svg>
                CSV
            </button>
        </div>
    </div>

    {{-- Flash de sessão --}}
    @if(session('sucesso'))
    <div class="bg-green-50 border border-green-200 text-green-700 text-sm rounded-xl px-4 py-3 mb-4">
        {{ session('sucesso') }}
    </div>
    @endif

    {{-- Resultado do sync Google --}}
    <template x-if="resultado">
        <div class="bg-green-50 border border-green-200 rounded-xl px-4 py-3 mb-4 flex items-center justify-between">
            <div class="text-sm text-green-700">
                <span class="font-semibold" x-text="resultado.importados + ' contatos sincronizados'"></span>
                <span class="text-green-600" x-text="resultado.ignorados ? ' · ' + resultado.ignorados + ' sem telefone ignorados' : ''"></span>
            </div>
            <button @click="resultado = null; location.reload()"
                    class="text-xs text-green-600 hover:underline ml-4 flex-shrink-0">
                Recarregar lista
            </button>
        </div>
    </template>

    {{-- Resultado do write-back Google --}}
    <template x-if="resultadoSobrenome">
        <div class="bg-blue-50 border border-blue-200 rounded-xl px-4 py-3 mb-4 flex items-center justify-between">
            <div class="text-sm text-blue-700">
                <span class="font-semibold" x-text="resultadoSobrenome.atualizados + ' contatos atualizados no Google'"></span>
                <span x-text="resultadoSobrenome.falhas ? ' · ' + resultadoSobrenome.falhas + ' falhas' : ''"></span>
            </div>
            <button @click="resultadoSobrenome = null" class="text-xs text-blue-500 hover:underline ml-4">OK</button>
        </div>
    </template>

    {{-- Erro --}}
    <template x-if="erro">
        <div class="bg-red-50 border border-red-200 text-red-700 text-sm rounded-xl px-4 py-3 mb-4" x-text="erro"></div>
    </template>

    {{-- Painel CSV (colapsável) --}}
    <template x-if="mostrarCsv">
        <div class="bg-white rounded-2xl shadow-sm p-6 mb-4">
            <div class="flex items-center justify-between mb-4">
                <h2 class="text-sm font-semibold text-gray-700">Importar via CSV do Google</h2>
                <button @click="mostrarCsv = false" class="text-gray-400 hover:text-gray-600 text-lg leading-none">&times;</button>
            </div>

            <div class="flex items-start gap-3 mb-4 text-xs text-gray-500">
                <span class="w-5 h-5 rounded-full bg-gray-100 flex items-center justify-center font-bold flex-shrink-0">1</span>
                <span>
                    Acesse
                    <a href="https://contacts.google.com/export" target="_blank" class="text-green-600 hover:underline">contacts.google.com/export</a>
                    → Exportar → Google CSV
                </span>
            </div>

            <template x-if="!resultadoCsv">
                <div>
                    <label class="flex flex-col items-center justify-center w-full h-28 border-2 border-dashed rounded-xl cursor-pointer transition-colors"
                           :class="arquivo ? 'border-green-400 bg-green-50' : 'border-gray-200 hover:border-green-400'"
                           @dragover.prevent @drop.prevent="aoSoltar($event)">
                        <input type="file" accept=".csv,.txt" class="hidden" @change="aoEscolher($event)">
                        <template x-if="!arquivo">
                            <div class="text-center text-gray-400">
                                <svg class="w-6 h-6 mx-auto mb-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12"/>
                                </svg>
                                <p class="text-xs">Arraste o CSV ou clique para selecionar</p>
                            </div>
                        </template>
                        <template x-if="arquivo">
                            <div class="text-center text-green-600">
                                <svg class="w-6 h-6 mx-auto mb-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                                </svg>
                                <p class="text-xs font-medium" x-text="arquivo.name"></p>
                            </div>
                        </template>
                    </label>

                    <button @click="sincronizarCsv()" :disabled="!arquivo || carregandoCsv"
                            class="mt-3 w-full py-2.5 rounded-xl text-sm font-medium transition-colors"
                            :class="arquivo && !carregandoCsv ? 'bg-green-600 hover:bg-green-700 text-white' : 'bg-gray-100 text-gray-400 cursor-not-allowed'">
                        <span x-text="carregandoCsv ? 'Importando...' : 'Importar CSV'"></span>
                    </button>
                </div>
            </template>

            <template x-if="resultadoCsv">
                <div class="space-y-3">
                    <div class="bg-green-50 border border-green-200 rounded-xl p-3 text-sm text-green-700">
                        <span class="font-semibold" x-text="resultadoCsv.importados + ' contatos importados'"></span>
                        <span x-text="resultadoCsv.ignorados ? ' · ' + resultadoCsv.ignorados + ' sem telefone' : ''"></span>
                    </div>
                    <button @click="resultadoCsv = null; arquivo = null; mostrarCsv = false; location.reload()"
                            class="w-full py-2 rounded-xl text-sm text-gray-600 bg-gray-100 hover:bg-gray-200 transition-colors">
                        Fechar e recarregar
                    </button>
                </div>
            </template>
        </div>
    </template>

    {{-- Aviso base global --}}
    <div class="bg-blue-50 border border-blue-100 rounded-xl px-4 py-2.5 mb-4 flex items-center gap-2 text-xs text-blue-600">
        <svg class="w-3.5 h-3.5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
        </svg>
        <span>Contatos são compartilhados na base global. Mesmo número em outra empresa é vinculado sem duplicação.</span>
    </div>

    {{-- Lista de contatos --}}
    <div class="bg-white rounded-2xl shadow-sm overflow-hidden">
        <div class="px-5 py-3 border-b border-gray-100 flex items-center justify-between">
            <span class="text-sm font-medium text-gray-700">Lista de contatos</span>
            <span class="text-xs text-gray-400">{{ $contatos->total() }} total</span>
        </div>

        @forelse($contatos as $contato)
        <div class="flex items-center px-5 py-2.5 border-b border-gray-50 last:border-0 hover:bg-gray-50">
            <div class="flex-1 min-w-0">
                <div class="flex items-baseline gap-2">
                    <span class="text-sm text-gray-800">{{ $contato->nome ?? '—' }}</span>
                    <span class="text-xs text-gray-400 flex-shrink-0">{{ $contato->id }}</span>
                </div>
                @if($contato->profissao)
                <span class="text-xs text-gray-400">{{ $contato->profissao }}</span>
                @endif
            </div>
            <span class="text-xs text-gray-400 flex-shrink-0 ml-4">{{ $contato->telefone }}</span>
        </div>
        @empty
        <div class="px-5 py-12 text-center">
            <svg class="w-8 h-8 mx-auto text-gray-300 mb-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z"/>
            </svg>
            <p class="text-sm text-gray-400 mb-3">Nenhum contato ainda.</p>
            @if($google_conectado)
            <button @click="sincronizarGoogle()" class="text-sm text-green-600 hover:underline">
                Sincronizar do Google
            </button>
            @else
            <a href="{{ route('integracoes') }}" class="text-sm text-green-600 hover:underline">
                Conectar Google para sincronizar
            </a>
            @endif
        </div>
        @endforelse
    </div>

    {{-- Paginação --}}
    @if($contatos->hasPages())
    <div class="mt-4">
        {{ $contatos->links() }}
    </div>
    @endif

</div>

<script>
function contatos() {
    return {
        carregandoGoogle:    false,
        carregandoSobrenome: false,
        resultado:           null,
        resultadoSobrenome:  null,
        resultadoCsv:        null,
        erro:                null,
        mostrarCsv:          false,
        arquivo:             null,
        carregandoCsv:       false,

        inicializar() {
            @if(session('google_recente'))
            this.$nextTick(() => this.sincronizarGoogle());
            @endif
        },

        async sincronizarGoogle() {
            this.carregandoGoogle = true;
            this.resultado = null;
            this.erro = null;

            try {
                const res = await fetch('/api/painel/contatos/sincronizar-google', {
                    method: 'POST',
                    headers: {
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                        'Accept': 'application/json',
                    },
                });

                const data = await res.json();

                if (res.ok) {
                    this.resultado = data;
                } else {
                    this.erro = data.erro || 'Erro ao sincronizar com o Google.';
                }
            } catch (e) {
                this.erro = 'Erro de conexão. Verifique se o servidor está rodando.';
            } finally {
                this.carregandoGoogle = false;
            }
        },

        async atualizarSobrenome() {
            if (!confirm('Isso vai substituir o Sobrenome de todos os contatos no Google pelo ID do banco. Continuar?')) return;
            this.carregandoSobrenome = true;
            this.resultadoSobrenome = null;
            this.erro = null;

            try {
                const res = await fetch('/api/painel/contatos/atualizar-google-sobrenome', {
                    method: 'POST',
                    headers: {
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                        'Accept': 'application/json',
                    },
                });

                const data = await res.json();

                if (res.ok) {
                    this.resultadoSobrenome = data;
                } else {
                    this.erro = data.erro || 'Erro ao atualizar o Google.';
                }
            } catch (e) {
                this.erro = 'Erro de conexão.';
            } finally {
                this.carregandoSobrenome = false;
            }
        },

        aoEscolher(e) {
            this.arquivo = e.target.files[0] || null;
            this.resultadoCsv = null;
        },

        aoSoltar(e) {
            const f = e.dataTransfer.files[0];
            if (f && (f.name.endsWith('.csv') || f.name.endsWith('.txt'))) {
                this.arquivo = f;
                this.resultadoCsv = null;
            }
        },

        async sincronizarCsv() {
            if (!this.arquivo) return;
            this.carregandoCsv = true;

            const form = new FormData();
            form.append('arquivo', this.arquivo);
            form.append('_token', document.querySelector('meta[name="csrf-token"]').content);

            try {
                const res = await fetch('/api/painel/contatos/importar', {
                    method: 'POST',
                    body: form,
                });

                const data = await res.json();

                if (res.ok) {
                    this.resultadoCsv = data;
                } else {
                    this.erro = data.message || data.erro || 'Erro ao importar CSV.';
                }
            } catch (e) {
                this.erro = 'Erro de conexão.';
            } finally {
                this.carregandoCsv = false;
            }
        },
    };
}
</script>
@endsection
