@extends('layouts.app')

@section('title', 'Secretária Eletrônica — Lead Certo')

@section('content')
<div class="max-w-4xl" x-data="secretaria()" x-init="carregar()">

    <div class="mb-6">
        <h1 class="text-xl font-bold text-gray-800">Secretária Eletrônica</h1>
        <p class="text-sm text-gray-500 mt-1">Captura automaticamente leads que ligaram e não foram atendidos.</p>
    </div>

    {{-- Sanfona de instruções --}}
    <div class="mb-6" x-data="{ aberto: false }">
        <button @click="aberto = !aberto"
                class="w-full flex items-center justify-between bg-blue-50 border border-blue-100 rounded-2xl px-5 py-4 text-left hover:bg-blue-100 transition-colors">
            <div class="flex items-center gap-3">
                <svg class="w-5 h-5 text-blue-500 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                          d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                </svg>
                <span class="text-sm font-semibold text-blue-700">Como usar a Secretária Eletrônica</span>
            </div>
            <svg class="w-4 h-4 text-blue-400 transition-transform" :class="aberto ? 'rotate-180' : ''" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
            </svg>
        </button>

        <div x-show="aberto" x-transition class="bg-white border border-blue-100 border-t-0 rounded-b-2xl px-6 py-5 space-y-5 text-sm text-gray-700">

            {{-- Passo 1 --}}
            <div class="flex gap-3">
                <div class="w-6 h-6 rounded-full bg-blue-100 text-blue-700 flex items-center justify-center text-xs font-bold shrink-0 mt-0.5">1</div>
                <div>
                    <p class="font-semibold">Baixe o MacroDroid na Play Store</p>
                    <p class="text-gray-500 mt-0.5">Busque por <strong>MacroDroid</strong> e instale. É gratuito e não precisa de root.</p>
                </div>
            </div>

            {{-- Passo 2 --}}
            <div class="flex gap-3">
                <div class="w-6 h-6 rounded-full bg-blue-100 text-blue-700 flex items-center justify-center text-xs font-bold shrink-0 mt-0.5">2</div>
                <div>
                    <p class="font-semibold">Crie uma nova macro</p>
                    <p class="text-gray-500 mt-0.5">Abra o MacroDroid e toque no botão <strong>"+"</strong>. Quando pedir nome, coloque <strong>Lead Certo - Chamada Perdida</strong>.</p>
                </div>
            </div>

            {{-- Passo 3 --}}
            <div class="flex gap-3">
                <div class="w-6 h-6 rounded-full bg-blue-100 text-blue-700 flex items-center justify-center text-xs font-bold shrink-0 mt-0.5">3</div>
                <div>
                    <p class="font-semibold">Configure o Gatilho</p>
                    <p class="text-gray-500 mt-1">Na seção <strong>Gatilhos</strong>, toque em <strong>"+"</strong> e siga o caminho:</p>
                    <div class="mt-2 bg-gray-50 rounded-lg px-4 py-3 text-xs space-y-1">
                        <p>1. Selecione <strong>Chamadas SMS</strong></p>
                        <p>2. Selecione <strong>Chamada Perdida</strong></p>
                        <p>3. Selecione <strong>Qualquer número</strong> e confirme</p>
                    </div>
                </div>
            </div>

            {{-- Passo 4 --}}
            <div class="flex gap-3">
                <div class="w-6 h-6 rounded-full bg-blue-100 text-blue-700 flex items-center justify-center text-xs font-bold shrink-0 mt-0.5">4</div>
                <div>
                    <p class="font-semibold">Configure a Ação</p>
                    <p class="text-gray-500 mt-1">Na seção <strong>Ações</strong>, toque em <strong>"+"</strong> e siga o caminho:</p>
                    <div class="mt-2 bg-gray-50 rounded-lg px-4 py-3 text-xs space-y-1">
                        <p>1. Selecione <strong>Integração Web</strong> (não é Conectividade)</p>
                        <p>2. Selecione <strong>Requisição HTTP</strong></p>
                    </div>
                </div>
            </div>

            {{-- Passo 5 --}}
            <div class="flex gap-3">
                <div class="w-6 h-6 rounded-full bg-blue-100 text-blue-700 flex items-center justify-center text-xs font-bold shrink-0 mt-0.5">5</div>
                <div class="w-full">
                    <p class="font-semibold">Preencha a Requisição HTTP</p>
                    <div class="mt-2 space-y-3">

                        <div>
                            <p class="text-xs text-gray-500 mb-1"><strong>Método:</strong> mude de GET para <strong>POST</strong></p>
                        </div>

                        <div>
                            <p class="text-xs text-gray-500 mb-1"><strong>URL</strong> — copie e cole o campo abaixo:</p>
                            <div class="flex gap-2 items-center">
                                <input type="text" readonly
                                       :value="'https://app.leadcerto.app.br/api/secretaria/' + token"
                                       class="flex-1 font-mono text-xs bg-gray-50 border border-gray-200 rounded-lg px-3 py-2 text-gray-800 select-all">
                                <button @click="copiarUrlMacro()"
                                        class="px-3 py-2 text-xs bg-gray-800 text-white rounded-lg hover:bg-gray-700 transition-colors whitespace-nowrap">
                                    <span x-text="copiadoUrl ? 'Copiado!' : 'Copiar URL'"></span>
                                </button>
                            </div>
                        </div>

                        <div>
                            <p class="text-xs text-gray-500 mb-1"><strong>Tipo do corpo:</strong> selecione <strong>application/json</strong></p>
                        </div>

                        <div>
                            <p class="text-xs text-gray-500 mb-1"><strong>Corpo da requisição</strong> — copie, cole e substitua o número:</p>
                            <div class="relative">
                                <pre class="bg-gray-50 border border-gray-200 rounded-lg px-4 py-3 text-xs text-gray-800 overflow-x-auto">{
  "numero_chamador": "{call_number}",
  "numero_receptor": "55DDDSEUNUMERO",
  "duracao_segundos": 0
}</pre>
                                <button @click="copiarBodyMacro()"
                                        class="absolute top-2 right-2 px-2 py-1 text-xs bg-gray-700 text-white rounded hover:bg-gray-600 transition-colors">
                                    <span x-text="copiadoBody ? 'Copiado!' : 'Copiar'"></span>
                                </button>
                            </div>
                            <p class="text-xs text-gray-400 mt-1">Substitua <strong>55DDDSEUNUMERO</strong> pelo seu número com 55 na frente. Ex: <code>5521981813106</code></p>
                            <p class="text-xs text-gray-400 mt-1">O <strong>&#123;call_number&#125;</strong> é preenchido automaticamente pelo MacroDroid com o número de quem ligou.</p>
                        </div>
                    </div>
                </div>
            </div>

            {{-- Passo 6 --}}
            <div class="flex gap-3">
                <div class="w-6 h-6 rounded-full bg-blue-100 text-blue-700 flex items-center justify-center text-xs font-bold shrink-0 mt-0.5">6</div>
                <div>
                    <p class="font-semibold">Salve e teste</p>
                    <p class="text-gray-500 mt-0.5">Confirme a ação, confirme a macro e salve. Para testar sem ligar de verdade, toque no ícone <strong>▶ play</strong> ao lado da macro na lista. Se aparecer uma nova linha na tabela abaixo, está funcionando.</p>
                </div>
            </div>

            <div class="bg-yellow-50 border border-yellow-100 rounded-lg p-3 text-xs text-yellow-800">
                <strong>Importante:</strong> o celular precisa estar com internet (Wi-Fi ou dados móveis). O MacroDroid roda em segundo plano — não precisa deixar a tela ligada.
            </div>
        </div>
    </div>

    {{-- Configuração --}}
    <div class="bg-white rounded-2xl shadow-sm p-6 mb-6">
        <h2 class="text-sm font-semibold text-gray-700 uppercase tracking-wider mb-4">Configuração do App Android</h2>

        <div class="space-y-4">
            <div>
                <label class="block text-sm text-gray-600 mb-1">Token da Secretária</label>
                <div class="flex gap-2">
                    <input type="text" :value="token" readonly
                           class="flex-1 font-mono text-sm bg-gray-50 border border-gray-200 rounded-lg px-3 py-2 text-gray-800 select-all">
                    <button @click="copiarToken()"
                            class="px-4 py-2 text-sm bg-gray-800 text-white rounded-lg hover:bg-gray-700 transition-colors">
                        <span x-text="copiado ? 'Copiado!' : 'Copiar'"></span>
                    </button>
                    <button @click="rotacionarToken()"
                            class="px-4 py-2 text-sm border border-red-200 text-red-600 rounded-lg hover:bg-red-50 transition-colors">
                        Gerar novo
                    </button>
                </div>
                <p class="text-xs text-gray-400 mt-1">Cole este token na tela de configuração do app Android.</p>
            </div>

            <div class="flex items-center gap-2 pt-2 border-t border-gray-100">
                <span class="text-sm text-gray-600">Dispositivos conectados:</span>
                <span class="font-semibold text-gray-800" x-text="dispositivosAtivos"></span>
            </div>
        </div>
    </div>

    {{-- Mensagem de abertura --}}
    <div class="bg-white rounded-2xl shadow-sm p-6 mb-6">
        <h2 class="text-sm font-semibold text-gray-700 uppercase tracking-wider mb-1">Mensagem de Abertura</h2>
        <p class="text-xs text-gray-400 mb-4">Texto que o João vai usar ao entrar em contato com quem ligou e não foi atendido. Deixe em branco para usar o padrão do sistema.</p>

        <textarea x-model="mensagemInicial" rows="3"
                  @input="salvoOk = false"
                  placeholder="Ex: Oi! Vi que você ligou aqui pra gente e não consegui atender na hora. Tô disponível agora no WhatsApp, pode falar!"
                  class="w-full text-sm border border-gray-200 rounded-lg px-3 py-2 text-gray-800 placeholder-gray-300 focus:outline-none focus:ring-2 focus:ring-gray-300 resize-none"></textarea>

        <div class="flex items-center justify-between mt-3">
            <p class="text-xs text-gray-400">O João vai usar esta mensagem exatamente como você escreveu.</p>
            <button @click="salvarMensagem()"
                    class="px-4 py-2 text-sm bg-gray-800 text-white rounded-lg hover:bg-gray-700 transition-colors flex items-center gap-2">
                <template x-if="salvando"><span>Salvando…</span></template>
                <template x-if="!salvando"><span x-text="salvoOk ? 'Salvo ✓' : 'Salvar mensagem'"></span></template>
            </button>
        </div>
    </div>

    {{-- Métricas --}}
    <div class="grid grid-cols-2 gap-4 mb-6">
        <div class="bg-white rounded-2xl shadow-sm p-5">
            <div class="text-sm text-gray-500">Chamadas capturadas este mês</div>
            <div class="text-3xl font-bold text-gray-800 mt-1" x-text="totalMes"></div>
        </div>
        <div class="bg-white rounded-2xl shadow-sm p-5">
            <div class="text-sm text-gray-500">Mensagens enviadas este mês</div>
            <div class="text-3xl font-bold text-green-600 mt-1"
                 x-text="chamadas.filter(c => c.mensagem_enviada).length"></div>
        </div>
    </div>

    {{-- Chamadas recentes --}}
    <div class="bg-white rounded-2xl shadow-sm p-6">
        <h2 class="text-sm font-semibold text-gray-700 uppercase tracking-wider mb-4">Chamadas Recentes</h2>

        <template x-if="chamadas.length === 0">
            <div class="text-center py-10 text-gray-400">
                <svg class="w-10 h-10 mx-auto mb-3 opacity-40" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5"
                          d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z"/>
                </svg>
                <p class="text-sm">Nenhuma chamada capturada ainda.</p>
                <p class="text-xs mt-1">Instale o app Android e configure com o token acima.</p>
            </div>
        </template>

        <template x-if="chamadas.length > 0">
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-left text-xs text-gray-400 uppercase tracking-wider border-b border-gray-100">
                            <th class="pb-3 pr-4">Número</th>
                            <th class="pb-3 pr-4">Contato</th>
                            <th class="pb-3 pr-4">Quando ligou</th>
                            <th class="pb-3 pr-4">Status</th>
                            <th class="pb-3">Ticket</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-50">
                        <template x-for="chamada in chamadas" :key="chamada.id">
                            <tr class="hover:bg-gray-50">
                                <td class="py-3 pr-4 font-mono text-gray-700" x-text="chamada.numero_chamador"></td>
                                <td class="py-3 pr-4 text-gray-600">
                                <template x-if="editandoId === chamada.id">
                                    <div class="flex items-center gap-1">
                                        <input type="text" x-model="editandoNome"
                                               class="border border-gray-300 rounded px-2 py-0.5 text-sm w-36 focus:outline-none focus:ring-1 focus:ring-gray-400"
                                               @keydown.enter="salvarNome(chamada)"
                                               @keydown.escape="editandoId = null"
                                               x-ref="inputNome"
                                               x-init="$nextTick(() => { if (editandoId === chamada.id) $el.focus() })">
                                        <button @click="salvarNome(chamada)" title="Salvar"
                                                class="text-green-600 hover:text-green-800 p-0.5">
                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                                            </svg>
                                        </button>
                                        <button @click="editandoId = null" title="Cancelar"
                                                class="text-gray-400 hover:text-gray-600 p-0.5">
                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                                            </svg>
                                        </button>
                                    </div>
                                </template>
                                <template x-if="editandoId !== chamada.id">
                                    <div class="flex items-center gap-1.5 group">
                                        <span :class="(!chamada.contato_nome || chamada.contato_nome === 'Sem Nome' || chamada.contato_nome === chamada.numero_chamador) ? 'text-gray-400 italic' : ''"
                                              x-text="(chamada.contato_nome && chamada.contato_nome !== chamada.numero_chamador) ? chamada.contato_nome : 'Sem nome'"></span>
                                        <template x-if="chamada.contato_id">
                                            <button @click="editandoId = chamada.id; editandoNome = (chamada.contato_nome && chamada.contato_nome !== chamada.numero_chamador && chamada.contato_nome !== 'Sem Nome') ? chamada.contato_nome : ''"
                                                    title="Editar nome do contato"
                                                    class="opacity-0 group-hover:opacity-100 transition-opacity text-gray-400 hover:text-gray-700 p-0.5">
                                                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z"/>
                                                </svg>
                                            </button>
                                        </template>
                                    </div>
                                </template>
                            </td>
                                <td class="py-3 pr-4 text-gray-500" x-text="chamada.chamou_em"></td>
                                <td class="py-3 pr-4">
                                    <template x-if="chamada.mensagem_enviada">
                                        <span class="inline-flex items-center gap-1 text-xs text-green-600 bg-green-50 px-2 py-0.5 rounded-full">
                                            <span class="w-1.5 h-1.5 rounded-full bg-green-500"></span>
                                            Mensagem enviada
                                        </span>
                                    </template>
                                    <template x-if="!chamada.mensagem_enviada">
                                        <span class="inline-flex items-center gap-1 text-xs text-gray-400 bg-gray-100 px-2 py-0.5 rounded-full">
                                            Cooldown / pendente
                                        </span>
                                    </template>
                                </td>
                                <td class="py-3">
                                    <template x-if="chamada.ticket_id">
                                        <a :href="'/kanban'" class="text-xs text-blue-600 hover:underline"
                                           x-text="'#' + chamada.ticket_id"></a>
                                    </template>
                                    <template x-if="!chamada.ticket_id">
                                        <span class="text-gray-300">—</span>
                                    </template>
                                </td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>
        </template>
    </div>

