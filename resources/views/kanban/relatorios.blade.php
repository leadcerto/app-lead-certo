@extends('layouts.app')

@section('title', 'Relatórios do Gestor do Kanban')

@section('content')
<div x-data="gestorKanbanRelatorios()" x-init="carregar()">

    <div class="flex items-center gap-1 mb-5 border-b border-gray-200">
        <button @click="aba = 'semanais'"
                class="px-4 py-2.5 text-sm font-medium border-b-2 transition-colors"
                :class="aba === 'semanais' ? 'border-green-600 text-green-700' : 'border-transparent text-gray-400 hover:text-gray-600'">
            Relatórios Semanais
        </button>
        <button @click="aba = 'auditoria'"
                class="px-4 py-2.5 text-sm font-medium border-b-2 transition-colors flex items-center gap-1.5"
                :class="aba === 'auditoria' ? 'border-green-600 text-green-700' : 'border-transparent text-gray-400 hover:text-gray-600'">
            Auditoria
            <span x-show="auditorias.length > 0" x-text="auditorias.length"
                  class="text-[10px] bg-red-100 text-red-600 rounded-full px-1.5 py-0.5 font-semibold"></span>
        </button>
    </div>

    <template x-if="aba === 'semanais'">
    <div>
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
    </template>

    {{-- Aba Auditoria (pedido do Leonardo 24/09): fila de tickets marcados
         manualmente pra revisão de desenvolvimento — nunca a IA analisa,
         só nós (dev) — via botão "🔍 Auditoria" no detalhe do ticket. --}}
    <template x-if="aba === 'auditoria'">
    <div>
    <h1 class="text-xl font-bold text-gray-800 mb-1">Auditoria — Tickets marcados pra revisão</h1>
    <p class="text-sm text-gray-500 mb-5">Marcados manualmente no ticket quando algo parece errado — a IA nunca analisa isso, só nós.</p>

    <template x-if="auditorias.length === 0">
        <div class="py-16 text-center text-gray-400">
            <p class="text-sm">Nenhum ticket marcado pra auditoria no momento.</p>
        </div>
    </template>

    <div class="space-y-3">
        <template x-for="t in auditorias" :key="t.id">
            <div class="bg-white rounded-xl border border-gray-200 p-4">
                <div class="flex items-start justify-between gap-3">
                    <div>
                        <p class="text-sm font-semibold text-gray-800"
                           x-text="(t.contato?.nome || 'Sem nome') + ' · Ticket #' + t.id"></p>
                        <p class="text-xs text-gray-400"
                           x-text="[t.contato?.telefone, t.coluna_kanban].filter(Boolean).join(' · ')"></p>
                    </div>
                    <span class="text-xs text-gray-400 whitespace-nowrap"
                          x-text="new Date(t.revisao_dev_solicitada_em).toLocaleString('pt-BR')"></span>
                </div>
                <template x-if="t.revisao_dev_nota">
                    <p class="text-sm text-gray-700 bg-red-50 border border-red-100 rounded-lg px-3 py-2 mt-2 whitespace-pre-wrap" x-text="t.revisao_dev_nota"></p>
                </template>
                <div class="flex items-center justify-between mt-3">
                    <span class="text-xs text-gray-400" x-text="'Marcado por ' + (t.solicitante?.nome || '—')"></span>
                    <button @click="concluirAuditoria(t.id)"
                            class="text-xs bg-gray-100 hover:bg-gray-200 text-gray-600 px-3 py-1.5 rounded-lg transition-colors font-medium">
                        Marcar como revisado
                    </button>
                </div>
            </div>
        </template>
    </div>
    </div>
    </template>

</div>

<script>
function gestorKanbanRelatorios() {
    return {
        aba: 'semanais',
        relatorios: [],
        auditorias: [],
        aberto: null,
        copiado: null,
        async carregar() {
            const res = await fetch('/api/painel/kanban/relatorios');
            const json = await res.json();
            this.relatorios = json.data;
            await this.carregarAuditorias();
        },
        async carregarAuditorias() {
            const res = await fetch('/api/painel/kanban/auditorias');
            const json = await res.json();
            this.auditorias = json.data;
        },
        async concluirAuditoria(ticketId) {
            const res = await fetch(`/api/painel/kanban/ticket/${ticketId}/auditoria/concluir`, {
                method: 'POST',
                headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content },
            });
            if (res.ok) this.auditorias = this.auditorias.filter(t => t.id !== ticketId);
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
