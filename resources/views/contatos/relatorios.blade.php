@extends('layouts.app')

@section('title', 'Relatório de Novos Leads — Lead Certo')

@section('content')
<div class="p-6 max-w-7xl mx-auto space-y-6">

    {{-- Toolbar Unificada: Header + Filtros de Período e Intervalo de Datas --}}
    <div class="bg-white rounded-3xl border-2 border-gray-200 shadow-md p-6 space-y-5">
        <div class="flex items-center justify-between flex-wrap gap-4 border-b border-gray-100 pb-4">
            <div>
                <div class="flex items-center gap-2">
                    <span class="text-2xl">📊</span>
                    <h1 class="text-2xl font-black text-gray-900 tracking-tight">Relatório de Novos Leads</h1>
                </div>
                <p class="text-xs font-medium text-gray-500 mt-1">Acompanhamento e evolução diária de novos contatos recebidos por período</p>
            </div>

            {{-- Badge do Período Ativo --}}
            <div class="inline-flex items-center gap-2 px-4 py-2 rounded-2xl bg-emerald-50 border border-emerald-200 text-emerald-900 text-xs font-bold shadow-sm">
                <span>🗓️ Período:</span>
                <span class="font-black text-emerald-700">{{ \Carbon\Carbon::parse($data_inicio)->format('d/m/Y') }}</span>
                <span class="text-gray-400">até</span>
                <span class="font-black text-emerald-700">{{ \Carbon\Carbon::parse($data_fim)->format('d/m/Y') }}</span>
                <span class="bg-emerald-200/80 text-emerald-950 px-2 py-0.5 rounded-lg text-[11px] font-black">
                    {{ $diasCount }} {{ $diasCount == 1 ? 'dia' : 'dias' }}
                </span>
            </div>
        </div>

        {{-- Filtros Rápidos + Seletor de Data Customizada --}}
        <div class="flex items-center justify-between flex-wrap gap-4 pt-1">
            {{-- Botões de Período Rápido --}}
            <div class="flex items-center gap-1.5 bg-gray-100 p-1.5 rounded-2xl border border-gray-200 flex-wrap text-xs font-bold shadow-inner">
                @php
                    $botoes = [
                        'hoje'          => 'Hoje',
                        'ontem'         => 'Ontem',
                        'ultimos_7'     => '7 Dias',
                        'ultimos_15'    => '15 Dias',
                        'ultimos_30'    => '30 Dias',
                        'mes_atual'     => 'Este Mês',
                        'mes_anterior'  => 'Mês Anterior',
                    ];
                @endphp
                @foreach($botoes as $chave => $label)
                    <a href="?periodo={{ $chave }}"
                       class="px-3.5 py-2 rounded-xl transition-all font-bold {{ $periodo === $chave ? 'bg-emerald-600 text-white shadow-md font-black scale-105' : 'text-gray-700 hover:bg-white hover:text-gray-900' }}"
                       style="{{ $periodo === $chave ? 'background-color: #059669 !important; color: #ffffff !important;' : '' }}">
                        {{ $label }}
                    </a>
                @endforeach
            </div>

            {{-- Formulário de Data Customizada (Visual Clean e Elegante) --}}
            <form method="GET" action="{{ route('contatos.relatorios') }}" class="flex items-center gap-2 flex-wrap text-xs">
                <input type="hidden" name="periodo" value="personalizado">

                <div class="flex items-center gap-2 bg-gray-50 border-2 border-gray-200 px-3 py-1.5 rounded-2xl shadow-sm focus-within:border-emerald-500 focus-within:bg-white transition">
                    <span class="text-[11px] font-bold text-gray-500 uppercase tracking-wider">De</span>
                    <input type="date" name="data_inicio" value="{{ $data_inicio }}"
                           class="bg-transparent border-0 font-bold text-gray-900 focus:ring-0 focus:outline-none text-xs cursor-pointer">
                </div>

                <div class="flex items-center gap-2 bg-gray-50 border-2 border-gray-200 px-3 py-1.5 rounded-2xl shadow-sm focus-within:border-emerald-500 focus-within:bg-white transition">
                    <span class="text-[11px] font-bold text-gray-500 uppercase tracking-wider">Até</span>
                    <input type="date" name="data_fim" value="{{ $data_fim }}"
                           class="bg-transparent border-0 font-bold text-gray-900 focus:ring-0 focus:outline-none text-xs cursor-pointer">
                </div>

                <button type="submit" 
                        class="px-4 py-2 bg-emerald-600 hover:bg-emerald-700 text-white font-bold rounded-2xl transition shadow-md flex items-center gap-1.5 text-xs active:scale-95"
                        style="background-color: #059669; color: #ffffff;">
                    <span>⚡ Filtrar</span>
                </button>
            </form>
        </div>
    </div>

    {{-- Cards de Resumo com Cores Vivas, Alto Contraste e Estilos Inline Imunes a Purge --}}
    <div class="grid grid-cols-1 md:grid-cols-3 gap-5">
        
        {{-- Card 1: Total de Leads (Gradiente Verde Escuro Vibrante e Texto Branco Puro) --}}
        <div class="rounded-3xl p-6 shadow-xl relative overflow-hidden flex flex-col justify-between"
             style="background: linear-gradient(135deg, #047857 0%, #065f46 50%, #064e3b 100%); color: #ffffff; border: 2px solid #059669;">
            <div class="absolute -right-4 -bottom-4 w-32 h-32 rounded-full pointer-events-none"
                 style="background: rgba(255, 255, 255, 0.12); filter: blur(24px);"></div>
            
            <div class="flex items-center justify-between relative z-10">
                <span style="color: #a7f3d0; font-size: 0.75rem; font-weight: 900; text-transform: uppercase; letter-spacing: 0.08em;">
                    Novos Leads no Período
                </span>
                <span class="text-2xl p-2.5 rounded-2xl" style="background: rgba(255, 255, 255, 0.2);">📥</span>
            </div>

            <div class="relative z-10 mt-3">
                <div style="font-size: 3rem; font-weight: 900; color: #ffffff; line-height: 1; letter-spacing: -0.03em;">
                    {{ number_format($totalPeriodo, 0, ',', '.') }}
                </div>
                <p style="color: #ecfdf5; font-size: 0.8rem; font-weight: 600; margin-top: 0.5rem;">
                    Total de novos contatos cadastrados
                </p>
            </div>
        </div>

        {{-- Card 2: Média Diária --}}
        <div class="bg-white rounded-3xl p-6 border-2 border-gray-200 shadow-md flex flex-col justify-between">
            <div class="flex items-center justify-between">
                <span class="text-xs font-black uppercase tracking-wider text-gray-500">Média Diária de Entrada</span>
                <span class="text-2xl bg-blue-50 text-blue-600 p-2.5 rounded-2xl border border-blue-100">📈</span>
            </div>
            <div class="mt-3">
                <div class="flex items-baseline gap-2">
                    <span class="text-4xl font-black text-gray-900 tracking-tight">{{ $mediaDia }}</span>
                    <span class="text-xs font-bold text-emerald-700 bg-emerald-50 px-2.5 py-1 rounded-lg border border-emerald-200">
                        leads/dia
                    </span>
                </div>
                <p class="text-xs text-gray-500 font-medium mt-2">Ritmo médio de novos contatos por dia</p>
            </div>
        </div>

        {{-- Card 3: Canais de Entrada com Badges Coloridas --}}
        <div class="bg-white rounded-3xl p-6 border-2 border-gray-200 shadow-md flex flex-col justify-between">
            <div class="flex items-center justify-between">
                <span class="text-xs font-black uppercase tracking-wider text-gray-500">Canais de Entrada</span>
                <span class="text-2xl bg-purple-50 text-purple-600 p-2.5 rounded-2xl border border-purple-100">🌐</span>
            </div>
            <div class="mt-3">
                <div class="flex items-center gap-2 flex-wrap">
                    @forelse($origens as $origem)
                        @php
                            $canalNome = strtolower($origem->canal);
                            $badgeStyle = 'background: #f3f4f6; color: #1f2937; border: 1px solid #d1d5db;';
                            $icone = '📌';
                            if (str_contains($canalNome, 'whats')) {
                                $badgeStyle = 'background: #ecfdf5; color: #065f46; border: 1px solid #a7f3d0;';
                                $icone = '💬 WhatsApp:';
                            } elseif (str_contains($canalNome, 'google')) {
                                $badgeStyle = 'background: #eff6ff; color: #1e40af; border: 1px solid #bfdbfe;';
                                $icone = '🔍 Google:';
                            } elseif (str_contains($canalNome, 'liga') || str_contains($canalNome, 'chamada')) {
                                $badgeStyle = 'background: #faf5ff; color: #6b21a8; border: 1px solid #e9d5ff;';
                                $icone = '📞 Ligação:';
                            } elseif (str_contains($canalNome, 'form') || str_contains($canalNome, 'site')) {
                                $badgeStyle = 'background: #fffbeb; color: #92400e; border: 1px solid #fde68a;';
                                $icone = '📝 Site:';
                            }
                        @endphp
                        <span class="px-3 py-1.5 rounded-xl text-xs font-black shadow-sm flex items-center gap-1.5" style="{{ $badgeStyle }}">
                            <span>{{ $icone }}</span>
                            <span class="text-sm font-black">{{ $origem->total }}</span>
                        </span>
                    @empty
                        <span class="text-xs text-gray-400 font-medium">Nenhum canal registrado</span>
                    @endforelse
                </div>
                <p class="text-xs text-gray-500 font-medium mt-2">Distribuição por canal de captação</p>
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
                            <div class="text-xs font-black mb-1.5 transition-colors group-hover:text-emerald-600" style="color: #111827;">
                                {{ $qtd }}
                            </div>

                            {{-- Trilho de fundo cinza com a barra preenchida em gradiente verde vivo --}}
                            <div class="w-full rounded-2xl flex items-end overflow-hidden p-1 transition-colors h-44 shadow-inner" style="background-color: #f3f4f6;">
                                <div class="w-full rounded-xl transition-all shadow-sm"
                                     style="height: {{ $alturaPct }}%; background: linear-gradient(180deg, #10b981 0%, #047857 100%);"></div>
                            </div>

                            {{-- Data abaixo da barra --}}
                            <div class="text-[11px] font-black mt-2 whitespace-nowrap" style="color: #4b5563;">
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
