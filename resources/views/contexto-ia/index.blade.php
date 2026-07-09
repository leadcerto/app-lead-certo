@extends('layouts.app')

@section('title', 'Contexto da IA')

@section('content')
<div class="max-w-4xl mx-auto px-4 py-8" x-data="contextoIa()">

    <div class="mb-6">
        <h1 class="text-2xl font-bold text-gray-900">Contexto da IA</h1>
        <p class="mt-1 text-sm text-gray-500 max-w-xl">
            Tudo que a IA sabe sobre o seu negócio: serviços, preços, processo de atendimento, tom de voz.
            Quanto mais completo, melhor ela responde.
        </p>
    </div>

    {{-- Aviso de geração --}}
    <template x-if="avisoGeracao">
        <div class="mb-4 p-3 rounded-lg text-sm"
            :class="avisoErro ? 'bg-red-50 text-red-700 border border-red-200' : 'bg-indigo-50 text-indigo-700 border border-indigo-200'"
            x-text="avisoGeracao">
        </div>
    </template>

    {{-- Editor principal --}}
    <div class="bg-white border border-gray-200 rounded-xl shadow-sm overflow-hidden">
        <div class="border-b border-gray-100 px-4 py-3 flex items-center justify-between bg-gray-50">
            <span class="text-xs font-medium text-gray-500 uppercase tracking-wide">Base de conhecimento</span>
            <span class="text-xs text-gray-400" x-text="contexto.length + ' caracteres'"></span>
        </div>
        <textarea
            x-model="contexto"
            placeholder="Descreva seu negócio aqui..."
            class="w-full text-sm text-gray-800 p-4 focus:outline-none resize-none font-mono leading-relaxed"
            style="min-height: 480px"
            @input="salvoRecentemente = false">
        </textarea>
        <div class="border-t border-gray-100 px-4 py-3 bg-gray-50">
            <p class="text-xs font-medium text-gray-400 uppercase tracking-wide mb-2">Exemplos do que incluir</p>
            <div class="grid grid-cols-2 gap-x-6 gap-y-1">
                <span class="text-xs text-gray-400">• Serviços oferecidos e preços</span>
                <span class="text-xs text-gray-400">• Horário de funcionamento</span>
                <span class="text-xs text-gray-400">• Área de atendimento (bairros, cidades)</span>
                <span class="text-xs text-gray-400">• Diferenciais / o que você NÃO faz</span>
                <span class="text-xs text-gray-400">• Processo de orçamento (como funciona)</span>
                <span class="text-xs text-gray-400">• Formas de pagamento aceitas</span>
                <span class="text-xs text-gray-400">• Capacidade máxima de atendimentos/dia</span>
                <span class="text-xs text-gray-400">• Perguntas frequentes com respostas padrão</span>
            </div>
        </div>
    </div>

    {{-- Barra inferior --}}
    <div class="mt-4 flex items-center justify-between">
        <span class="text-xs text-gray-400" x-show="salvoRecentemente">
            ✓ Salvo
        </span>
        <span class="text-xs text-gray-400" x-show="!salvoRecentemente && !salvando">
            Alterações não salvas
        </span>
        <span class="text-xs text-gray-400" x-show="salvando">
            Salvando...
        </span>
        <button @click="salvar"
            :disabled="salvando"
            class="ml-auto bg-green-600 text-white text-sm font-medium px-6 py-2 rounded-lg hover:bg-green-700 disabled:opacity-50">
            <span x-text="salvando ? 'Salvando...' : 'Salvar'"></span>
        </button>
    </div>

    {{-- Tabela de Preços (PDF) --}}
    <div class="mt-4" style="border:1px solid #fca5a5;border-radius:12px;overflow:hidden;background:#fff;box-shadow:0 1px 3px rgba(0,0,0,.06);">

        {{-- Cabeçalho --}}
        <div style="padding:16px 20px;background:#fff1f2;border-bottom:1px solid #fecaca;display:flex;align-items:center;gap:12px;">
            <div style="width:36px;height:36px;border-radius:8px;background:#dc2626;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                <svg style="width:20px;height:20px;" fill="none" stroke="#fff" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                          d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                </svg>
            </div>
            <div>
                <p style="font-weight:600;font-size:15px;color:#7f1d1d;margin:0;line-height:1.2;">Tabela de Preços</p>
                <p style="font-size:12px;color:#dc2626;margin:3px 0 0 0;">PDF com valores, produtos e condições — a IA usa nos atendimentos</p>
            </div>
        </div>

        {{-- Corpo --}}
        <div x-data="tabelaPdf()" x-init="init()" style="padding:20px;display:flex;align-items:center;gap:24px;">
            <div style="flex:1;">
                <template x-if="!arquivoNome">
                    <div>
                        <p style="font-size:14px;color:#374151;line-height:1.65;margin:0 0 8px 0;">
                            Faça upload de um PDF com sua tabela de preços — produtos, valores, formas de pagamento e condições.
                            A IA lê o conteúdo e usa as informações durante o atendimento.
                        </p>
                        <p style="font-size:13px;color:#6b7280;margin:0;">Use PDFs gerados digitalmente (não imagens escaneadas).</p>
                    </div>
                </template>
                <template x-if="arquivoNome">
                    <div>
                        <div style="display:flex;align-items:center;gap:10px;margin-bottom:8px;">
                            <svg style="width:20px;height:20px;color:#dc2626;flex-shrink:0;" fill="currentColor" viewBox="0 0 20 20">
                                <path fill-rule="evenodd" d="M4 4a2 2 0 012-2h4.586A2 2 0 0112 2.586L15.414 6A2 2 0 0116 7.414V16a2 2 0 01-2 2H6a2 2 0 01-2-2V4zm2 6a1 1 0 011-1h6a1 1 0 110 2H7a1 1 0 01-1-1zm1 3a1 1 0 100 2h6a1 1 0 100-2H7z" clip-rule="evenodd"/>
                            </svg>
                            <span style="font-size:14px;font-weight:500;color:#111827;" x-text="arquivoNome"></span>
                        </div>
                        <p style="font-size:13px;color:#6b7280;margin:0;">
                            <span x-text="arquivoChars.toLocaleString('pt-BR')"></span> caracteres extraídos · A IA está usando esta tabela
                        </p>
                    </div>
                </template>
                <template x-if="aviso">
                    <p style="font-size:13px;margin:8px 0 0 0;"
                       :style="avisoErro ? 'color:#dc2626;' : 'color:#16a34a;'"
                       x-text="aviso"></p>
                </template>
            </div>

            {{-- Botões --}}
            <div style="flex-shrink:0;display:flex;flex-direction:column;gap:8px;align-items:flex-end;">
                <label :style="enviando
                        ? 'display:flex;align-items:center;gap:8px;background:#dc2626;color:#fff;border:none;border-radius:8px;padding:8px 18px;font-size:14px;font-weight:600;white-space:nowrap;opacity:0.55;pointer-events:none;cursor:not-allowed;'
                        : 'display:flex;align-items:center;gap:8px;background:#dc2626;color:#fff;border:none;border-radius:8px;padding:8px 18px;font-size:14px;font-weight:600;white-space:nowrap;cursor:pointer;'"
                       style="display:flex;align-items:center;gap:8px;background:#dc2626;color:#fff;border-radius:8px;padding:8px 18px;font-size:14px;font-weight:600;white-space:nowrap;cursor:pointer;">
                    <svg x-show="enviando" style="display:none;width:14px;height:14px;" fill="none" viewBox="0 0 24 24">
                        <circle style="opacity:.25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path style="opacity:.75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                    </svg>
                    <svg x-show="!enviando" style="width:14px;height:14px;" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12"/>
                    </svg>
                    <span x-text="enviando ? 'Enviando...' : (arquivoNome ? 'Substituir PDF' : 'Enviar PDF')">Enviar PDF</span>
                    <input type="file" accept=".pdf" style="display:none" @change="upload($event)" :disabled="enviando">
                </label>

                <button x-show="arquivoNome" @click="remover"
                    style="display:none;align-items:center;gap:6px;background:transparent;border:1px solid #fca5a5;color:#dc2626;border-radius:8px;padding:6px 14px;font-size:13px;cursor:pointer;white-space:nowrap;">
                    <svg style="width:13px;height:13px;" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                    </svg>
                    Remover
                </button>
            </div>
        </div>
    </div>

    {{-- Leitor de Conversas --}}
    <div class="mt-6" style="border:1px solid #d1d5db;border-radius:12px;overflow:hidden;background:#fff;box-shadow:0 1px 3px rgba(0,0,0,.06);">

        {{-- Cabeçalho do card --}}
        <div style="padding:16px 20px;background:#f0fdf4;border-bottom:1px solid #bbf7d0;display:flex;align-items:center;gap:12px;">
            <div style="width:36px;height:36px;border-radius:8px;background:#16a34a;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                <svg style="width:20px;height:20px;" fill="none" stroke="#fff" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                          d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"/>
                </svg>
            </div>
            <div>
                <p style="font-weight:600;font-size:15px;color:#14532d;margin:0;line-height:1.2;">Leitor de Conversas</p>
                <p style="font-size:12px;color:#16a34a;margin:3px 0 0 0;">Enriquece a base de conhecimento lendo os atendimentos do WhatsApp</p>
            </div>
        </div>

        {{-- Corpo do card --}}
        <div style="padding:20px;display:flex;align-items:center;gap:24px;">
            <div style="flex:1;">
                <p style="font-size:14px;color:#374151;line-height:1.65;margin:0 0 10px 0;">
                    Analisa suas conversas de WhatsApp e enriquece automaticamente esta base de conhecimento com serviços, preços e padrões que aparecem nos atendimentos reais.
                </p>
                <div style="display:flex;flex-direction:column;gap:6px;">
                    <p style="font-size:13px;color:#6b7280;margin:0;display:flex;align-items:flex-start;gap:6px;">
                        <span style="color:#16a34a;font-weight:600;flex-shrink:0;">🔄 Automático</span>
                        Roda diariamente e lê as conversas das últimas 24 horas — a base se atualiza sozinha conforme os atendimentos evoluem.
                    </p>
                    <p style="font-size:13px;color:#6b7280;margin:0;display:flex;align-items:flex-start;gap:6px;">
                        <span style="color:#16a34a;font-weight:600;flex-shrink:0;">▶ Manual</span>
                        Use o botão ao lado para forçar uma leitura agora, sem esperar o ciclo diário.
                    </p>
                </div>
            </div>
            <button @click="gerar" :disabled="gerando"
                class="bg-green-600 text-white text-sm font-medium px-6 py-2 rounded-lg hover:bg-green-700 disabled:opacity-50"
                style="flex-shrink:0;display:flex;align-items:center;gap:8px;white-space:nowrap;border:none;cursor:pointer;">
                <svg x-show="gerando" style="display:none;width:14px;height:14px;" fill="none" viewBox="0 0 24 24">
                    <circle style="opacity:.25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                    <path style="opacity:.75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                </svg>
                <span x-text="gerando ? 'Lendo conversas...' : 'Ler conversas agora'">Ler conversas agora</span>
            </button>
        </div>
    </div>