</div>
@endsection

@push('scripts')
<script>
function secretaria() {
    return {
        token: '',
        mensagemInicial: '',
        chamadas: [],
        totalMes: 0,
        dispositivosAtivos: 0,
        copiado: false,
        copiadoUrl: false,
        copiadoBody: false,
        salvando: false,
        salvoOk: false,
        editandoId: null,
        editandoNome: '',

        async carregar() {
            const res = await fetch('/api/painel/secretaria-eletronica/dados');
            if (! res.ok) return;
            const d = await res.json();
            this.token              = d.secretaria_token ?? '';
            this.mensagemInicial    = d.mensagem_inicial ?? '';
            this.chamadas           = d.chamadas ?? [];
            this.totalMes           = d.total_mes ?? 0;
            this.dispositivosAtivos = d.dispositivos_ativos ?? 0;
        },

        async salvarMensagem() {
            this.salvando = true;
            this.salvoOk  = false;
            await fetch('/api/painel/secretaria-eletronica/mensagem', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                },
                body: JSON.stringify({ mensagem: this.mensagemInicial }),
            });
            this.salvando = false;
            this.salvoOk  = true;
        },

        async copiarToken() {
            await navigator.clipboard.writeText(this.token);
            this.copiado = true;
            setTimeout(() => this.copiado = false, 2000);
        },

        async copiarUrlMacro() {
            await navigator.clipboard.writeText('https://app.leadcerto.app.br/api/secretaria/' + this.token);
            this.copiadoUrl = true;
            setTimeout(() => this.copiadoUrl = false, 2000);
        },

        async copiarBodyMacro() {
            const body = `{\n  "numero_chamador": "{call_number}",\n  "numero_receptor": "55DDDSEUNUMERO",\n  "duracao_segundos": 0\n}`;
            await navigator.clipboard.writeText(body);
            this.copiadoBody = true;
            setTimeout(() => this.copiadoBody = false, 2000);
        },

        async salvarNome(chamada) {
            const nome = this.editandoNome.trim();
            if (! nome || ! chamada.contato_id) return;

            const csrf = document.querySelector('meta[name="csrf-token"]').content;
            const res  = await fetch(`/api/painel/contato/${chamada.contato_id}`, {
                method:  'PATCH',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json' },
                body:    JSON.stringify({ nome }),
            });

            if (res.ok) {
                chamada.contato_nome = nome;
                this.editandoId      = null;
            }
        },

        async rotacionarToken() {
            if (! confirm('Gerar novo token invalida o token atual no app Android. Continuar?')) return;
            const res = await fetch('/api/painel/secretaria-eletronica/token', {
                method: 'POST',
                headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content },
            });
            const d = await res.json();
            if (d.ok) this.token = d.secretaria_token;
        },
    }
}
</script>
@endpush
