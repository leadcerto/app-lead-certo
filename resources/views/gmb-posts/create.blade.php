@extends('layouts.app')

@section('title', 'Nova Publicação — Google Meu Negócio')

@section('content')
<div class="p-6 max-w-7xl mx-auto space-y-6" x-data="gmbPostForm()">

    {{-- Breadcrumb / Header --}}
    <div class="flex items-center justify-between border-b border-gray-200 pb-4">
        <div>
            <div class="flex items-center gap-2 text-xs text-gray-500 mb-1">
                <a href="{{ route('admin.gmb-posts.index') }}" class="hover:underline">Google Meu Negócio</a>
                <span>/</span>
                <span class="text-gray-700">Nova Publicação</span>
            </div>
            <h1 class="text-2xl font-bold text-gray-900 font-heading">Criar & Agendar Publicação (Google Post)</h1>
        </div>
        <a href="{{ route('admin.gmb-posts.index') }}" class="px-4 py-2 bg-gray-100 hover:bg-gray-200 text-gray-700 text-sm font-medium rounded-lg transition">
            Voltar
        </a>
    </div>

    {{-- Caixa Mágica: Gerador com IA --}}
    <div class="bg-gradient-to-r from-purple-50 via-indigo-50 to-blue-50 border border-purple-200 rounded-2xl p-6 shadow-sm">
        <div class="flex items-start justify-between">
            <div class="flex items-center gap-3">
                <div class="p-2.5 bg-purple-600 text-white rounded-xl shadow-md">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/>
                    </svg>
                </div>
                <div>
                    <h2 class="text-lg font-bold text-gray-900 font-heading">Assistente de Copywriting com IA</h2>
                    <p class="text-xs text-gray-600">Gere textos persuasivos e otimizados para ranqueamento local com 1 clique.</p>
                </div>
            </div>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mt-5">
            <div>
                <label class="block text-xs font-semibold text-gray-700 mb-1">Perfil da Empresa</label>
                <select x-model="iaPerfilId" class="w-full text-sm border-gray-300 rounded-lg bg-white">
                    <option value="">Selecione o Perfil</option>
                    @foreach($perfis as $p)
                        <option value="{{ $p->id }}">{{ $p->nome }} ({{ $p->city }})</option>
                    @endforeach
                </select>
            </div>

            <div>
                <label class="block text-xs font-semibold text-gray-700 mb-1">Objetivo da Postagem</label>
                <select x-model="iaObjetivo" class="w-full text-sm border-gray-300 rounded-lg bg-white">
                    <option value="Atrair novos clientes locais e ligações">Atrair Novos Clientes (Geral)</option>
                    <option value="Divulgar oferta relâmpago ou desconto">Promoção / Desconto Especial</option>
                    <option value="Tirar dúvidas comuns e reforçar autoridade">Dicas / Como Funciona</option>
                    <option value="Apresentar novidades nos serviços ou cardápio">Novidade / Lançamento</option>
                    <option value="Reforçar agilidade e depoimento de satisfação">Prova Social / Confiança</option>
                </select>
            </div>

            <div>
                <label class="block text-xs font-semibold text-gray-700 mb-1">Tema Livre / Instrução (Opcional)</label>
                <input type="text" x-model="iaTema" placeholder="Ex: Frete no fim de semana, Pizza de calabresa..." class="w-full text-sm border-gray-300 rounded-lg bg-white">
            </div>

            <div class="flex items-end">
                <button type="button" @click="gerarComIa()" :disabled="carregandoIa || !iaPerfilId" class="w-full py-2.5 px-4 bg-purple-600 hover:bg-purple-700 disabled:opacity-50 text-white text-sm font-semibold rounded-lg shadow transition flex items-center justify-center gap-2">
                    <template x-if="!carregandoIa">
                        <span class="flex items-center gap-1.5">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19.428 15.428a2 2 0 00-1.022-.547l-2.387-.477a6 6 0 00-3.86.517l-.318.158a6 6 0 01-3.86.517L6.05 15.21a2 2 0 00-1.806.547M8 4h8l-1 1v5.172a2 2 0 00.586 1.414l5 5c1.26 1.26.367 3.414-1.415 3.414H4.828c-1.782 0-2.674-2.154-1.414-3.414l5-5A2 2 0 009 10.172V5L8 4z"/></svg>
                            Gerar com IA
                        </span>
                    </template>
                    <template x-if="carregandoIa">
                        <span class="flex items-center gap-1.5">
                            <svg class="animate-spin w-4 h-4" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8H4z"></path></svg>
                            Criando Copy...
                        </span>
                    </template>
                </button>
            </div>
        </div>

        <div x-show="dicaSeo" x-transition class="mt-4 p-3 bg-purple-100/70 border border-purple-200 rounded-lg text-xs text-purple-900 flex items-center gap-2">
            <span class="font-bold">💡 Dica de SEO:</span>
            <span x-text="dicaSeo"></span>
        </div>
    </div>

    {{-- Grid Principal: Formulário + Preview ao Vivo --}}
    <div class="grid grid-cols-1 lg:grid-cols-12 gap-8 items-start">

        {{-- Lado Esquerdo: Formulário (7 Colunas) --}}
        <div class="lg:col-span-7 bg-white p-6 rounded-2xl border border-gray-200 shadow-sm space-y-5">
            <form method="POST" action="{{ route('admin.gmb-posts.store') }}" enctype="multipart/form-data">
                @csrf
                <input type="hidden" name="gerado_por_ia" :value="geradoPorIa ? 1 : 0">

                <div class="space-y-4">
                    {{-- Seleção do Perfil --}}
                    <div>
                        <label class="block text-sm font-semibold text-gray-800 mb-1">Perfil no Google Meu Negócio *</label>
                        <select name="perfil_gmb_id" x-model="perfilId" required class="w-full text-sm border-gray-300 rounded-lg focus:ring-green-500 focus:border-green-500">
                            <option value="">Selecione a Empresa / Unidade</option>
                            @foreach($perfis as $p)
                                <option value="{{ $p->id }}" data-nome="{{ $p->nome }}">{{ $p->nome }} — {{ $p->city }}/{{ $p->state }}</option>
                            @endforeach
                        </select>
                    </div>

                    {{-- Formato do Post --}}
                    <div>
                        <label class="block text-sm font-semibold text-gray-800 mb-1">Tipo de Publicação *</label>
                        <div class="grid grid-cols-3 gap-3">
                            <label class="flex items-center justify-center gap-2 p-3 border rounded-xl cursor-pointer text-sm font-medium transition" :class="tipo === 'novidade' ? 'bg-green-50 border-green-500 text-green-800' : 'border-gray-200 text-gray-700 hover:bg-gray-50'">
                                <input type="radio" name="tipo" value="novidade" x-model="tipo" class="hidden">
                                <span>📰 Novidade</span>
                            </label>

                            <label class="flex items-center justify-center gap-2 p-3 border rounded-xl cursor-pointer text-sm font-medium transition" :class="tipo === 'oferta' ? 'bg-green-50 border-green-500 text-green-800' : 'border-gray-200 text-gray-700 hover:bg-gray-50'">
                                <input type="radio" name="tipo" value="oferta" x-model="tipo" class="hidden">
                                <span>🏷️ Oferta</span>
                            </label>

                            <label class="flex items-center justify-center gap-2 p-3 border rounded-xl cursor-pointer text-sm font-medium transition" :class="tipo === 'evento' ? 'bg-green-50 border-green-500 text-green-800' : 'border-gray-200 text-gray-700 hover:bg-gray-50'">
                                <input type="radio" name="tipo" value="evento" x-model="tipo" class="hidden">
                                <span>📅 Evento</span>
                            </label>
                        </div>
                    </div>

                    {{-- Título (se oferta ou evento) --}}
                    <div x-show="tipo !== 'novidade'" x-transition>
                        <label class="block text-sm font-semibold text-gray-800 mb-1">Título do Destaque / Oferta *</label>
                        <input type="text" name="titulo" x-model="titulo" placeholder="Ex: Super Promoção de Primavera ou Feirão de Leilões" class="w-full text-sm border-gray-300 rounded-lg">
                    </div>

                    {{-- Texto / Copy --}}
                    <div>
                        <div class="flex justify-between items-center mb-1">
                            <label class="block text-sm font-semibold text-gray-800">Texto da Publicação *</label>
                            <span class="text-xs text-gray-400 font-mono" :class="texto.length > 1400 ? 'text-red-500 font-bold' : ''" x-text="texto.length + '/1500 caracteres'"></span>
                        </div>
                        <textarea name="texto" x-model="texto" rows="5" required placeholder="Escreva a mensagem que seus clientes verão no Google..." class="w-full text-sm border-gray-300 rounded-lg focus:ring-green-500 focus:border-green-500"></textarea>
                    </div>

                    {{-- Upload de Imagem e SEO --}}
                    <div class="p-4 bg-gray-50 rounded-xl border border-gray-200 space-y-3">
                        <div class="flex items-center justify-between">
                            <label class="block text-xs font-bold text-gray-800 uppercase tracking-wide">📷 Imagem do Post (SEO Automático)</label>
                            <span class="text-[11px] text-green-700 font-semibold">✨ Renomeação Inteligente</span>
                        </div>
                        
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                            <div>
                                <label class="block text-xs text-gray-600 mb-1">Enviar do Computador</label>
                                <input type="file" name="imagem" accept="image/*" @change="if ($event.target.files.length) { imagemUrl = URL.createObjectURL($event.target.files[0]); }" class="w-full text-xs text-gray-700 file:mr-2 file:py-1.5 file:px-3 file:rounded-lg file:border-0 file:text-xs file:font-semibold file:bg-green-100 file:text-green-800 hover:file:bg-green-200">
                            </div>
                            <div>
                                <label class="block text-xs text-gray-600 mb-1">Ou colar URL da Imagem</label>
                                <input type="url" name="imagem_url" x-model="imagemUrl" placeholder="https://..." class="w-full text-xs border-gray-300 rounded-lg bg-white">
                            </div>
                        </div>
                        <p class="text-[11px] text-gray-500">
                            A imagem será automaticamente renomeada com palavras-chave do seu negócio, bairro e data/hora do post para potencializar o ranqueamento no Google Maps.
                        </p>
                    </div>

                    {{-- Botão CTA (Ação) --}}
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-semibold text-gray-800 mb-1">Botão de Ação (CTA)</label>
                            <select name="cta_tipo" x-model="ctaTipo" class="w-full text-sm border-gray-300 rounded-lg">
                                <option value="LEARN_MORE">Saiba Mais (Padrão)</option>
                                <option value="CALL">Ligar Agora</option>
                                <option value="ORDER">Fazer Pedido Online</option>
                                <option value="BOOK">Agendar</option>
                                <option value="SIGN_UP">Cadastre-se</option>
                                <option value="NENHUM">Sem Botão</option>
                            </select>
                        </div>

                        <div x-show="ctaTipo !== 'NENHUM' && ctaTipo !== 'CALL'">
                            <label class="block text-sm font-semibold text-gray-800 mb-1">Link de Destino do Botão</label>
                            <input type="url" name="cta_url" x-model="ctaUrl" placeholder="https://wa.me/55... ou link da página" class="w-full text-sm border-gray-300 rounded-lg">
                        </div>
                    </div>

                    {{-- Campos de Oferta --}}
                    <div x-show="tipo === 'oferta'" x-transition class="p-4 bg-gray-50 border border-gray-200 rounded-xl space-y-3">
                        <h4 class="text-xs font-bold text-gray-700 uppercase tracking-wider">Regras da Oferta</h4>
                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <label class="block text-xs font-medium text-gray-600 mb-1">Código do Cupom</label>
                                <input type="text" name="codigo_cupom" x-model="codigoCupom" placeholder="Ex: PROMO10" class="w-full text-sm border-gray-300 rounded-lg">
                            </div>
                            <div>
                                <label class="block text-xs font-medium text-gray-600 mb-1">Link para Resgate</label>
                                <input type="url" name="link_resgate" x-model="linkResgate" placeholder="https://..." class="w-full text-sm border-gray-300 rounded-lg">
                            </div>
                        </div>
                    </div>

                    {{-- Data e Hora do Agendamento --}}
                    <div class="pt-2 border-t border-gray-100">
                        <div class="flex items-center justify-between mb-2">
                            <label class="text-sm font-semibold text-gray-800">Agendamento *</label>
                            <label class="flex items-center gap-2 cursor-pointer text-xs font-medium text-gray-600">
                                <input type="checkbox" name="publicar_imediato" x-model="publicarImediato" class="rounded text-green-600 focus:ring-green-500">
                                Publicar Imediatamente
                            </label>
                        </div>

                        <div x-show="!publicarImediato" x-transition>
                            <input type="datetime-local" name="data_agendada" x-model="dataAgendada" required class="w-full text-sm border-gray-300 rounded-lg">
                            <p class="text-xs text-gray-400 mt-1">O sistema publicará automaticamente no Google Meu Negócio no horário programado.</p>
                        </div>
                    </div>

                    {{-- Botões de Submissão --}}
                    <div class="pt-4 flex items-center justify-end gap-3">
                        <a href="{{ route('admin.gmb-posts.index') }}" class="px-5 py-2.5 text-sm font-medium text-gray-600 hover:text-gray-800">
                            Cancelar
                        </a>
                        <button type="submit" class="px-6 py-2.5 bg-green-600 hover:bg-green-700 text-white text-sm font-bold rounded-xl shadow-md transition">
                            <span x-text="publicarImediato ? '🚀 Publicar Agora no Google' : '📅 Confirmar Agendamento'"></span>
                        </button>
                    </div>
                </div>
            </form>
        </div>

        {{-- Lado Direito: Live Preview Google Post (5 Colunas) --}}
        <div class="lg:col-span-5 sticky top-6 space-y-3">
            <div class="flex items-center justify-between">
                <span class="text-xs font-bold text-gray-500 uppercase tracking-wider">Preview no Google Maps / Busca</span>
                <span class="text-xs text-green-700 bg-green-100 px-2 py-0.5 rounded font-medium">Tempo Real</span>
            </div>

            {{-- Google Post Mockup Card --}}
            <div class="bg-white rounded-2xl border border-gray-300 shadow-lg overflow-hidden max-w-sm mx-auto">
                {{-- Topo Perfil --}}
                <div class="p-4 border-b border-gray-100 flex items-center gap-3">
                    <div class="w-10 h-10 rounded-full bg-blue-600 text-white font-bold flex items-center justify-center text-sm">
                        G
                    </div>
                    <div>
                        <p class="text-sm font-bold text-gray-900 leading-tight" x-text="nomePerfilSelecionado || 'Nome da Sua Empresa'"></p>
                        <p class="text-xs text-gray-500">Publicado no Google • Agora</p>
                    </div>
                </div>

                {{-- Imagem --}}
                <template x-if="imagemUrl">
                    <img :src="imagemUrl" class="w-full h-48 object-cover bg-gray-100">
                </template>
                <template x-if="!imagemUrl">
                    <div class="w-full h-40 bg-gray-100 flex flex-col items-center justify-center text-gray-400 text-xs">
                        <svg class="w-8 h-8 mb-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                        </svg>
                        Insira a URL da foto para preview
                    </div>
                </template>

                {{-- Conteúdo do Card --}}
                <div class="p-4 space-y-2.5">
                    <template x-if="titulo && tipo !== 'novidade'">
                        <h4 class="text-sm font-bold text-gray-900 font-heading" x-text="titulo"></h4>
                    </template>

                    <p class="text-xs text-gray-700 leading-relaxed whitespace-pre-line" x-text="texto || 'O texto digitado aparecerá aqui como seus clientes verão no Google Meu Negócio...'"></p>

                    <template x-if="codigoCupom && tipo === 'oferta'">
                        <div class="p-2 bg-amber-50 border border-amber-200 rounded text-xs text-amber-800 font-mono text-center">
                            Cupom: <strong x-text="codigoCupom"></strong>
                        </div>
                    </template>

                    {{-- Botão CTA Simulado --}}
                    <template x-if="ctaTipo !== 'NENHUM'">
                        <div class="pt-2">
                            <button type="button" class="w-full py-2 bg-blue-600 text-white rounded-lg text-xs font-semibold text-center shadow-sm">
                                <span x-text="labelCta(ctaTipo)"></span>
                            </button>
                        </div>
                    </template>
                </div>
            </div>
        </div>

    </div>

