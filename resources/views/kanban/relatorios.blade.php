@extends('layouts.app')

@section('title', 'Relatórios do Gestor do Kanban')

@section('content')
<div x-data="gestorKanbanRelatorios()" x-init="carregar()">
    <h1 class="text-xl font-bold text-gray-800 mb-1">Relatórios Semanais — Gestor do Kanban</h1>
    <p class="text-sm text-gray-500 mb-5">Gerado todo sábado à meia-noite, analisando os últimos 7 dias.</p>

    <template x-if="relatorios.length === 0">
        <div class="py-16 text-center text-gray-400">
            <p class="text-sm">Nenhum relatório ainda. O primeiro sai no próximo sábado à meia-noite.</p>
        </div>
    </template>

    <div class="space-y-3">
        <template x-for="r in relatorios" :key="r.id">
            <div class="bg-white rounded-xl border border-gray-200 overflow-hidden">
                <button @click="toggle(r.id)" class="w-full flex items-center justify-between px-5 py-3 hover:bg-gray-50 transition-colors">
                    <span class="text-sm font-medium text-gray-800"
                          x-text="'Semana de ' + formatarData(r.semana_inicio) + ' a ' + formatarData(r.semana_fim)"></span>
                    <svg class="w-4 h-4 text-gray-400 transition-transform duration-200" :class="aberto === r.id ? 'rotate-180' : ''"
                         fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
                    </svg>
                </button>
                <template x-if="aberto === r.id">
                    <div class="px-5 pb-5 border-t border-gray-100 pt-4 space-y-4">
                        <div class="bg-blue-50 border border-blue-100 rounded-lg px-4 py-3">
                            <p class="text-xs font-semibold text-blue-700 uppercase tracking-wide mb-1">Síntese da semana</p>
                            <p class="text-sm text-gray-700 whitespace-pre-wrap" x-text="r.sintese_geral || '—'"></p>
                        </div>

                        <template x-for="(dadosColuna, coluna) in r.dados" :key="coluna">
                            <div class="border border-gray-100 rounded-lg p-4">
                                <div class="flex items-center gap-3 mb-2">
                                    <span class="text-sm font-semibold text-gray-800" x-text="coluna"></span>
                                    <span class="text-xs text-gray-400"
                                          x-text="'Entradas: ' + dadosColuna.entradas + ' · Avanços: ' + dadosColuna.avancos + ' · Travados: ' + dadosColuna.travados"></span>
                                </div>
                                <p class="text-sm text-gray-600 whitespace-pre-wrap mb-2" x-text="dadosColuna.analise"></p>
                                <template x-if="dadosColuna.sugestao_prompt">
                                    <div class="bg-gray-50 rounded-lg p-3">
                                        <div class="flex items-center justify-between mb-1">
                                            <p class="text-xs font-medium text-gray-500 uppercase tracking-wide">Sugestão de ajuste de prompt</p>
                                            <button @click="copiar(dadosColuna.sugestao_prompt, coluna)"
                                                    class="text-xs text-blue-600 hover:text-blue-800 font-medium">
                                                <span x-text="copiado === coluna ? 'Copiado!' : 'Copiar'"></span>
                                            </button>
                                        </div>
                                        <p class="text-xs font-mono text-gray-700 whitespace-pre-wrap" x-text="dadosColuna.sugestao_prompt"></p>
                                    </div>
                                </template>
                            </div>
                        </template>
                    </div>
                </template>
            </div>
        </template>
    </div>
</div>

<script>
function gestorKanbanRelatorios() {
    return {
        relatorios: [],
        aberto: null,
        copiado: null,
        async carregar() {
            const res = await fetch('/api/painel/kanban/relatorios');
            const json = await res.json();
            this.relatorios = json.data;
        },
        toggle(id) {
            this.aberto = this.aberto === id ? null : id;
        },
        formatarData(data) {
            // O backend serializa semana_inicio/semana_fim como datetime ISO
            // ("2026-07-06T00:00:00.000000Z"); usamos só a parte da data para
            // montar meia-noite local e evitar "Invalid Date"/rollback de fuso.
            return new Date(data.slice(0, 10) + 'T00:00:00').toLocaleDateString('pt-BR');
        },
        async copiar(texto, coluna) {
            await navigator.clipboard.writeText(texto);
            this.copiado = coluna;
            setTimeout(() => this.copiado = null, 1500);
        },
    };
}
</script>
@endsection
