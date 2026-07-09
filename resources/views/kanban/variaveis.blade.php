@extends('layouts.app')

@section('title', 'Variáveis — Lead Certo')

@section('content')
<div class="max-w-4xl mx-auto" x-data="kanbanVariaveis()" x-init="carregar()">

    {{-- Cabeçalho --}}
    <div class="flex items-center justify-between mb-6">
        <div class="flex items-center gap-3">
            <a href="{{ route('kanban.config') }}"
               class="text-gray-400 hover:text-gray-600 p-1 rounded-lg hover:bg-gray-100 transition-colors">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/>
                </svg>
            </a>
            <div>
                <h1 class="text-2xl font-bold text-gray-900">Variáveis de Mensagem</h1>
                <p class="text-sm text-gray-500 mt-0.5">Personalize sequências para que cada lead receba uma versão diferente</p>
            </div>
        </div>
        <button @click="abrirModalNova()"
                class="flex items-center gap-2 bg-indigo-600 hover:bg-indigo-700 text-white text-sm px-4 py-2 rounded-xl transition-colors">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
            </svg>
            Nova variável
        </button>
    </div>

    {{-- Tabs --}}
    <div class="flex gap-1 bg-gray-100 p-1 rounded-xl mb-6">
        <button @click="aba = 'variaveis'"
                :class="aba === 'variaveis' ? 'bg-white shadow text-gray-900' : 'text-gray-500 hover:text-gray-700'"
                class="flex-1 px-4 py-1.5 rounded-lg text-sm font-medium transition-all">
            🎲 Variáveis de Variação
        </button>
        <button @click="aba = 'automaticas'"
                :class="aba === 'automaticas' ? 'bg-white shadow text-gray-900' : 'text-gray-500 hover:text-gray-700'"
                class="flex-1 px-4 py-1.5 rounded-lg text-sm font-medium transition-all">
            ⚡ Variáveis Automáticas
        </button>
        <button @click="aba = 'sugestoes'"
                :class="aba === 'sugestoes' ? 'bg-white shadow text-gray-900' : 'text-gray-500 hover:text-gray-700'"
                class="flex-1 px-4 py-1.5 rounded-lg text-sm font-medium transition-all">
            💡 Sugestões de Templates
        </button>
    </div>

    {{-- ═══ ABA: VARIÁVEIS DE VARIAÇÃO ═══ --}}
    <div x-show="aba === 'variaveis'" style="display:none">

        {{-- Instrução --}}
        <div class="bg-indigo-50 border border-indigo-100 rounded-2xl p-4 mb-5 text-sm text-indigo-800">
            <p class="font-semibold mb-1">Como funcionam</p>
            <p>Use o nome da variável entre chaves nas mensagens de sequência. A cada envio, o sistema sorteia aleatoriamente uma das opções — fazendo cada mensagem soar diferente para cada lead.</p>
            <div class="mt-2 bg-white rounded-xl border border-indigo-100 p-3 font-mono text-xs text-gray-700">
                <span class="text-indigo-600">{saudacao_tempo}</span>, <span class="text-indigo-600">{nome}</span>! <span class="text-indigo-600">{abertura_casual}</span> Conseguiu ver o orçamento que te mandei <span class="text-indigo-600">{tempo_passado}</span>? <span class="text-indigo-600">{gatilho_urgencia}</span> <span class="text-indigo-600">{despedida_casual}</span>
            </div>
        </div>

        <template x-if="carregando">
            <div class="text-center py-10 text-gray-400">Carregando...</div>
        </template>

        <div class="space-y-4" x-show="!carregando">
            <template x-for="v in variaveis" :key="v.nome">
                <div class="bg-white border border-gray-200 rounded-2xl overflow-hidden shadow-sm">
                    <div class="px-5 py-3 border-b border-gray-100 flex items-center gap-3">
                        <code class="text-sm bg-indigo-50 text-indigo-700 rounded-lg px-2.5 py-1 font-mono flex-shrink-0"
                              x-text="'{' + v.nome + '}'"></code>
                        <div class="flex-1 min-w-0">
                            <p class="text-sm font-medium text-gray-700" x-text="v.label"></p>
                        </div>
                        <div class="flex items-center gap-1.5 flex-shrink-0">
                            <template x-if="!v.custom">
                                <button @click="restaurar(v)"
                                        class="text-xs text-gray-400 hover:text-gray-600 border border-gray-200 rounded-lg px-2.5 py-1 transition-colors">
                                    Restaurar padrão
                                </button>
                            </template>
                            <template x-if="v.custom">
                                <button @click="excluir(v)"
                                        class="text-xs text-red-400 hover:text-red-600 border border-red-200 rounded-lg px-2.5 py-1 transition-colors">
                                    Excluir
                                </button>
                            </template>
                        </div>
                    </div>
                    <div class="px-5 py-4">
                        <textarea
                            @input="v.opcoes = $event.target.value; marcaAlterado(v.nome)"
                            :value="v.opcoes"
                            rows="5"
                            placeholder="Uma opção por linha..."
                            class="w-full text-sm border border-gray-200 rounded-xl p-3 focus:outline-none focus:ring-2 focus:ring-indigo-400 resize-none bg-gray-50 font-mono"
                        ></textarea>
                        <div class="flex items-center justify-between mt-2">
                            <p class="text-xs text-gray-400">
                                <span x-text="v.opcoes.split('\n').filter(l => l.trim()).length"></span> opções · sorteio aleatório a cada envio
                            </p>
                            <div class="flex items-center gap-2">
                                <span x-show="salvando[v.nome]" class="text-xs text-gray-400">Salvando...</span>
                                <span x-show="salvo[v.nome]" class="text-xs text-green-600">✓ Salvo</span>
                                <button @click="salvar(v)"
                                        :disabled="!alterado[v.nome]"
                                        class="text-sm bg-indigo-600 hover:bg-indigo-700 disabled:opacity-40 text-white px-4 py-1.5 rounded-lg transition-colors">
                                    Salvar
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </template>

            <template x-if="!carregando && variaveis.length === 0">
                <div class="text-center py-10 text-gray-400 text-sm border-2 border-dashed border-gray-200 rounded-xl">
                    Nenhuma variável cadastrada. Clique em "Nova variável" para criar.
                </div>
            </template>
        </div>
    </div>

    {{-- ═══ ABA: VARIÁVEIS AUTOMÁTICAS ═══ --}}
    <div x-show="aba === 'automaticas'" style="display:none">
        <div class="bg-blue-50 border border-blue-100 rounded-2xl p-4 mb-5 text-sm text-blue-800">
            <p class="font-semibold mb-1">O que são variáveis automáticas</p>
            <p>Estas variáveis são calculadas pelo sistema no momento do envio — sem configuração necessária. Basta usar o nome entre chaves nas mensagens de sequência.</p>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
            @php
            $automaticas = [
                ['nome'=>'saudacao_tempo', 'label'=>'Saudação por horário',          'ex'=>'"Bom dia" · "Boa tarde" · "Boa noite"',          'desc'=>'Detecta o horário do envio e usa a saudação correta (fuso horário de Brasília).'],
                ['nome'=>'nome',           'label'=>'Nome do lead',                   'ex'=>'"Carlos" · "Maria" · "João"',                     'desc'=>'Primeiro nome cadastrado no contato.'],
                ['nome'=>'empresa',        'label'=>'Nome da empresa',                'ex'=>'"Frete.Rio"',                                      'desc'=>'Nome do negócio configurado no sistema.'],
                ['nome'=>'data_hoje',      'label'=>'Data de hoje',                   'ex'=>'"8 de julho"',                                     'desc'=>'Dia e mês da data de envio.'],
                ['nome'=>'dia_semana',     'label'=>'Dia da semana',                  'ex'=>'"segunda-feira" · "sexta-feira"',                  'desc'=>'Nome completo do dia da semana.'],
                ['nome'=>'referencia_dia', 'label'=>'Referência contextual ao dia',   'ex'=>'"nesta segunda-feira" · "pro final de semana"',    'desc'=>'Monta a referência de forma natural com base no dia/hora.'],
                ['nome'=>'tempo_passado',  'label'=>'Tempo desde último contato',     'ex'=>'"ontem" · "há 2 dias" · "na semana passada"',      'desc'=>'Calcula automaticamente desde a última mensagem enviada ao lead.'],
                ['nome'=>'endereco_saida', 'label'=>'Endereço de origem',             'ex'=>'"Rua das Flores, 120, Botafogo"',                  'desc'=>'Endereço de saída coletado do lead (se disponível).'],
                ['nome'=>'endereco_destino','label'=>'Endereço de destino',           'ex'=>'"Av. Brasil, 500, Tijuca"',                        'desc'=>'Endereço de destino coletado do lead (se disponível).'],
            ];
            @endphp
            @foreach($automaticas as $v)
            <div class="bg-white border border-gray-200 rounded-xl p-4 flex gap-3">
                <code class="text-xs bg-blue-50 text-blue-700 rounded-lg px-2 py-1.5 font-mono h-fit flex-shrink-0 whitespace-nowrap">{{'{'}}{{ $v['nome'] }}{{'}'}}}</code>
                <div class="min-w-0">
                    <p class="text-sm font-medium text-gray-800">{{ $v['label'] }}</p>
                    <p class="text-xs text-gray-500 mt-0.5">{{ $v['desc'] }}</p>
                    <p class="text-xs text-green-600 mt-1 font-mono">→ {{ $v['ex'] }}</p>
                </div>
            </div>
            @endforeach
        </div>
    </div>

    {{-- ═══ ABA: SUGESTÕES DE TEMPLATES ═══ --}}
    <div x-show="aba === 'sugestoes'" style="display:none">
        <div class="bg-amber-50 border border-amber-100 rounded-2xl p-4 mb-5 text-sm text-amber-800">
            <p class="font-semibold mb-1">Como usar estes templates</p>
            <p>Copie o texto e cole diretamente no campo de mensagem da sequência. As variáveis serão resolvidas automaticamente no envio. Cada lead receberá uma combinação diferente.</p>
        </div>

        @php
        $templates = [
            [
                'titulo' => 'Follow-up 24h — Quebrar silêncio',
                'cor'    => 'green',
                'texto'  => '{saudacao_tempo}, {nome}! {abertura_empatica} Por isso, {motivo_contato} sobre {termo_servico}. {cta_fechamento}',
                'exemplo_a' => '"Bom dia, Carlos! Sei que a correria de organizar uma mudança é grande... Por isso, estou passando para checar se ficou alguma dúvida sobre a nossa proposta sobre a sua mudança. O que está faltando para fecharmos negócio hoje?"',
                'exemplo_b' => '"Boa tarde, Maria! Imagino que você esteja com a cabeça cheia com os preparativos... Por isso, voltei aqui para saber se você conseguiu dar uma olhada nos valores que te mandei sobre o transporte dos seus móveis. Podemos seguir com o agendamento?"',
            ],
            [
                'titulo' => 'Follow-up 48h — Valor + Urgência',
                'cor'    => 'blue',
                'texto'  => 'Oi {nome}, tudo bem? {motivo_contato} {reforco_valor} Queria ver logo isso contigo porque {gatilho_urgencia} {cta_fechamento}',
                'exemplo_a' => '"Oi Carlos, tudo bem? Tô te chamando rapidinho só pra não deixar o atendimento pendente aqui. Vale lembrar que nossa equipe embala tudo com o maior cuidado. Queria ver logo isso contigo porque já estou com poucos caminhões disponíveis para a data que você precisa. Podemos seguir com o agendamento?"',
                'exemplo_b' => '"Oi Maria, tudo bem? Vim verificar se você precisa de mais alguma informação. Só reforçando que nosso serviço inclui seguro total para a sua tranquilidade. Queria ver logo isso contigo porque a agenda dessa semana deu uma apertada. Qual é o seu prazo máximo para tomar essa decisão?"',
            ],
            [
                'titulo' => '"Última Chamada" — Gatilho de perda',
                'cor'    => 'red',
                'texto'  => '{nome}, {saudacao_tempo}. {motivo_contato} Como não tive seu retorno, imagino que os planos tenham mudado. Só te aviso que {gatilho_urgencia} Se ainda quiser prosseguir, {cta_fechamento}',
                'exemplo_a' => '"Fernanda, bom dia. Vim verificar se você precisa de mais alguma informação. Como não tive seu retorno, imagino que os planos tenham mudado. Só te aviso que nossa agenda para os próximos dias está se esgotando rápido. Se ainda quiser prosseguir, tem algo que eu possa fazer para melhorarmos essa proposta?"',
                'exemplo_b' => '"Roberto, boa tarde. Estou passando para checar se ficou alguma dúvida. Como não tive seu retorno, imagino que os planos tenham mudado. Só te aviso que preciso fechar a rota dos caminhões até amanhã. Se ainda quiser prosseguir, o que está faltando para fecharmos negócio hoje?"',
            ],
        ];
        @endphp

        <div class="space-y-5">
            @foreach($templates as $t)
            @php
                $colors = [
                    'green' => ['bg'=>'bg-green-50','border'=>'border-green-100','code'=>'bg-green-100 text-green-800','badge'=>'bg-green-100 text-green-700'],
                    'blue'  => ['bg'=>'bg-blue-50', 'border'=>'border-blue-100', 'code'=>'bg-blue-100 text-blue-800',  'badge'=>'bg-blue-100 text-blue-700'],
                    'red'   => ['bg'=>'bg-red-50',  'border'=>'border-red-100',  'code'=>'bg-red-100 text-red-800',    'badge'=>'bg-red-100 text-red-700'],
                ];
                $c = $colors[$t['cor']];
            @endphp
            <div class="bg-white border border-gray-200 rounded-2xl overflow-hidden shadow-sm">
                <div class="px-5 py-3 border-b border-gray-100 flex items-center justify-between">
                    <span class="text-sm font-semibold text-gray-800">{{ $t['titulo'] }}</span>
                    <button onclick="navigator.clipboard.writeText(this.dataset.texto); this.textContent='✓ Copiado!'; setTimeout(()=>this.textContent='Copiar',2000)"
                            data-texto="{{ $t['texto'] }}"
                            class="text-xs border border-gray-200 rounded-lg px-3 py-1 hover:bg-gray-50 transition-colors text-gray-500">
                        Copiar
                    </button>
                </div>
                <div class="px-5 py-4 space-y-4">
                    <div class="{{ $c['bg'] }} {{ $c['border'] }} border rounded-xl p-3">
                        <p class="text-xs font-semibold text-gray-500 mb-1.5">Estrutura do template:</p>
                        <code class="block text-sm {{ $c['code'] }} font-mono rounded-lg px-3 py-2">{{ $t['texto'] }}</code>
                    </div>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                        <div>
                            <p class="text-xs font-semibold text-gray-400 mb-1.5">Exemplo — Lead A recebe:</p>
                            <p class="text-sm text-gray-700 bg-gray-50 rounded-xl px-4 py-3 border border-gray-100">{{ $t['exemplo_a'] }}</p>
                        </div>
                        <div>
                            <p class="text-xs font-semibold text-gray-400 mb-1.5">Exemplo — Lead B recebe:</p>
                            <p class="text-sm text-gray-700 bg-gray-50 rounded-xl px-4 py-3 border border-gray-100">{{ $t['exemplo_b'] }}</p>
                        </div>
                    </div>
                </div>
            </div>
            @endforeach
        </div>
    </div>

    {{-- Modal: Nova variável --}}
    <template x-if="modalNova">
        <div class="fixed inset-0 bg-black/50 z-50 flex items-center justify-center p-4">
            <div class="bg-white rounded-2xl p-6 w-full max-w-md shadow-2xl">
                <h2 class="font-semibold text-gray-800 mb-4">Nova variável de variação</h2>
                <div class="space-y-4">
                    <div>
                        <label class="block text-xs font-semibold text-gray-500 mb-1">Nome da variável *</label>
                        <div class="flex items-center gap-2">
                            <span class="text-gray-400 font-mono">{</span>
                            <input type="text" x-model="novaForm.nome"
                                   @input="novaForm.nome = $event.target.value.toLowerCase().replace(/[^a-z0-9_]/g,'_').replace(/^[^a-z]+/,'')"
                                   placeholder="minha_variavel"
                                   class="flex-1 border border-gray-300 rounded-lg px-3 py-2 text-sm font-mono focus:outline-none focus:ring-2 focus:ring-indigo-500">
                            <span class="text-gray-400 font-mono">}</span>
                        </div>
                        <p class="text-xs text-gray-400 mt-1">Só letras minúsculas, números e underline</p>
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-gray-500 mb-1">Título / Descrição *</label>
                        <input type="text" x-model="novaForm.label"
                               placeholder="Ex: Saudação de abertura"
                               class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500">
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-gray-500 mb-1">Opções (uma por linha) *</label>
                        <textarea x-model="novaForm.opcoes" rows="5"
                                  placeholder="Opção 1&#10;Opção 2&#10;Opção 3"
                                  class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm font-mono focus:outline-none focus:ring-2 focus:ring-indigo-500"></textarea>
                    </div>
                    <template x-if="novaErro">
                        <p class="text-xs text-red-600" x-text="novaErro"></p>
                    </template>
                </div>
                <div class="flex gap-2 mt-5">
                    <button @click="modalNova = false"
                            class="flex-1 border border-gray-300 text-gray-600 py-2 rounded-lg text-sm hover:bg-gray-50">
                        Cancelar
                    </button>
                    <button @click="criarVariavel()"
                            :disabled="criando || !novaForm.nome || !novaForm.label || !novaForm.opcoes.trim()"
                            class="flex-1 bg-indigo-600 hover:bg-indigo-700 disabled:opacity-40 text-white py-2 rounded-lg text-sm transition-colors">
                        <span x-show="!criando">Criar variável</span>
                        <span x-show="criando">Criando...</span>
                    </button>
                </div>
            </div>
        </div>
    </template>

