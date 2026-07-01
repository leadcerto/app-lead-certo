@extends('layouts.app')

@section('title', 'Kanban — Lead Certo')

@section('content')
<div x-data="kanban()" x-init="carregar()" class="h-full">

    <div class="flex items-center justify-between mb-5">
        <h1 class="text-xl font-bold text-gray-800">Atendimentos</h1>
        <span class="text-xs text-gray-400">Atualiza a cada 5s</span>
    </div>

    {{-- Colunas Kanban --}}
    <div class="flex gap-4 overflow-x-auto pb-4" style="min-height: calc(100vh - 160px)">

        <template x-for="coluna in colunas" :key="coluna.key">
            <div class="flex-shrink-0 w-72">
                <div class="flex items-center justify-between mb-3 px-1">
                    <span class="text-sm font-semibold text-gray-600" x-text="coluna.label"></span>
                    <span class="bg-gray-200 text-gray-600 text-xs rounded-full px-2 py-0.5"
                          x-text="(tickets[coluna.key] || []).length"></span>
                </div>

                <div class="space-y-2">
                    <template x-for="ticket in (tickets[coluna.key] || [])" :key="ticket.id">
                        <div class="bg-white rounded-xl shadow-sm p-4 cursor-pointer hover:shadow-md transition-shadow"
                             @click="abrirTicket(ticket)">

                            <div class="flex items-start justify-between gap-2">
                                <div>
                                    <div class="flex items-center gap-1.5">
                                        <p class="text-sm font-medium text-gray-800"
                                           x-text="ticket.contato?.nome_local || ticket.contato?.nome || ticket.contato?.telefone || '#' + ticket.id"></p>
                                        <span x-show="ticket.contato?.auditoria_pendente"
                                              title="Nome em revisão pelo Auditor"
                                              class="inline-block w-2 h-2 rounded-full bg-yellow-400 flex-shrink-0"></span>
                                    </div>
                                    <p class="text-xs text-gray-400 mt-0.5"
                                       x-text="[ticket.contato?.telefone, ticket.contato?.id].filter(Boolean).join(' · ')"></p>
                                </div>
                                <div class="flex flex-col items-end gap-1">
                                    <template x-if="ticket.agente_responsavel === 'bot'">
                                        <span class="text-xs bg-blue-100 text-blue-600 px-2 py-0.5 rounded-full">Bot</span>
                                    </template>
                                    <template x-if="ticket.agente_responsavel === 'humano'">
                                        <span class="text-xs bg-green-100 text-green-600 px-2 py-0.5 rounded-full">Humano</span>
                                    </template>
                                    <template x-if="ticket.status === 'pendente'">
                                        <span class="text-xs bg-orange-100 text-orange-600 px-2 py-0.5 rounded-full">Pendente</span>
                                    </template>
                                    <template x-if="ticket.status === 'resolvido'">
                                        <span class="text-xs bg-teal-100 text-teal-600 px-2 py-0.5 rounded-full">Resolvido</span>
                                    </template>
                                </div>
                            </div>

                            <div class="flex items-center justify-between mt-3">
                                <span class="text-xs text-gray-400"
                                      x-text="dataRelativa(ticket.aberto_em)"></span>
                                <template x-if="ticket.count_midias > 0">
                                    <span class="text-xs text-purple-600">📎 <span x-text="ticket.count_midias"></span></span>
                                </template>
                            </div>

                            <template x-if="!ticket.vendedor_id">
                                <button @click.stop="assumir(ticket.id)"
                                        class="mt-3 w-full text-xs bg-green-600 hover:bg-green-500 text-white py-1.5 rounded-lg transition-colors">
                                    Assumir
                                </button>
                            </template>
                            <template x-if="ticket.vendedor_id">
                                <p class="mt-2 text-xs text-gray-400 truncate"
                                   x-text="'Com: ' + (ticket.vendedor?.nome || '—')"></p>
                            </template>
                        </div>
                    </template>
                </div>
            </div>
        </template>

    </div>

    {{-- Modal de atendimento --}}
    <template x-if="ticketAtivo">
        <div class="fixed inset-0 bg-black/50 z-50 flex items-end md:items-center justify-center p-4">
            <div class="bg-white rounded-2xl w-full max-w-lg shadow-2xl flex flex-col max-h-[90vh]">

                {{-- Header --}}
                <div class="flex items-center justify-between px-5 py-4 border-b">
                    <div>
                        {{-- Nome editável --}}
                        <div x-show="!editandoNome" class="flex items-center gap-1.5">
                            <p class="font-semibold text-gray-800"
                               x-text="ticketAtivo.contato?.nome_local || ticketAtivo.contato?.nome || ticketAtivo.contato?.telefone"></p>
                            <span x-show="ticketAtivo.contato?.auditoria_pendente"
                                  class="text-xs bg-yellow-100 text-yellow-700 px-1.5 py-0.5 rounded font-normal"
                                  title="Nome sugerido aguarda aprovação do Auditor">Em revisão</span>
                            <button @click="editandoNome = true; nomeEdit = ticketAtivo.contato?.nome_local || ticketAtivo.contato?.nome || ''"
                                    class="text-gray-300 hover:text-gray-500 transition-colors">
                                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                          d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z"/>
                                </svg>
                            </button>
                        </div>
                        <div x-show="editandoNome" class="flex items-center gap-2">
                            <input x-model="nomeEdit" type="text"
                                   @keydown.enter="salvarNome()"
                                   @keydown.escape="editandoNome = false"
                                   x-ref="inputNome"
                                   class="text-sm font-semibold border-b-2 border-green-400 focus:outline-none bg-transparent w-40"
                                   placeholder="Nome do contato">
                            <button @click="salvarNome()"
                                    class="text-xs text-green-600 font-medium hover:underline">Salvar</button>
                            <button @click="editandoNome = false"
                                    class="text-xs text-gray-400 hover:underline">×</button>
                        </div>
                        <p class="text-xs text-gray-400"
                           x-text="[ticketAtivo.contato?.telefone, ticketAtivo.contato?.id].filter(Boolean).join(' · ')"></p>
                    </div>
                    <div class="flex items-center gap-1.5">
                        <template x-if="ticketAtivo.status === 'aberto' && ticketAtivo.agente_responsavel === 'humano'">
                            <button @click="marcarPendente(ticketAtivo.id)"
                                    class="text-xs bg-orange-100 text-orange-600 px-2.5 py-1.5 rounded-lg hover:bg-orange-200 transition-colors">
                                Pendente
                            </button>
                        </template>
                        <template x-if="ticketAtivo.agente_responsavel === 'humano' && !['resolvido','encerrado'].includes(ticketAtivo.status)">
                            <button @click="resolver(ticketAtivo.id)"
                                    class="text-xs bg-teal-100 text-teal-600 px-2.5 py-1.5 rounded-lg hover:bg-teal-200 transition-colors">
                                Resolver
                            </button>
                        </template>
                        <template x-if="!['resolvido','encerrado'].includes(ticketAtivo.status)">
                            <button @click="encerrarModal = true"
                                    class="text-xs bg-red-100 text-red-600 px-2.5 py-1.5 rounded-lg hover:bg-red-200 transition-colors">
                                Encerrar
                            </button>
                        </template>
                        <button @click="ticketAtivo = null"
                                class="text-gray-400 hover:text-gray-600 text-xl leading-none ml-1">&times;</button>
                    </div>
                </div>

                {{-- Notas do contato --}}
                <template x-if="ticketAtivo.contato?.id">
                    <div class="border-b">
                        <button @click="notasAberto = !notasAberto"
                                class="w-full flex items-center justify-between px-5 py-2 text-xs text-gray-500 hover:bg-gray-50 transition-colors">
                            <span class="flex items-center gap-1.5">
                                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/>
                                </svg>
                                <span x-text="notas.length > 0 ? 'Notas (' + notas.length + ')' : 'Notas'"></span>
                            </span>
                            <svg class="w-3.5 h-3.5 transition-transform duration-200" :class="notasAberto ? 'rotate-180' : ''" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
                            </svg>
                        </button>
                        <template x-if="notasAberto">
                            <div class="px-5 pb-3 space-y-1.5 max-h-44 overflow-y-auto">
                                <template x-if="notas.length === 0">
                                    <p class="text-xs text-gray-400 text-center py-1">Sem notas ainda.</p>
                                </template>
                                <template x-for="nota in notas" :key="nota.id">
                                    <div class="bg-yellow-50 border border-yellow-200 rounded-lg px-3 py-2">
                                        <p class="text-xs text-gray-700 leading-snug" x-text="nota.texto"></p>
                                        <div class="flex items-center justify-between mt-1">
                                            <span class="text-xs text-gray-400"
                                                  x-text="nota.autor + ' · ' + new Date(nota.created_at).toLocaleDateString('pt-BR')"></span>
                                            <button @click="excluirNota(nota.id)"
                                                    class="text-gray-300 hover:text-red-400 transition-colors text-sm leading-none">×</button>
                                        </div>
                                    </div>
                                </template>
                                <div class="flex gap-2 pt-1">
                                    <input x-model="novaNota" type="text"
                                           placeholder="Adicionar nota sobre este contato..."
                                           @keydown.enter.prevent="salvarNota()"
                                           class="flex-1 border border-gray-300 rounded-lg px-3 py-1.5 text-xs focus:outline-none focus:ring-1 focus:ring-yellow-400">
                                    <button @click="salvarNota()"
                                            :disabled="salvandoNota || !novaNota.trim()"
                                            class="bg-yellow-400 hover:bg-yellow-300 disabled:opacity-40 text-gray-800 px-3 py-1.5 rounded-lg text-xs font-medium transition-colors">
                                        Salvar
                                    </button>
                                </div>
                            </div>
                        </template>
                    </div>
                </template>

                {{-- Mensagens --}}
                <div class="flex-1 overflow-y-auto px-5 py-4 space-y-2" x-ref="msgBox">
                    <template x-for="msg in mensagens" :key="msg.id">
                        <div :class="msg.remetente === 'lead' ? 'flex justify-start' : 'flex justify-end'">
                            <div :class="msg.remetente === 'lead'
                                    ? 'bg-gray-100 text-gray-800 rounded-br-xl rounded-tr-xl rounded-bl-sm'
                                    : 'bg-green-600 text-white rounded-bl-xl rounded-tl-xl rounded-br-sm'"
                                 class="max-w-xs px-3.5 py-2.5 text-sm rounded-2xl">
                                <template x-if="msg.tipo === 'texto'">
                                    <p x-text="msg.conteudo"></p>
                                </template>
                                <template x-if="msg.tipo !== 'texto' && msg.midia_url">
                                    <a :href="msg.midia_url" target="_blank"
                                       class="underline text-xs opacity-80">Ver arquivo</a>
                                </template>
                                <p class="text-xs opacity-50 mt-1 text-right"
                                   x-text="new Date(msg.enviado_em).toLocaleTimeString('pt-BR', {hour:'2-digit', minute:'2-digit'})"></p>
                            </div>
                        </div>
                    </template>
                </div>

                {{-- Input --}}
                <template x-if="ticketAtivo.status !== 'encerrado'">
                    <div class="px-4 pb-4 pt-2 border-t">
                        <template x-if="ticketAtivo.agente_responsavel !== 'humano'">
                            <div class="text-center text-xs text-gray-400 py-2">
                                <button @click="assumir(ticketAtivo.id)"
                                        class="text-green-600 font-medium hover:underline">
                                    Assumir atendimento
                                </button>
                                para enviar mensagens
                            </div>
                        </template>
                        <template x-if="ticketAtivo.agente_responsavel === 'humano'">
                            <div>
                                {{-- Sugestões de resposta pronta --}}
                                <template x-if="sugestoes.length > 0">
                                    <div class="mb-2 border border-gray-200 rounded-xl overflow-hidden shadow-sm">
                                        <template x-for="(s, i) in sugestoes" :key="s.id">
                                            <button @click="aplicarResposta(s)"
                                                    :class="i === sugestaoSelecionada ? 'bg-green-50' : 'bg-white hover:bg-gray-50'"
                                                    class="w-full text-left px-4 py-2.5 border-b border-gray-100 last:border-0 transition-colors">
                                                <span class="text-xs font-mono text-green-700 mr-2" x-text="'/' + s.codigo_curto"></span>
                                                <span class="text-xs text-gray-500 truncate" x-text="s.conteudo"></span>
                                            </button>
                                        </template>
                                    </div>
                                </template>
                                <form @submit.prevent="enviarMensagem()" class="flex gap-2">
                                    <input x-model="novaMensagem" type="text"
                                           placeholder="Digite / para respostas prontas..."
                                           @input="onInputMensagem()"
                                           @keydown.escape="sugestoes = []"
                                           @keydown.arrow-up.prevent="navegarSugestao(-1)"
                                           @keydown.arrow-down.prevent="navegarSugestao(1)"
                                           @keydown.enter.prevent="sugestoes.length > 0 ? aplicarResposta(sugestoes[sugestaoSelecionada]) : enviarMensagem()"
                                           class="flex-1 border border-gray-300 rounded-xl px-4 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-green-500">
                                    <button type="button" @click="enviarMensagem()"
                                            class="bg-green-600 hover:bg-green-500 text-white px-4 py-2 rounded-xl text-sm transition-colors">
                                        Enviar
                                    </button>
                                </form>
                            </div>
                        </template>
                    </div>
                </template>

            </div>
        </div>
    </template>

    {{-- Modal encerrar --}}
    <template x-if="encerrarModal">
        <div class="fixed inset-0 bg-black/50 z-[60] flex items-center justify-center p-4">
            <div class="bg-white rounded-2xl p-6 w-full max-w-sm shadow-2xl">
                <h3 class="font-semibold text-gray-800 mb-4">Encerrar atendimento</h3>
                <label class="block text-sm text-gray-600 mb-2">Motivo de desfecho</label>
                <select x-model="tagDesfecho"
                        class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-green-500 mb-4">
                    <option value="">Selecione...</option>
                    <option value="venda_fechada">Venda fechada</option>
                    <option value="sem_interesse">Sem interesse</option>
                    <option value="preco_alto">Preço alto</option>
                    <option value="sem_resposta">Sem resposta</option>
                    <option value="fora_de_area">Fora de área</option>
                    <option value="outro">Outro</option>
                </select>
                <div class="flex gap-2">
                    <button @click="encerrarModal = false"
                            class="flex-1 border border-gray-300 text-gray-600 py-2 rounded-lg text-sm hover:bg-gray-50">
                        Cancelar
                    </button>
                    <button @click="encerrar()"
                            :disabled="!tagDesfecho"
                            class="flex-1 bg-red-600 hover:bg-red-500 disabled:opacity-40 text-white py-2 rounded-lg text-sm transition-colors">
                        Confirmar
                    </button>
                </div>
            </div>
        </div>
    </template>

