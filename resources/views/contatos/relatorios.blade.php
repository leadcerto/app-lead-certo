@extends('layouts.app')

@section('title', 'Relatório de Novos Leads — Lead Certo')

@section('content')
<div class="p-6 max-w-7xl mx-auto space-y-6">

    {{-- Header --}}
    <div class="flex items-center justify-between flex-wrap gap-4">
        <div>
            <h1 class="text-2xl font-bold text-gray-800">Relatório de Novos Leads</h1>
            <p class="text-xs text-gray-500 mt-0.5">Acompanhamento e evolução de novos contatos recebidos por período</p>
        </div>

        {{-- Filtros de Período Rápido --}}
        <div class="flex items-center gap-1.5 bg-white p-1.5 rounded-2xl border border-gray-200 shadow-sm flex-wrap text-xs font-bold">
            <a href="?periodo=hoje"
               class="px-3 py-1.5 rounded-xl transition {{ $periodo === 'hoje' ? 'bg-green-600 text-white shadow-sm' : 'text-gray-600 hover:bg-gray-100' }}">
                Hoje
            </a>
            <a href="?periodo=ontem"
               class="px-3 py-1.5 rounded-xl transition {{ $periodo === 'ontem' ? 'bg-green-600 text-white shadow-sm' : 'text-gray-600 hover:bg-gray-100' }}">
                Ontem
            </a>
            <a href="?periodo=ultimos_7"
               class="px-3 py-1.5 rounded-xl transition {{ $periodo === 'ultimos_7' ? 'bg-green-600 text-white shadow-sm' : 'text-gray-600 hover:bg-gray-100' }}">
                7 Dias
            </a>
            <a href="?periodo=ultimos_15"
               class="px-3 py-1.5 rounded-xl transition {{ $periodo === 'ultimos_15' ? 'bg-green-600 text-white shadow-sm' : 'text-gray-600 hover:bg-gray-100' }}">
                15 Dias
            </a>
            <a href="?periodo=ultimos_30"
               class="px-3 py-1.5 rounded-xl transition {{ $periodo === 'ultimos_30' ? 'bg-green-600 text-white shadow-sm' : 'text-gray-600 hover:bg-gray-100' }}">
                30 Dias
            </a>
            <a href="?periodo=mes_atual"
               class="px-3 py-1.5 rounded-xl transition {{ $periodo === 'mes_atual' ? 'bg-green-600 text-white shadow-sm' : 'text-gray-600 hover:bg-gray-100' }}">
                Este Mês
            </a>
            <a href="?periodo=mes_anterior"
               class="px-3 py-1.5 rounded-xl transition {{ $periodo === 'mes_anterior' ? 'bg-green-600 text-white shadow-sm' : 'text-gray-600 hover:bg-gray-100' }}">
                Mês Anterior
            </a>
        </div>
    </div>

    {{-- Filtro Personalizado com Datas --}}
    <form method="GET" action="{{ route('contatos.relatorios') }}" class="bg-white rounded-2xl p-4 border border-gray-200 shadow-sm flex items-center gap-3 flex-wrap text-xs">
        <input type="hidden" name="periodo" value="personalizado">
        <span class="font-bold text-gray-700 flex items-center gap-1.5">
            <svg class="w-4 h-4 text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/>
            </svg>
            Filtrar Intervalo Personalizado:
        </span>
        <div class="flex items-center gap-2">
            <label class="text-gray-500 font-medium">De:</label>
            <input type="date" name="data_inicio" value="{{ $data_inicio }}"
                   class="border border-gray-300 rounded-xl px-3 py-1.5 font-semibold text-gray-800 focus:ring-2 focus:ring-green-500">
        </div>
        <div class="flex items-center gap-2">
            <label class="text-gray-500 font-medium">Até:</label>
            <input type="date" name="data_fim" value="{{ $data_fim }}"
                   class="border border-gray-300 rounded-xl px-3 py-1.5 font-semibold text-gray-800 focus:ring-2 focus:ring-green-500">
        </div>
        <button type="submit" class="px-4 py-1.5 bg-gray-900 hover:bg-black text-white rounded-xl font-bold transition shadow-sm">
            Aplicar Filtro
        </button>
        <span class="text-gray-400 ml-auto">Exibindo período de <strong>{{ \Carbon\Carbon::parse($data_inicio)->format('d/m/Y') }}</strong> até <strong>{{ \Carbon\Carbon::parse($data_fim)->format('d/m/Y') }}</strong> ({{ $diasCount }} dias)</span>
    </form>

    {{-- Cards de Resumo --}}
    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
        <div class="bg-gradient-to-br from-green-600 to-green-700 text-white rounded-2xl p-5 shadow-sm">
            <div class="flex items-center justify-between">
                <span class="text-xs font-bold uppercase tracking-wider text-green-100">Novos Leads no Período</span>
                <span class="text-2xl">📥</span>
            </div>
            <div class="text-3xl font-extrabold mt-2">{{ number_format($totalPeriodo, 0, ',', '.') }}</div>
            <p class="text-xs text-green-100 mt-1">Total de contatos cadastrados no intervalo</p>
        </div>

        <div class="bg-white rounded-2xl p-5 border border-gray-200 shadow-sm">
            <div class="flex items-center justify-between">
                <span class="text-xs font-bold uppercase tracking-wider text-gray-500">Média Diária</span>
                <span class="text-2xl">📈</span>
            </div>
            <div class="text-3xl font-extrabold text-gray-800 mt-2">{{ $mediaDia }} <span class="text-sm font-semibold text-gray-400">leads/dia</span></div>
            <p class="text-xs text-gray-400 mt-1">Ritmo médio de entrada no período</p>
        </div>

        <div class="bg-white rounded-2xl p-5 border border-gray-200 shadow-sm">
            <div class="flex items-center justify-between">
                <span class="text-xs font-bold uppercase tracking-wider text-gray-500">Canais de Entrada</span>
                <span class="text-2xl">🌐</span>
            </div>
            <div class="flex items-center gap-2 mt-2 flex-wrap">
                @forelse($origens as $origem)
                    <span class="px-2.5 py-1 rounded-xl text-xs font-bold bg-gray-100 text-gray-800 border border-gray-200">
                        {{ ucfirst($origem->canal) }}: {{ $origem->total }}
                    </span>
                @empty
                    <span class="text-xs text-gray-400">Nenhum canal registrado</span>
                @endforelse
            </div>
            <p class="text-xs text-gray-400 mt-2">Distribuição de origem dos contatos</p>
        </div>
    </div>

    {{-- Gráfico Diário de Evolução --}}
    <div class="bg-white rounded-2xl p-6 border border-gray-200 shadow-sm space-y-4">
        <h3 class="text-sm font-bold text-gray-800 uppercase tracking-wider flex items-center gap-2">
            <span>📊 Evolução Diária de Novos Leads</span>
        </h3>

        @if($evolucaoDiaria->isEmpty())
            <p class="text-xs text-gray-400 text-center py-6">Nenhum novo lead registrado nos dias deste período.</p>
        @else
            @php
                $maxVal = max(1, $evolucaoDiaria->max());
            @endphp
            <div class="flex items-end gap-2 h-40 pt-4 overflow-x-auto">
                @foreach($evolucaoDiaria as $data => $qtd)
                    @php
                        $alturaPct = round(($qtd / $maxVal) * 100);
                        $dataFormatada = \Carbon\Carbon::parse($data)->format('d/m');
                    @endphp
                    <div class="flex flex-col items-center flex-1 min-w-[32px] group relative">
                        <div class="absolute -top-7 opacity-0 group-hover:opacity-100 transition-opacity bg-gray-900 text-white text-[10px] font-bold py-1 px-2 rounded-lg shadow pointer-events-none whitespace-nowrap z-10">
                            {{ $qtd }} leads em {{ $dataFormatada }}
                        </div>
                        <div class="text-[10px] font-bold text-gray-600 mb-1">{{ $qtd }}</div>
                        <div class="w-full bg-green-500 hover:bg-green-600 transition-all rounded-t-lg" style="height: {{ max(6, $alturaPct) }}%;"></div>
                        <div class="text-[10px] font-semibold text-gray-400 mt-2 whitespace-nowrap">{{ $dataFormatada }}</div>
                    </div>
                @endforeach
            </div>
        @endif
    </div>

    {{-- Tabela de Leads Recebidos no Período --}}
    <div class="bg-white rounded-2xl border border-gray-200 shadow-sm overflow-hidden space-y-3">
        <div class="p-4 border-b border-gray-100 flex items-center justify-between">
            <h3 class="text-sm font-bold text-gray-800">Lista de Contatos Recebidos ({{ $leads->total() }})</h3>
            <span class="text-xs text-gray-400">Ordenado pelos mais recentes</span>
        </div>

        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-gray-50 border-b border-gray-200">
                    <tr>
                        <th class="text-left px-4 py-3 text-xs text-gray-500 font-bold uppercase tracking-wider">Data / Hora</th>
                        <th class="text-left px-4 py-3 text-xs text-gray-500 font-bold uppercase tracking-wider">Nome</th>
                        <th class="text-left px-4 py-3 text-xs text-gray-500 font-bold uppercase tracking-wider">Telefone (DDI / País)</th>
                        <th class="text-left px-4 py-3 text-xs text-gray-500 font-bold uppercase tracking-wider">Origem</th>
                        <th class="text-left px-4 py-3 text-xs text-gray-500 font-bold uppercase tracking-wider">Tipo</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse($leads as $lead)
                        <tr class="hover:bg-gray-50/80 transition-colors">
                            <td class="px-4 py-3 font-mono text-xs text-gray-500">
                                {{ $lead->created_at?->format('d/m/Y H:i') ?? '—' }}
                            </td>
                            <td class="px-4 py-3 font-bold text-gray-800">
                                {{ $lead->nome ?: 'Sem Nome' }}
                            </td>
                            <td class="px-4 py-3">
                                <div class="inline-flex items-center gap-1.5 px-3 py-1 rounded-xl text-xs font-bold bg-gray-100 text-gray-800 border border-gray-200">
                                    <span class="text-base leading-none">{{ $lead->bandeira }}</span>
                                    <span>{{ $lead->telefone_formatado }}</span>
                                </div>
                            </td>
                            <td class="px-4 py-3 text-xs text-gray-600">
                                <span class="px-2.5 py-0.5 rounded-full font-semibold bg-gray-100 text-gray-700">
                                    {{ ucfirst($lead->origem ?: 'whatsapp') }}
                                </span>
                            </td>
                            <td class="px-4 py-3 text-xs font-semibold text-gray-500">
                                {{ ucfirst($lead->tipo_contato ?: 'lead') }}
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="py-12 text-center text-gray-400 text-sm">
                                Nenhum novo lead registrado no período selecionado.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="px-4 py-3 border-t border-gray-100">
            {{ $leads->links() }}
        </div>
    </div>

</div>
@endsection
