@extends('layouts.app')

@section('title', 'Dashboard — Lead Certo')

@section('content')
<div x-data="dashboard()" x-init="carregar()" class="space-y-6">

    <div class="flex items-center justify-between">
        <h1 class="text-xl font-bold text-gray-800">Dashboard</h1>
        <select x-model="periodo" @change="carregar()"
                class="text-sm border border-gray-300 rounded-lg px-3 py-1.5 bg-white focus:outline-none focus:ring-2 focus:ring-green-500">
            <option value="hoje">Hoje</option>
            <option value="7dias">7 dias</option>
            <option value="30dias">30 dias</option>
            <option value="mes">Este mês</option>
        </select>
    </div>

    {{-- KPI cards --}}
    <div class="grid grid-cols-2 lg:grid-cols-4 gap-4">
        <div class="bg-white rounded-xl p-5 shadow-sm">
            <p class="text-xs text-gray-500 uppercase tracking-wide">Leads Recebidos</p>
            <p class="text-3xl font-bold text-gray-800 mt-1" x-text="dados.leads_recebidos ?? '—'"></p>
        </div>
        <div class="bg-white rounded-xl p-5 shadow-sm">
            <p class="text-xs text-gray-500 uppercase tracking-wide">Em Aberto</p>
            <p class="text-3xl font-bold text-yellow-600 mt-1" x-text="dados.em_aberto ?? '—'"></p>
        </div>
        <div class="bg-white rounded-xl p-5 shadow-sm">
            <p class="text-xs text-gray-500 uppercase tracking-wide">Fechados</p>
            <p class="text-3xl font-bold text-green-600 mt-1" x-text="dados.fechados ?? '—'"></p>
        </div>
        <div class="bg-white rounded-xl p-5 shadow-sm">
            <p class="text-xs text-gray-500 uppercase tracking-wide">Taxa de Conversão</p>
            <p class="text-3xl font-bold text-blue-600 mt-1"
               x-text="dados.taxa_conversao != null ? dados.taxa_conversao + '%' : '—'"></p>
        </div>
    </div>

    {{-- Alerta --}}
    <template x-if="dados.alertas && dados.alertas.sem_resposta_2h > 0">
        <div class="bg-red-50 border border-red-200 text-red-700 rounded-xl px-5 py-3 text-sm flex items-center gap-2">
            <svg class="w-4 h-4 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20">
                <path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd"/>
            </svg>
            <span x-text="`${dados.alertas.sem_resposta_2h} lead(s) aguardando resposta há mais de 2 horas.`"></span>
        </div>
    </template>

    {{-- Motivos de perda --}}
    <div class="bg-white rounded-xl p-5 shadow-sm">
        <h2 class="text-sm font-semibold text-gray-600 mb-4">Motivos de Perda</h2>
        <template x-if="dados.motivos_perda && dados.motivos_perda.length === 0">
            <p class="text-sm text-gray-400">Nenhum registro no período.</p>
        </template>
        <div class="space-y-2">
            <template x-for="m in (dados.motivos_perda || [])" :key="m.tag">
                <div class="flex items-center gap-3">
                    <span class="text-sm text-gray-600 w-40 truncate" x-text="m.tag"></span>
                    <div class="flex-1 bg-gray-100 rounded-full h-2">
                        <div class="bg-red-400 h-2 rounded-full" :style="`width: ${m.percentual}%`"></div>
                    </div>
                    <span class="text-xs text-gray-500 w-10 text-right" x-text="`${m.percentual}%`"></span>
                </div>
            </template>
        </div>
    </div>

</div>

<script>
function dashboard() {
    return {
        periodo: 'hoje',
        dados: {},

        async carregar() {
            const res = await fetch(`/api/painel/dashboard?periodo=${this.periodo}`, {
                headers: {
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                    'Accept': 'application/json',
                }
            });
            if (res.ok) this.dados = await res.json();
        }
    };
}
</script>
@endsection