</div>
@endsection

@push('scripts')
<script>
function tabelaPdf() {
    return {
        arquivoNome:  null,
        arquivoChars: 0,
        enviando:     false,
        aviso:        '',
        avisoErro:    false,

        async init() {
            const res  = await fetch('/api/painel/contexto-ia/dados');
            const data = await res.json();
            this.arquivoNome  = data.tabela_precos_nome  || null;
            this.arquivoChars = data.tabela_precos_chars || 0;
        },

        async upload(event) {
            const file = event.target.files[0];
            if (!file) return;

            this.enviando = true;
            this.aviso    = '';
            this.avisoErro = false;

            const form = new FormData();
            form.append('pdf', file);
            form.append('_token', '{{ csrf_token() }}');

            const res  = await fetch('/api/painel/contexto-ia/tabela-precos', { method: 'POST', body: form });
            const data = await res.json();

            this.enviando = false;

            if (!res.ok) {
                this.avisoErro = true;
                this.aviso = data.error || 'Erro ao enviar o PDF.';
                return;
            }

            this.arquivoNome  = data.nome;
            this.arquivoChars = data.chars;
            this.aviso = 'PDF carregado com sucesso! ' + data.chars.toLocaleString('pt-BR') + ' caracteres extraídos.';

            // reset input
            event.target.value = '';
        },

        async remover() {
            if (!confirm('Remover a tabela de preços?')) return;

            const res = await fetch('/api/painel/contexto-ia/tabela-precos', {
                method:  'DELETE',
                headers: { 'X-CSRF-TOKEN': '{{ csrf_token() }}' },
            });

            if (res.ok) {
                this.arquivoNome  = null;
                this.arquivoChars = 0;
                this.aviso = '';
            }
        },
    };
}

