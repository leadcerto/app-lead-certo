@extends('layouts.app')

@section('title', 'Dashboard — Lead Certo')

@section('content')
<div x-data="dashboard()" x-init="carregar()" class="space-y-6">

    {{-- Cabeçalho --}}
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

    {{-- ────────────────────────────────────────────────────────── --}}
    {{-- Agenda do Dia (accordion — fechado por padrão) --}}
    {{-- ────────────────────────────────────────────────────────── --}}
    <div x-data="{ open: false }" class="bg-white rounded-xl shadow-sm overflow-hidden">
        <button @click="open = !open"
                class="w-full flex items-center justify-between px-5 py-4 text-left focus:outline-none">
            <div class="flex items-center gap-3">
                <span class="text-base font-semibold text-gray-800">Agenda do Dia</span>
                <template x-if="dados.alertas && dados.alertas.sem_resposta_2h > 0">
                    <span class="text-xs font-semibold text-white px-2 py-0.5 rounded-full"
                          style="background:#ef4444"
                          x-text="`${dados.alertas.sem_resposta_2h} urgente${dados.alertas.sem_resposta_2h > 1 ? 's' : ''}`"></span>
                </template>
            </div>
            <svg class="w-5 h-5 text-gray-400 transition-transform duration-200"
                 :class="open ? 'rotate-180' : ''"
                 fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
            </svg>
        </button>

        <div x-show="open" style="display:none" class="border-t border-gray-100 px-5 pb-5 pt-4 space-y-5">

            {{-- Leads sem resposta > 2h --}}
            <div>
                <p class="text-xs font-semibold text-gray-500 uppercase tracking-wide mb-2">Leads aguardando resposta</p>
                <div class="flex items-center gap-3">
                    <template x-if="dados.alertas && dados.alertas.sem_resposta_2h > 0">
                        <div class="flex items-center gap-2 text-sm font-medium px-3 py-2 rounded-lg"
                             style="background:#fef2f2;color:#b91c1c">
                            <svg class="w-4 h-4 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20">
                                <path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd"/>
                            </svg>
                            <span x-text="`${dados.alertas.sem_resposta_2h} lead(s) sem resposta há mais de 2 horas`"></span>
                        </div>
                    </template>
                    <template x-if="!dados.alertas || dados.alertas.sem_resposta_2h === 0">
                        <p class="text-sm text-green-600 font-medium">Todos atendidos ✓</p>
                    </template>
                </div>
            </div>

            {{-- Auditoria pendente --}}
            <div>
                <p class="text-xs font-semibold text-gray-500 uppercase tracking-wide mb-2">Auditoria de Contatos</p>
                <template x-if="dados.auditoria_pendentes > 0">
                    <div class="flex items-center gap-2 text-sm font-medium px-3 py-2 rounded-lg"
                         style="background:#fff7ed;color:#c2410c">
                        <svg class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                  d="M12 9v2m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                        </svg>
                        <span x-text="`${dados.auditoria_pendentes} contato(s) aguardando revisão`"></span>
                    </div>
                </template>
                <template x-if="!dados.auditoria_pendentes || dados.auditoria_pendentes === 0">
                    <p class="text-sm text-green-600 font-medium">Nenhuma pendência ✓</p>
                </template>
            </div>

            {{-- Kanban breakdown --}}
            <div>
                <p class="text-xs font-semibold text-gray-500 uppercase tracking-wide mb-2">Leads por etapa (Kanban)</p>
                <template x-if="!dados.kanban || Object.keys(dados.kanban).length === 0">
                    <p class="text-sm text-gray-400">Nenhum lead em aberto.</p>
                </template>
                <div class="flex flex-wrap gap-2">
                    <template x-for="[coluna, total] in Object.entries(dados.kanban || {})" :key="coluna">
                        <div class="flex items-center gap-1.5 text-sm px-3 py-1.5 rounded-lg"
                             style="background:#f3f4f6;color:#374151">
                            <span class="font-semibold" x-text="total"></span>
                            <span class="text-gray-500" x-text="formatarTag(coluna)"></span>
                        </div>
                    </template>
                </div>
            </div>

        </div>
    </div>

    {{-- ────────────────────────────────────────────────────────── --}}
    {{-- Automações da Madrugada (accordion — aberto por padrão) --}}
    {{-- ────────────────────────────────────────────────────────── --}}
    <div x-data="{ open: true }" class="bg-white rounded-xl shadow-sm overflow-hidden">
        <button @click="open = !open"
                class="w-full flex items-center justify-between px-5 py-4 text-left focus:outline-none">
            <div class="flex items-center gap-3">
                <span class="text-base font-semibold text-gray-800">Automações da Madrugada</span>
                <template x-if="automacoes.some(a => a.status === 'erro')">
                    <span class="text-xs font-semibold text-white px-2 py-0.5 rounded-full"
                          style="background:#ef4444">erro detectado</span>
                </template>
            </div>
            <svg class="w-5 h-5 text-gray-400 transition-transform duration-200"
                 :class="open ? 'rotate-180' : ''"
                 fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
            </svg>
        </button>

        <div x-show="open" class="border-t border-gray-100 px-5 pb-5 pt-4">

            <template x-if="carregandoAutomacoes">
                <p class="text-sm text-gray-400">Carregando...</p>
            </template>

            <template x-if="!carregandoAutomacoes">
                <div class="space-y-3">
                    <template x-for="a in automacoes" :key="a.nome">
                        <div x-data="{ logAberto: false }"
                             class="border border-gray-100 rounded-xl overflow-hidden">

                            {{-- Linha principal da rotina --}}
                            <button @click="logAberto = !logAberto"
                                    class="w-full flex items-center gap-3 px-4 py-3 text-left"
                                    :style="statusBg(a.status)">

                                {{-- Ícone de status --}}
                                <span class="w-2.5 h-2.5 rounded-full flex-shrink-0"
                                      :style="`background:${statusCor(a.status)}`"></span>

                                {{-- Nome + horário --}}
                                <div class="flex-1 min-w-0">
                                    <p class="text-sm font-semibold text-gray-800 truncate" x-text="a.nome"></p>
                                    <p class="text-xs text-gray-400" x-text="a.horario"></p>
                                </div>

                                {{-- Resumo --}}
                                <div class="hidden sm:block flex-1 min-w-0 text-right">
                                    <p class="text-xs text-gray-500 truncate" x-text="a.resumo"></p>
                                </div>

                                {{-- Tempo atrás --}}
                                <div class="text-right flex-shrink-0">
                                    <p class="text-xs font-medium" :style="`color:${statusCor(a.status)}`"
                                       x-text="a.tempo_atras ?? 'nunca'"></p>
                                    <p class="text-xs text-gray-400" x-text="a.quando ?? ''"></p>
                                </div>

                                {{-- Chevron --}}
                                <svg class="w-4 h-4 text-gray-300 flex-shrink-0 transition-transform duration-150"
                                     :class="logAberto ? 'rotate-180' : ''"
                                     fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
                                </svg>
                            </button>

                            {{-- Log expandido --}}
                            <div x-show="logAberto" style="display:none"
                                 class="border-t border-gray-100 bg-gray-50 px-4 py-3">
                                <template x-if="a.linhas.length === 0">
                                    <p class="text-xs text-gray-400 italic">Sem linhas de log.</p>
                                </template>
                                <pre class="text-xs text-gray-600 font-mono whitespace-pre-wrap leading-relaxed overflow-x-auto max-h-56 overflow-y-auto"
                                     x-text="a.linhas.join('\n')"></pre>
                            </div>

                        </div>
                    </template>
                </div>
            </template>
        </div>
    </div>

    {{-- Motivos de Perda --}}
    <div class="bg-white rounded-xl p-5 shadow-sm">
        <h2 class="text-sm font-semibold text-gray-600 mb-4">Motivos de Perda</h2>
        <template x-if="dados.motivos_perda && dados.motivos_perda.length === 0">
            <p class="text-sm text-gray-400">Nenhum registro no período.</p>
        </template>
        <div class="space-y-2">
            <template x-for="m in (dados.motivos_perda || [])" :key="m.tag">
                <div class="flex items-center gap-3">
                    <span class="text-sm text-gray-600 w-44 truncate flex-shrink-0"
                          x-text="formatarTag(m.tag)"></span>
                    <div class="flex-1 bg-gray-100 rounded-full h-2">
                        <div class="h-2 rounded-full" style="background:#f87171"
                             :style="`width:${m.percentual}%`"></div>
                    </div>
                    <span class="text-xs text-gray-500 w-10 text-right flex-shrink-0"
                          x-text="`${m.percentual}%`"></span>
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
        automacoes: [],
        carregandoAutomacoes: true,

        async carregar() {
            const headers = {
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                'Accept': 'application/json',
            };

            const [resDados, resAuto] = await Promise.all([
                fetch(`/api/painel/dashboard?periodo=${this.periodo}`, { headers }),
                fetch('/api/painel/dashboard/automacoes', { headers }),
            ]);

            if (resDados.ok) this.dados = await resDados.json();

            if (resAuto.ok) {
                this.automacoes = await resAuto.json();
            }
            this.carregandoAutomacoes = false;
        },

        // Converte snake_case / slug em label legível
        formatarTag(tag) {
            const mapa = {
                sem_interesse:      'Sem interesse',
                sem_resposta:       'Sem resposta',
                fora_de_area:       'Fora de área',
                preco_alto:         'Preço alto',
                concorrente:        'Concorrente',
                desistiu:           'Desistiu',
                qualificacao:       'Qualificação',
                nao_qualificado:    'Não qualificado',
                venda_fechada:      'Venda fechada',
                novo:               'Novo',
                em_atendimento:     'Em atendimento',
                aguardando:         'Aguardando',
                proposta_enviada:   'Proposta enviada',
                negociacao:         'Negociação',
                fechado:            'Fechado',
            };
            if (mapa[tag]) return mapa[tag];
            // Fallback: snake_case → Title Case
            return tag.replace(/_/g, ' ').replace(/\b\w/g, c => c.toUpperCase());
        },

        statusCor(status) {
            return { ok: '#16a34a', aviso: '#d97706', erro: '#dc2626', nunca: '#9ca3af' }[status] ?? '#9ca3af';
        },

        statusBg(status) {
            return { ok: 'background:#f0fdf4', aviso: 'background:#fffbeb', erro: 'background:#fef2f2', nunca: '' }[status] ?? '';
        },
    };
}
</script>
@endsection
