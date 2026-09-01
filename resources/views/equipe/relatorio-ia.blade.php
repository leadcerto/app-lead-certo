@extends('layouts.app')
@section('title', 'Relatório de Uso de IA — Lead Certo')

@section('content')
<div class="max-w-7xl mx-auto space-y-6">

    {{-- Header --}}
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 pb-2 border-b border-gray-100">
        <div>
            <div class="flex items-center gap-2">
                <span class="p-2 bg-purple-50 text-purple-600 rounded-xl text-lg">⚡</span>
                <h1 class="text-2xl font-bold text-gray-900 tracking-tight">Relatório de Uso e Performance de IA</h1>
            </div>
            <p class="text-sm text-gray-500 mt-1">
                Acompanhe o consumo de tokens, latência de resposta e volume de requisições por Agente e por Provedor (OpenRouter vs Gemini Direto).
            </p>
        </div>

        <div class="flex items-center gap-2">
            <a href="{{ route('equipe.agentes-ia') }}"
               class="bg-white hover:bg-gray-50 text-gray-700 border border-gray-200 text-xs font-semibold px-4 py-2.5 rounded-xl shadow-2xs transition flex items-center gap-1.5">
                <span>🤖</span> Gerenciar Agentes IA
            </a>
        </div>
    </div>

    {{-- Cards de Indicadores (KPIs) --}}
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
        {{-- Total Requisições --}}
        <div class="bg-white rounded-3xl p-5 border border-gray-200/80 shadow-2xs space-y-2">
            <div class="flex items-center justify-between">
                <span class="text-xs font-bold uppercase tracking-wider text-gray-400">Total de Chamadas</span>
                <span class="p-2 bg-purple-50 text-purple-600 rounded-xl text-sm">💬</span>
            </div>
            <div class="text-2xl font-bold text-gray-900">
                {{ number_format($totalRequisicoes, 0, ',', '.') }}
            </div>
            <p class="text-[11px] text-gray-500">Requisições processadas pelos motores de IA</p>
        </div>

        {{-- Tokens de Entrada (Prompt) --}}
        <div class="bg-white rounded-3xl p-5 border border-gray-200/80 shadow-2xs space-y-2">
            <div class="flex items-center justify-between">
                <span class="text-xs font-bold uppercase tracking-wider text-gray-400">Tokens de Entrada</span>
                <span class="p-2 bg-blue-50 text-blue-600 rounded-xl text-sm">📥</span>
            </div>
            <div class="text-2xl font-bold text-gray-900">
                {{ number_format($totalTokensInput, 0, ',', '.') }}
            </div>
            <p class="text-[11px] text-gray-500">Histórico de conversas e prompts enviados</p>
        </div>

        {{-- Tokens de Saída (Respostas) --}}
        <div class="bg-white rounded-3xl p-5 border border-gray-200/80 shadow-2xs space-y-2">
            <div class="flex items-center justify-between">
                <span class="text-xs font-bold uppercase tracking-wider text-gray-400">Tokens de Saída</span>
                <span class="p-2 bg-emerald-50 text-emerald-600 rounded-xl text-sm">📤</span>
            </div>
            <div class="text-2xl font-bold text-gray-900">
                {{ number_format($totalTokensOutput, 0, ',', '.') }}
            </div>
            <p class="text-[11px] text-gray-500">Respostas geradas pelos modelos de IA</p>
        </div>

        {{-- Latência Média --}}
        <div class="bg-white rounded-3xl p-5 border border-gray-200/80 shadow-2xs space-y-2">
            <div class="flex items-center justify-between">
                <span class="text-xs font-bold uppercase tracking-wider text-gray-400">Latência Média</span>
                <span class="p-2 bg-amber-50 text-amber-600 rounded-xl text-sm">⏱️</span>
            </div>
            <div class="text-2xl font-bold text-gray-900">
                {{ $mediaLatenciaMs > 0 ? number_format($mediaLatenciaMs / 1000, 2, ',', '.') . 's' : '0s' }}
            </div>
            <p class="text-[11px] text-gray-500">Tempo médio de resposta por chamada</p>
        </div>
    </div>

    {{-- Grid: Consumo por Provedor & Consumo por Agente --}}
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">

        {{-- Distribuição por Provedor --}}
        <div class="bg-white rounded-3xl p-6 border border-gray-200/80 shadow-2xs space-y-4">
            <h2 class="text-base font-bold text-gray-900 flex items-center gap-2">
                <span>🌐</span> Consumo por Motor / Provedor
            </h2>
            <div class="space-y-3">
                @forelse($porProvedor as $prov)
                @php
                    $isGemini = strtolower($prov->provedor) === 'gemini_direto';
                    $pct = $totalRequisicoes > 0 ? round(($prov->total / $totalRequisicoes) * 100, 1) : 0;
                @endphp
                <div class="p-4 rounded-2xl border {{ $isGemini ? 'bg-purple-50/50 border-purple-200' : 'bg-blue-50/50 border-blue-200' }} space-y-2">
                    <div class="flex items-center justify-between">
                        <div class="flex items-center gap-2">
                            <span class="text-lg">{{ $isGemini ? '✨' : '🟣' }}</span>
                            <div>
                                <h3 class="text-xs font-bold text-gray-800">
                                    {{ $isGemini ? 'Google Gemini Pro (Direto)' : 'OpenRouter (Multi-model)' }}
                                </h3>
                                <p class="text-[11px] text-gray-500">{{ number_format($prov->tokens, 0, ',', '.') }} tokens consumidos</p>
                            </div>
                        </div>
                        <div class="text-right">
                            <span class="text-sm font-bold text-gray-900">{{ number_format($prov->total, 0, ',', '.') }} chamadas</span>
                            <span class="block text-[11px] text-gray-500">{{ $pct }}% do volume</span>
                        </div>
                    </div>
                    <div class="w-full bg-white rounded-full h-2 overflow-hidden border border-gray-100">
                        <div class="h-full {{ $isGemini ? 'bg-purple-600' : 'bg-blue-600' }}" style="width: {{ $pct }}%"></div>
                    </div>
                </div>
                @empty
                <div class="p-6 text-center text-xs text-gray-400 italic">
                    Nenhum registro de uso por provedor até o momento.
                </div>
                @endforelse
            </div>
        </div>

        {{-- Volume por Agente de IA --}}
        <div class="bg-white rounded-3xl p-6 border border-gray-200/80 shadow-2xs space-y-4">
            <h2 class="text-base font-bold text-gray-900 flex items-center gap-2">
                <span>🤖</span> Volume por Agente de IA
            </h2>
            <div class="space-y-3">
                @forelse($porAgente as $ag)
                <div class="p-3.5 rounded-2xl border border-gray-100 bg-gray-50/60 flex items-center justify-between gap-3">
                    <div class="flex items-center gap-3 min-w-0">
                        <div class="w-9 h-9 rounded-xl bg-purple-100 text-purple-700 flex items-center justify-center font-bold text-sm flex-shrink-0">
                            {{ mb_substr($ag->agente_nome, 0, 1) }}
                        </div>
                        <div class="min-w-0">
                            <h3 class="text-xs font-bold text-gray-900 truncate">{{ $ag->agente_nome }}</h3>
                            <span class="inline-flex items-center gap-1 text-[10px] font-medium text-gray-500">
                                {{ $ag->provedor === 'gemini_direto' ? '✨ Gemini Pro Direto' : '🟣 OpenRouter' }}
                            </span>
                        </div>
                    </div>

                    <div class="text-right flex-shrink-0">
                        <div class="text-xs font-bold text-gray-800">{{ number_format($ag->total_chamadas, 0, ',', '.') }} chamadas</div>
                        <div class="text-[10px] text-gray-400">
                            {{ number_format(($ag->input_tokens + $ag->output_tokens), 0, ',', '.') }} tokens
                            • {{ round($ag->avg_latencia) }}ms
                        </div>
                    </div>
                </div>
                @empty
                <div class="p-6 text-center text-xs text-gray-400 italic">
                    Nenhum agente realizou chamadas ainda.
                </div>
                @endforelse
            </div>
        </div>

    </div>

    {{-- Tabela de Histórico Recente (Últimas 50 Requisições) --}}
    <div class="bg-white rounded-3xl border border-gray-200/80 shadow-2xs overflow-hidden">
        <div class="p-6 border-b border-gray-100 flex items-center justify-between">
            <div>
                <h2 class="text-base font-bold text-gray-900 flex items-center gap-2">
                    <span>📜</span> Histórico Recente de Execuções
                </h2>
                <p class="text-xs text-gray-500 mt-0.5">Últimas 50 interações processadas pelos agentes inteligentes</p>
            </div>
        </div>

        <div class="overflow-x-auto">
            <table class="w-full text-left text-xs">
                <thead class="bg-gray-50/70 border-b border-gray-100 text-gray-400 uppercase text-[10px] font-bold tracking-wider">
                    <tr>
                        <th class="py-3 px-5">Data / Hora</th>
                        <th class="py-3 px-5">Agente</th>
                        <th class="py-3 px-5">Provedor</th>
                        <th class="py-3 px-5">Modelo</th>
                        <th class="py-3 px-5">Origem</th>
                        <th class="py-3 px-5 text-right">Tokens (In / Out)</th>
                        <th class="py-3 px-5 text-right">Latência</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse($ultimosUsos as $uso)
                    <tr class="hover:bg-gray-50/60 transition">
                        <td class="py-3 px-5 text-gray-500 whitespace-nowrap">
                            {{ \Carbon\Carbon::parse($uso->created_at)->format('d/m/Y H:i:s') }}
                        </td>
                        <td class="py-3 px-5 font-semibold text-gray-800 whitespace-nowrap">
                            {{ $uso->agente_nome ?: 'Sistema Lead Certo' }}
                        </td>
                        <td class="py-3 px-5 whitespace-nowrap">
                            @if($uso->provedor === 'gemini_direto')
                                <span class="inline-flex items-center gap-1 text-[11px] font-medium text-purple-700 bg-purple-50 px-2 py-0.5 rounded-md border border-purple-200/60">
                                    <span>✨</span> Gemini Direto
                                </span>
                            @else
                                <span class="inline-flex items-center gap-1 text-[11px] font-medium text-blue-700 bg-blue-50 px-2 py-0.5 rounded-md border border-blue-200/60">
                                    <span>🟣</span> OpenRouter
                                </span>
                            @endif
                        </td>
                        <td class="py-3 px-5 text-gray-600 font-mono text-[11px]">
                            {{ $uso->modelo }}
                        </td>
                        <td class="py-3 px-5 text-gray-500 uppercase text-[10px] font-bold">
                            <span class="bg-gray-100 px-2 py-0.5 rounded-md text-gray-600">{{ $uso->origem ?: 'sdr' }}</span>
                        </td>
                        <td class="py-3 px-5 text-right font-medium text-gray-700 whitespace-nowrap">
                            {{ number_format($uso->tokens_input, 0, ',', '.') }} / {{ number_format($uso->tokens_output, 0, ',', '.') }}
                        </td>
                        <td class="py-3 px-5 text-right font-semibold text-gray-800 whitespace-nowrap">
                            {{ $uso->latencia_ms }}ms
                        </td>
                    </tr>
                    @empty
                    <tr>
                        <td colspan="7" class="py-8 text-center text-xs text-gray-400 italic">
                            Nenhuma requisição registrada até o momento.
                        </td>
                    </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

</div>
@endsection