</div>
@endsection

@push('scripts')
<script>
function kanbanVariaveis() {
    return {
        aba:       'variaveis',
        variaveis: [],
        carregando: true,
        alterado:  {},
        salvando:  {},
        salvo:     {},
        modalNova: false,
        criando:   false,
        novaErro:  '',
        novaForm:  { nome: '', label: '', opcoes: '' },

        async carregar() {
            this.carregando = true;
            const res = await this.api('/api/painel/kanban/variaveis');
            if (res.ok) this.variaveis = await res.json();
            this.carregando = false;
        },

        marcaAlterado(nome) {
            this.alterado[nome] = true;
        },

        async salvar(v) {
            this.salvando[v.nome] = true;
            const res = await this.api(`/api/painel/kanban/variaveis/${v.nome}`, 'PUT', {
                opcoes: v.opcoes,
                label:  v.label,
            });
            this.salvando[v.nome] = false;
            if (res.ok) {
                this.alterado[v.nome] = false;
                this.salvo[v.nome]    = true;
                setTimeout(() => { this.salvo[v.nome] = false; }, 3000);
            }
        },

        restaurar(v) {
            v.opcoes = v.padrao;
            this.alterado[v.nome] = true;
        },

        async excluir(v) {
            if (! confirm(`Excluir a variável {${v.nome}}? Esta ação não pode ser desfeita.`)) return;
            const res = await this.api(`/api/painel/kanban/variaveis/${v.nome}`, 'DELETE');
            if (res.ok) {
                this.variaveis = this.variaveis.filter(x => x.nome !== v.nome);
            } else {
                const json = await res.json().catch(() => ({}));
                alert(json.message || 'Erro ao excluir.');
            }
        },

        abrirModalNova() {
            this.novaForm  = { nome: '', label: '', opcoes: '' };
            this.novaErro  = '';
            this.modalNova = true;
            this.aba = 'variaveis';
        },

        async criarVariavel() {
            this.novaErro = '';
            if (! this.novaForm.nome || ! this.novaForm.label || ! this.novaForm.opcoes.trim()) return;
            this.criando = true;
            const res = await this.api('/api/painel/kanban/variaveis', 'POST', this.novaForm);
            this.criando = false;
            if (res.ok) {
                this.modalNova = false;
                await this.carregar();
            } else {
                const json = await res.json().catch(() => ({}));
                this.novaErro = json.message || 'Erro ao criar variável.';
            }
        },

        async api(url, method = 'GET', body = null) {
            return fetch(url, {
                method,
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                },
                body: body ? JSON.stringify(body) : null,
            });
        },
    };
}
</script>
@endpush
