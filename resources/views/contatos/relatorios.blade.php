@extends('layouts.app')

@section('title', 'Relatório de Novos Leads — Lead Certo')

@section('content')
<div class="p-6 max-w-7xl mx-auto space-y-6">

    {{-- Header --}}
    <div class="flex items-center justify-between flex-wrap gap-4 bg-white p-5 rounded-2xl border border-gray-200 shadow-sm">
        <div>
            <div class="flex items-center gap-2">
                <span class="text-2xl">📊</span>
                <h1 class="text-2xl font-black text-gray-900 tracking-tight">Relatório de Novos Leads</h1>
            </div>
            <p class="text-xs font-medium text-gray-500 mt-1">Acompanhamento e evolução diária de contatos recebidos por período</p>
        </div>

        {{-- Filtros de Período Rápido com Alto Contraste --}}
        <div class="flex items-center gap-1.5 bg-gray-100 p-1.5 rounded-2xl border border-gray-300 shadow-inner flex-wrap text-xs font-bold">
            <a href="?periodo=hoje"
               class="px-3.5 py-2 rounded-xl transition-all {{ $periodo === 'hoje' ? 'bg-emerald-600 text-white shadow-md font-black scale-105' : 'text-gray-700 hover:bg-white hover:text-gray-900' }}">
                Hoje
            </a>
            <a href="?periodo=ontem"
               class="px-3.5 py-2 rounded-xl transition-all {{ $periodo === 'ontem' ? 'bg-emerald-600 text-white shadow-md font-black scale-105' : 'text-gray-700 hover:bg-white hover:text-gray-900' }}">
                Ontem
            </a>
            <a href="?periodo=ultimos_7"
               class="px-3.5 py-2 rounded-xl transition-all {{ $periodo === 'ultimos_7' ? 'bg-emerald-600 text-white shadow-md font-black scale-105' : 'text-gray-700 hover:bg-white hover:text-gray-900' }}">
                7 Dias
            </a>
            <a href="?periodo=ultimos_15"
               class="px-3.5 py-2 rounded-xl transition-all {{ $periodo === 'ultimos_15' ? 'bg-emerald-600 text-white shadow-md font-black scale-105' : 'text-gray-700 hover:bg-white hover:text-gray-900' }}">
                15 Dias
            </a>
            <a href="?periodo=ultimos_30"
               class="px-3.5 py-2 rounded-xl transition-all {{ $periodo === 'ultimos_30' ? 'bg-emerald-600 text-white shadow-md font-black scale-105' : 'text-gray-700 hover:bg-white hover:text-gray-900' }}">
                30 Dias
            </a>
            <a href="?periodo=mes_atual"
               class="px-3.5 py-2 rounded-xl transition-all {{ $periodo === 'mes_atual' ? 'bg-emerald-600 text-white shadow-md font-black scale-105' : 'text-gray-700 hover:bg-white hover:text-gray-900' }}">
                Este Mês
            </a>
            <a href="?periodo=mes_anterior"
               class="px-3.5 py-2 rounded-xl transition-all {{ $periodo === 'mes_anterior' ? 'bg-emerald-600 text-white shadow-md font-black scale-105' : 'text-gray-700 hover:bg-white hover:text-gray-900' }}">
                Mês Anterior
            </a>
        </div>
    </div>

    {{-- Filtro Personalizado com Datas e Destaque --}}
    <form method="GET" action="{{ route('contatos.relatorios') }}" 
          class="bg-gradient-to-r from-gray-900 to-gray-800 text-white rounded-2xl p-4 shadow-md flex items-center gap-4 flex-wrap text-xs">
        <input type="hidden" name="periodo" value="personalizado">
        
        <span class="font-bold text-gray-200 flex items-center gap-2 text-sm">
            <svg class="w-4 h-4 text-emerald-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/>
            </svg>
            <span>Intervalo de Datas:</span>
        </span>

        <div class="flex items-center gap-2 bg-gray-800/80 px-3 py-1.5 rounded-xl border border-gray-700">
            <label class="text-gray-400 font-semibold uppercase text-[10px]">De</label>
            <input type="date" name="data_inicio" value="{{ $data_inicio }}"
                   class="bg-transparent border-0 font-bold text-white focus:ring-0 focus:outline-none text-xs">
        </div>

        <div class="flex items-center gap-2 bg-gray-800/80 px-3 py-1.5 rounded-xl border border-gray-700">
            <label class="text-gray-400 font-semibold uppercase text-[10px]">Até</label>
            <input type="date" name="data_fim" value="{{ $data_fim }}"
                   class="bg-transparent border-0 font-bold text-white focus:ring-0 focus:outline-none text-xs">
        </div>

        <button type="submit" class="px-5 py-2 bg-emerald-500 hover:bg-emerald-600 text-gray-950 font-black rounded-xl transition shadow-md flex items-center gap-1.5">
            <span>⚡ Filtrar Período</span>
        </button>

        <div class="ml-auto text-xs text-gray-300 font-medium bg-black/40 px-3 py-1.5 rounded-xl border border-gray-700">
            Período: <strong class="text-emerald-400 font-bold">{{ \Carbon\Carbon::parse($data_inicio)->format('d/m/Y') }}</strong> até <strong class="text-emerald-400 font-bold">{{ \Carbon\Carbon::parse($data_fim)->format('d/m/Y') }}</strong> <span class="text-gray-400">({{ $diasCount }} dias)</span>
        </div>
    </form>

    {{-- Cards de Resumo com Alto Contraste e Cores Vivas --}}
    <div class="grid grid-cols-1 md:grid-cols-3 gap-5">
        
        {{-- Card 1: Total de Leads --}}
        <div class="bg-gradient-to-br from-emerald-600 via-emerald-700 to-teal-800 text-white rounded-3xl p-6 shadow-lg border border-emerald-500/30 relative overflow-hidden">
            <div class="absolute -right-4 -bottom-4 w-28 h-28 bg-white/10 rounded-full blur-2xl pointer-events-none"></div>
            <div class="flex items-center justify-between">
                <span class="text-xs font-black uppercase tracking-wider text-emerald-200">Novos Leads no Período</span>
                <span class="text-3xl bg-white/20 p-2 rounded-2xl backdrop-blur-sm">📥</span>
            </div>
            <div class="text-4xl font-black mt-3 tracking-tight">{{ number_format($totalPeriodo, 0, ',', '.') }}</div>
            <p class="text-xs text-emerald-100 font-medium mt-1">Total de novos contatos cadastrados</p>
        </div>

        {{-- Card 2: Média Diária --}}
        <div class="bg-white rounded-3xl p-6 border-2 border-gray-200 shadow-md flex flex-col justify-between">
            <div class="flex items-center justify-between">
                <span class="text-xs font-black uppercase tracking-wider text-gray-500">Média Diária de Entrada</span>
                <span class="text-3xl bg-blue-50 text-blue-600 p-2 rounded-2xl border border-blue-100">📈</span>
            </div>
            <div class="mt-3">
                <div class="text-4xl font-black text-gray-900 tracking-tight">
                    {{ $mediaDia }} 
                    <span class="text-sm font-bold text-emerald-600 bg-emerald-50 px-2 py-0.5 rounded-lg border border-emerald-200">leads/dia</span>
                </div>
                <p class="text-xs text-gray-500 font-medium mt-1">Ritmo médio de novos contatos por dia</p>
            </div>
        </div>

        {{-- Card 3: Canais de Entrada com Badges Coloridas --}}
        <div class="bg-white rounded-3xl p-6 border-2 border-gray-200 shadow-md flex flex-col justify-between">
            <div class="flex items-center justify-between">
                <span class="text-xs font-black uppercase tracking-wider text-gray-500">Canais de Entrada</span>
                <span class="text-3xl bg-purple-50 text-purple-600 p-2 rounded-2xl border border-purple-100">🌐</span>
            </div>
            <div class="mt-3">
                <div class="flex items-center gap-2 flex-wrap">
                    @forelse($origens as $origem)
                        @php
                            $canalNome = strtolower($origem->canal);
                            $badgeClass = 'bg-gray-100 text-gray-800 border-gray-300';
                            $icone = '📌';
                            if (str_contains($canalNome, 'whats')) {
                                $badgeClass = 'bg-emerald-100 text-emerald-900 border-emerald-300';
                                $icone = '💬 WhatsApp:';
                            } elseif (str_contains($canalNome, 'google')) {
                                $badgeClass = 'bg-blue-100 text-blue-900 border-blue-300';
                                $icone = '🔍 Google:';
                            } elseif (str_contains($canalNome, 'liga') || str_contains($canalNome, 'chamada')) {
                                $badgeClass = 'bg-purple-100 text-purple-900 border-purple-300';
                                $icone = '📞 Ligação:';
                            } elseif (str_contains($canalNome, 'form') || str_contains($canalNome, 'site')) {
                                $badgeClass = 'bg-amber-100 text-amber-900 border-amber-300';
                                $icone = '📝 Site:';
                            }
                        @endphp
                        <span class="px-3 py-1.5 rounded-xl text-xs font-black border shadow-sm flex items-center gap-1.5 {{ $badgeClass }}">
                            <span>{{ $icone }}</span>
                            <span class="text-sm font-black">{{ $origem->total }}</span>
                        </span>
                    @empty
                        <span class="text-xs text-gray-400 font-medium">Nenhum canal registrado</span>
                    @endforelse
                </div>
                <p class="text-xs text-gray-500 font-medium mt-2">Distribuição por origem dos leads</p>
            </div>
        </div>

    </div>

    {{-- Gráfico Diário de Evolução (Barras Altas, Nítidas e Coloridas) --}}
    <div class="bg-white rounded-3xl p-6 border-2 border-gray-200 shadow-md space-y-4">
        <div class="flex items-center justify-between flex-wrap gap-2 border-b border-gray-100 pb-3">
            <div class="flex items-center gap-2">
                <span class="text-xl">📊</span>
                <h3 class="text-base font-black text-gray-900 tracking-tight">Evolução Diária de Novos Leads</h3>
            </div>
            <span class="text-xs font-bold text-gray-500 bg-gray-100 px-3 py-1 rounded-xl">Passe o mouse na barra para ver os detalhes</span>
        </div>

        @if($evolucaoDiaria->isEmpty())
            <div class="text-center py-12 text-gray-400">
                <p class="text-sm font-bold">Nenhum novo lead registrado nos dias deste período.</p>
            </div>
        @else
            @php
                $maxVal = max(1, $evolucaoDiaria->max());
            @endphp
            
            {{-- Container do Gráfico com altura fixa e trilhos de fundo --}}
            <div class="pt-8 pb-2 overflow-x-auto">
                <div class="flex items-end gap-2.5 min-w-[700px] h-64 px-2">
                    @foreach($evolucaoDiaria as $data => $qtd)
                        @php
                            $alturaPct = max(8, round(($qtd / $maxVal) * 100));
                            $dataFormatada = \Carbon\Carbon::parse($data)->format('d/m');
                            $diaSemana = \Carbon\Carbon::parse($data)->translatedFormat('D');
                        @endphp
                        
                        <div class="flex flex-col items-center flex-1 min-w-[34px] h-full justify-end group relative">
                            
                            {{-- Tooltip ao passar o mouse --}}
                            <div class="absolute -top-10 opacity-0 group-hover:opacity-100 transition-all bg-gray-950 text-white text-xs font-black py-1.5 px-3 rounded-xl shadow-xl pointer-events-none whitespace-nowrap z-20 transform -translate-y-1">
                                <span class="text-emerald-400 font-bold">{{ $qtd }} leads</span> em {{ $dataFormatada }} ({{ $diaSemana }})
                            </div>

                            {{-- Valor numérico acima da barra com alto contraste --}}
                            <div class="text-[11px] font-black text-gray-800 mb-1.5 transition-colors group-hover:text-emerald-600">
                                {{ $qtd }}
                            </div>

                            {{-- Trilho de fundo cinza com a barra preenchida em gradiente verde vivo --}}
                            <div class="w-full bg-gray-100 group-hover:bg-gray-200 rounded-2xl flex items-end overflow-hidden p-1 transition-colors h-44">
                                <div class="w-full bg-gradient-to-t from-emerald-600 to-teal-400 group-hover:from-emerald-500 group-hover:to-teal-300 rounded-xl transition-all shadow-sm"
                                     style="height: {{ $alturaPct }}%;"></div>
                            </div>

                            {{-- Data abaixo da barra --}}
                            <div class="text-[11px] font-bold text-gray-600 group-hover:text-gray-900 mt-2 whitespace-nowrap">
                                {{ $dataFormatada }}
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        @endif
    </div>

    {{-- Tabela de Leads com Contraste Forte e Legível --}}
    <div class="bg-white rounded-3xl border-2 border-gray-200 shadow-md overflow-hidden space-y-3">
        <div class="px-6 py-4 bg-gray-50 border-b border-gray-200 flex items-center justify-between flex-wrap gap-2">
            <div>
                <h3 class="text-base font-black text-gray-900">Lista Detalhada de Contatos Recebidos</h3>
                <p class="text-xs font-medium text-gray-500">Mostrando {{ $leads->total() }} contatos recebidos no período selecionado</p>
            </div>
            <span class="text-xs font-black text-emerald-700 bg-emerald-100 border border-emerald-200 px-3 py-1 rounded-xl">
                {{ $leads->total() }} contatos no total
            </span>
        </div>

        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-gray-100/90 border-b border-gray-200">
                    <tr>
                        <th class="text-left px-5 py-3.5 text-xs text-gray-700 font-black uppercase tracking-wider">Data / Hora</th>
                        <th class="text-left px-5 py-3.5 text-xs text-gray-700 font-black uppercase tracking-wider">Nome do Contato</th>
                        <th class="text-left px-5 py-3.5 text-xs text-gray-700 font-black uppercase tracking-wider">Telefone (País & DDI)</th>
                        <th class="text-left px-5 py-3.5 text-xs text-gray-700 font-black uppercase tracking-wider">Origem / Canal</th>
                        <th class="text-left px-5 py-3.5 text-xs text-gray-700 font-black uppercase tracking-wider">Tipo</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200">
                    @forelse($leads as $lead)
                        <tr class="hover:bg-emerald-50/40 transition-colors">
                            <td class="px-5 py-3.5 font-mono text-xs font-bold text-gray-600">
                                {{ $lead->created_at?->format('d/m/Y H:i') ?? '—' }}
                            </td>
                            <td class="px-5 py-3.5 font-bold text-gray-900 text-sm">
                                {{ $lead->nome ?: 'Sem Nome' }}
                            </td>
                            <td class="px-5 py-3.5">
                                <div class="inline-flex items-center gap-2 px-3 py-1.5 rounded-xl text-xs font-black bg-gray-50 text-gray-900 border-2 border-gray-200 shadow-sm">
                                    <span class="text-base leading-none">{{ $lead->bandeira ?: '🇧🇷' }}</span>
                                    <span class="font-mono">{{ $lead->telefone_formatado }}</span>
                                </div>
                            </td>
                            <td class="px-5 py-3.5 text-xs font-bold">
                                @php
                                    $origemNome = strtolower($lead->origem ?? 'whatsapp');
                                    $origemStyle = 'bg-emerald-100 text-emerald-900 border-emerald-300';
                                    if (str_contains($origemNome, 'google')) {
                                        $origemStyle = 'bg-blue-100 text-blue-900 border-blue-300';
                                    } elseif (str_contains($origemNome, 'liga') || str_contains($origemNome, 'chamada')) {
                                        $origemStyle = 'bg-purple-100 text-purple-900 border-purple-300';
                                    }
                                @endphp
                                <span class="px-3 py-1 rounded-xl border {{ $origemStyle }}">
                                    {{ ucfirst($lead->origem ?: 'WhatsApp') }}
                                </span>
                            </td>
                            <td class="px-5 py-3.5 text-xs font-bold text-gray-600">
                                <span class="px-2.5 py-0.5 rounded-lg bg-gray-100 text-gray-700">
                                    {{ ucfirst($lead->tipo_contato ?: 'Lead') }}
                                </span>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="py-16 text-center text-gray-400 font-bold text-sm">
                                Nenhum novo lead registrado no período selecionado.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="px-6 py-4 border-t border-gray-200 bg-gray-50">
            {{ $leads->links() }}
        </div>
    </div>

</div>
@endsection