function contextoIa() {
    return {
        contexto: '',
        salvando: false,
        salvoRecentemente: false,
        gerando: false,
        avisoGeracao: '',
        avisoErro: false,

        async init() {
            const res = await fetch('/api/painel/contexto-ia/dados');
            const data = await res.json();
            this.contexto = data.ia_contexto || '';
            this.salvoRecentemente = true;
        },

        async salvar() {
            this.salvando = true;
            await fetch('/api/painel/contexto-ia/dados', {
                method: 'PUT',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': '{{ csrf_token() }}',
                },
                body: JSON.stringify({ ia_contexto: this.contexto }),
            });
            this.salvando = false;
            this.salvoRecentemente = true;
        },

        async gerar() {
            this.gerando = true;
            this.avisoGeracao = '';
            this.avisoErro = false;

            const res = await fetch('/api/painel/contexto-ia/gerar', {
                method: 'POST',
                headers: { 'X-CSRF-TOKEN': '{{ csrf_token() }}' },
            });

            const data = await res.json();
            this.gerando = false;

            if (!res.ok) {
                this.avisoErro = true;
                this.avisoGeracao = data.error || 'Erro ao gerar contexto.';
                return;
            }

            // Preenche o editor com o texto gerado (substitui)
            this.contexto = data.ia_contexto;
            this.salvoRecentemente = false;
            this.avisoGeracao = 'Base de conhecimento atualizada com as conversas! Revise as informações adicionadas e clique em Salvar.';
        },
    }
}
</script>
@endpush