</div>

<script>
function kanban() {
    return {
        colunas: [
            { key: 'lead_novo',           label: 'Novo' },
            { key: 'em_atendimento',      label: 'Em Atendimento' },
            { key: 'aguardando_orcamento',label: 'Aguardando Orçamento' },
            { key: 'aguardando_lead',     label: 'Aguardando Lead' },
            { key: 'encerrado',           label: 'Encerrado' },
        ],
        tickets:      {},
        ticketAtivo:  null,
        mensagens:    [],
        novaMensagem:      '',
        encerrarModal:     false,
        tagDesfecho:       '',
        intervalo:         null,
        editandoNome:      false,
        nomeEdit:          '',
        sugestoes:         [],
        sugestaoSelecionada: 0,
        notas:             [],
        notasAberto:       false,
        novaNota:          '',
        salvandoNota:      false,

        async carregar() {
            const res = await this.api('/api/painel/kanban/tickets');
            if (res.ok) this.tickets = await res.json();
        },

        async salvarNome() {
            const nome = this.nomeEdit.trim();
            if (!nome || !this.ticketAtivo?.contato?.id) return;

            const res = await this.api(`/api/painel/contato/${this.ticketAtivo.contato.id}`, 'PATCH', { nome });
            if (res.ok) {
                const data = await res.json();
                if (data.auditoria) {
                    // Nome vai para fila do Auditor — exibe localmente com badge
                    this.ticketAtivo.contato.nome_local        = nome;
                    this.ticketAtivo.contato.auditoria_pendente = true;
                } else {
                    // Master atualizado diretamente (nome estava vazio)
                    this.ticketAtivo.contato.nome       = nome;
                    this.ticketAtivo.contato.nome_local = null;
                }
                this.editandoNome = false;
                await this.carregar();
            }
        },

        async abrirTicket(ticket) {
            this.ticketAtivo  = ticket;
            this.editandoNome = false;
            this.novaMensagem = '';
            this.notas        = [];
            this.notasAberto  = false;
            this.novaNota     = '';
            await this.carregarMensagens(ticket.id);
            if (ticket.contato?.id) await this.carregarNotas(ticket.contato.id);
            this.$nextTick(() => {
                const box = this.$refs.msgBox;
                if (box) box.scrollTop = box.scrollHeight;
            });
        },

        async carregarNotas(contatoId) {
            const res = await this.api(`/api/painel/contato/${contatoId}/notas`);
            if (res.ok) {
                const json = await res.json();
                this.notas = json.data;
            }
        },

        async salvarNota() {
            const texto = this.novaNota.trim();
            if (!texto || !this.ticketAtivo?.contato?.id) return;
            this.salvandoNota = true;
            const res = await this.api(`/api/painel/contato/${this.ticketAtivo.contato.id}/notas`, 'POST', { texto });
            this.salvandoNota = false;
            if (res.ok) {
                this.novaNota = '';
                await this.carregarNotas(this.ticketAtivo.contato.id);
            }
        },

        async excluirNota(id) {
            if (!confirm('Excluir esta nota?')) return;
            const res = await this.api(`/api/painel/notas/${id}`, 'DELETE');
            if (res.ok) await this.carregarNotas(this.ticketAtivo.contato.id);
        },

        async marcarPendente(id) {
            const res = await this.api(`/api/painel/kanban/ticket/${id}/pendente`, 'POST');
            if (res.ok) {
                this.ticketAtivo.status = 'pendente';
                await this.carregar();
            }
        },

        async resolver(id) {
            if (!confirm('Marcar este atendimento como resolvido?')) return;
            const res = await this.api(`/api/painel/kanban/ticket/${id}/resolver`, 'POST');
            if (res.ok) {
                this.ticketAtivo = null;
                await this.carregar();
            }
        },

        async carregarMensagens(id) {
            const res = await this.api(`/api/painel/kanban/ticket/${id}/mensagens`);
            if (res.ok) this.mensagens = await res.json();
        },

        async assumir(id) {
            const res = await this.api(`/api/painel/kanban/ticket/${id}/assumir`, 'POST');
            if (res.ok) {
                await this.carregar();
                if (this.ticketAtivo?.id === id) {
                    const updated = Object.values(this.tickets).flat().find(t => t.id === id);
                    if (updated) this.ticketAtivo = updated;
                }
            }
        },

        async onInputMensagem() {
            const val = this.novaMensagem;
            if (val.startsWith('/') && val.length > 1) {
                const q   = val.slice(1);
                const res = await this.api(`/api/painel/respostas-prontas/buscar?q=${encodeURIComponent(q)}`);
                if (res.ok) {
                    const json = await res.json();
                    this.sugestoes = json.data;
                    this.sugestaoSelecionada = 0;
                }
            } else {
                this.sugestoes = [];
            }
        },

        aplicarResposta(s) {
            if (!s) return;
            this.novaMensagem = s.conteudo;
            this.sugestoes   = [];
        },

        navegarSugestao(dir) {
            if (!this.sugestoes.length) return;
            this.sugestaoSelecionada = Math.max(0, Math.min(
                this.sugestoes.length - 1,
                this.sugestaoSelecionada + dir
            ));
        },

        async enviarMensagem() {
            const conteudo = this.novaMensagem.trim();
            if (!conteudo || !this.ticketAtivo) return;
            this.novaMensagem = '';
            this.sugestoes   = [];
            const res = await this.api(`/api/painel/kanban/ticket/${this.ticketAtivo.id}/mensagem`, 'POST', { conteudo });
            if (res.ok) await this.carregarMensagens(this.ticketAtivo.id);
        },

        async encerrar() {
            if (!this.tagDesfecho || !this.ticketAtivo) return;
            const res = await this.api(`/api/painel/kanban/ticket/${this.ticketAtivo.id}/encerrar`, 'POST', { tag_desfecho: this.tagDesfecho });
            if (res.ok) {
                this.encerrarModal = false;
                this.ticketAtivo = null;
                await this.carregar();
            }
        },

        dataRelativa(dt) {
            if (!dt) return '';
            const diff = Math.floor((Date.now() - new Date(dt)) / 60000);
            if (diff < 1) return 'agora';
            if (diff < 60) return `${diff}min atrás`;
            if (diff < 1440) return `${Math.floor(diff/60)}h atrás`;
            return `${Math.floor(diff/1440)}d atrás`;
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

        init() {
            this.intervalo = setInterval(() => this.carregar(), 5000);
        },

        destroy() {
            clearInterval(this.intervalo);
        }
    };
}
</script>
@endsection
