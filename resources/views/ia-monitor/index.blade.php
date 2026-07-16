@extends('layouts.app')

@section('title', 'Uso de IA')

@section('content')
<div x-data="iaMonitor()" x-init="carregar()">
    <h1 class="text-xl font-bold text-gray-800 mb-1">Uso de IA</h1>
    <p class="text-sm text-gray-500 mb-5">Chamadas ao OpenRouter por modelo, tier e dia.</p>

    <div class="grid grid-cols-2 gap-4 mb-6 max-w-md">
        <div class="bg-white rounded-xl border border-gray-200 p-4">
            <p class="text-xs text-gray-400 uppercase tracking-wide mb-1">Hoje</p>
            <p class="text-2xl font-bold text-gray-800" x-text="totalHoje"></p>
        </div>
        <div class="bg-white rounded-xl border border-gray-200 p-4">
            <p class="text-xs text-gray-400 uppercase tracking-wide mb-1">Últimos 7 dias</p>
            <p class="text-2xl font-bold text-gray-800" x-text="total7Dias"></p>
        </div>
    </div>

    <template x-if="!carregando && linhas.length === 0">
        <div class="py-16 text-center text-gray-400">
            <p class="text-sm">Nenhuma chamada de IA registrada nos últimos 30 dias.</p>
        </div>
    </template>

    <template x-if="linhas.length > 0">
        <div class="bg-white rounded-xl border border-gray-200 overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-gray-100 text-left text-xs text-gray-400 uppercase tracking-wide">
                        <th class="px-4 py-2">Dia</th>
                        <th class="px-4 py-2">Modelo</th>
                        <th class="px-4 py-2">Tier</th>
                        <th class="px-4 py-2 text-right">Chamadas</th>
                        <th class="px-4 py-2 text-right">Tokens entrada</th>
                        <th class="px-4 py-2 text-right">Tokens saída</th>
                        <th class="px-4 py-2 text-right">Latência média</th>
                    </tr>
                </thead>
                <tbody>
                    <template x-for="(linha, idx) in linhas" :key="idx">
                        <tr class="border-b border-gray-50 last:border-0 hover:bg-gray-50">
                            <td class="px-4 py-2 text-gray-600" x-text="formatarData(linha.dia)"></td>
                            <td class="px-4 py-2 text-gray-800 font-medium" x-text="linha.modelo"></td>
                            <td class="px-4 py-2">
                                <span class="text-xs px-2 py-0.5 rounded-full"
                                      :class="{
                                          'bg-blue-100 text-blue-600':   linha.tier === 'simples',
                                          'bg-purple-100 text-purple-600': linha.tier === 'complexo',
                                          'bg-gray-100 text-gray-500':   !['simples','complexo'].includes(linha.tier),
                                      }"
                                      x-text="linha.tier"></span>
                            </td>
                            <td class="px-4 py-2 text-right text-gray-700" x-text="linha.chamadas"></td>
                            <td class="px-4 py-2 text-right text-gray-400" x-text="linha.tokens_input"></td>
                            <td class="px-4 py-2 text-right text-gray-400" x-text="linha.tokens_output"></td>
                            <td class="px-4 py-2 text-right text-gray-400" x-text="linha.latencia_media_ms + ' ms'"></td>
                        </tr>
                    </template>
                </tbody>
            </table>
        </div>
    </template>
</div>

<script>
function iaMonitor() {
    return {
        linhas:      [],
        totalHoje:   0,
        total7Dias:  0,
        carregando:  true,
        async carregar() {
            this.carregando = true;
            const res = await fetch('/api/painel/ia-monitor');
            const json = await res.json();
            this.linhas     = json.data;
            this.totalHoje  = json.total_hoje;
            this.total7Dias = json.total_7_dias;
            this.carregando = false;
        },
        formatarData(dia) {
            return new Date(dia + 'T00:00:00').toLocaleDateString('pt-BR');
        },
    };
}
</script>
@endsection