</div>

<script>
function gmbPostForm() {
    return {
        perfilId: '',
        tipo: 'novidade',
        titulo: '',
        texto: '',
        imagemUrl: '',
        ctaTipo: 'LEARN_MORE',
        ctaUrl: '',
        codigoCupom: '',
        linkResgate: '',
        dataAgendada: new Date(Date.now() + 3600000).toISOString().slice(0, 16),
        publicarImediato: false,
        geradoPorIa: false,

        // IA Assistant State
        iaPerfilId: '',
        iaObjetivo: 'Atrair novos clientes locais e ligações',
        iaTema: '',
        carregandoIa: false,
        dicaSeo: '',

        get nomePerfilSelecionado() {
            const el = document.querySelector(`select[name="perfil_gmb_id"] option[value="${this.perfilId}"]`);
            return el ? el.textContent.split('—')[0].trim() : '';
        },

        labelCta(tipo) {
            const mapa = {
                'LEARN_MORE': 'Saiba Mais',
                'CALL': 'Ligar Agora',
                'ORDER': 'Fazer Pedido Online',
                'BOOK': 'Agendar',
                'SIGN_UP': 'Cadastre-se',
                'SHOP': 'Comprar'
            };
            return mapa[tipo] || 'Saiba Mais';
        },

        async gerarComIa() {
            if (!this.iaPerfilId) return;

            this.carregandoIa = true;
            this.dicaSeo = '';

            try {
                const res = await fetch('{{ route("admin.gmb-posts.gerar-ia") }}', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                        'Accept': 'application/json'
                    },
                    body: JSON.stringify({
                        perfil_gmb_id: this.iaPerfilId,
                        tipo: this.tipo,
                        objetivo: this.iaObjetivo,
                        tema: this.iaTema
                    })
                });

                const json = await res.json();

                if (json.success && json.data) {
                    this.perfilId = this.iaPerfilId;
                    this.texto = json.data.texto || '';
                    if (json.data.titulo) this.titulo = json.data.titulo;
                    if (json.data.cta_tipo) this.ctaTipo = json.data.cta_tipo;
                    if (json.data.dica_seo) this.dicaSeo = json.data.dica_seo;
                    this.geradoPorIa = true;
                } else {
                    alert(json.message || 'Erro ao gerar copy.');
                }
            } catch (err) {
                alert('Erro de comunicação com o servidor.');
            } finally {
                this.carregandoIa = false;
            }
        }
    }
}
</script>
@endsection
