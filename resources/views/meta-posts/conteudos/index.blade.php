@extends('layouts.app')

@section('title', 'Banco de Conteúdos — Lead Certo')

@section('content')
<div class="max-w-6xl mx-auto p-6" x-data="{
    modalCriar: false,
    modalEditar: false,
    editId: null,
    editTitulo: '',
    editCategoria: 'geral',
    editTexto: '',
    editImagemUrl: '',
    editCtaTipo: 'NENHUM',
    editCtaUrl: '',
    editGatilho: 'nenhum',
    editPalavrasChave: '',
    editRespostaPublica: '',
    editMensagemDirect: '',
    abrirEdicao(c) {
        this.editId = c.id;
        this.editTitulo = c.titulo;
        this.editCategoria = c.categoria;
        this.editTexto = c.texto;
        this.editImagemUrl = c.imagem_url || '';
        this.editCtaTipo = c.cta_tipo || 'NENHUM';
        this.editCtaUrl = c.cta_url || '';
        this.editGatilho = c.modo_gatilho || 'nenhum';
        this.editPalavrasChave = (c.palavras_chave || []).join(', ');
        this.editRespostaPublica = c.resposta_publica_comentario || '';
        this.editMensagemDirect = c.mensagem_direct || '';
        this.modalEditar = true;
    }
}">

    <div class="flex items-center justify-between mb-6 flex-wrap gap-4">
        <div>
            <h1 class="text-2xl font-bold text-gray-800">📚 Banco de Conteúdos</h1>
            <p class="text-sm text-gray-500 mt-1">
                Crie conteúdos prontos (texto, imagem, botão e gatilho de comentário) para reaproveitar em
                várias postagens ao longo do tempo, sem recriar tudo do zero a cada vez.
            </p>
        </div>
        <button @click="modalCriar = true"
                class="px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 text-sm font-semibold transition flex items-center gap-1.5 shadow-sm">
            + Novo Conteúdo
        </button>
    </div>

    @if(session('sucesso'))
        <div class="mb-4 p-3 bg-green-100 text-green-800 rounded-lg text-sm flex items-center gap-2">
            <span>✅</span>
            <span>{{ session('sucesso') }}</span>
        </div>
    @endif

    <div class="space-y-6">
        @forelse($conteudosPorCategoria as $categoria => $itens)
            <h2 class="text-sm font-semibold text-gray-600 uppercase tracking-wide">{{ $categoria }}</h2>

            @foreach($itens as $conteudo)
                <div class="bg-white rounded-xl shadow p-4 hover:shadow-md transition">
                    <div class="flex items-start justify-between gap-4">
                        <div class="flex items-center gap-2 flex-wrap">
                            @if($conteudo->ativo)
                                <span class="px-2 py-0.5 bg-green-100 text-green-700 rounded-full text-xs font-semibold">Ativo</span>
                            @else
                                <span class="px-2 py-0.5 bg-gray-100 text-gray-600 rounded-full text-xs font-semibold">Inativo</span>
                            @endif
                            @if($conteudo->imagem_url)
                                <span class="px-2 py-0.5 bg-blue-50 text-blue-700 border border-blue-200 rounded text-xs font-bold">🖼️ Com imagem</span>
                            @endif
                            @if($conteudo->modo_gatilho !== 'nenhum')
                                <span class="px-2 py-0.5 bg-emerald-50 text-emerald-700 border border-emerald-200 rounded text-xs font-bold">💬 Comment-to-DM</span>
                            @endif
                        </div>
                        <div class="flex gap-3 flex-shrink-0 items-center">
                            <form action="{{ route('meta-posts.conteudos.alternar-status', $conteudo) }}" method="POST" class="inline">
                                @csrf @method('PATCH')
                                <button type="submit" class="text-xs font-semibold {{ $conteudo->ativo ? 'text-gray-500 hover:underline' : 'text-green-600 hover:underline' }}">
                                    {{ $conteudo->ativo ? 'Desativar' : 'Ativar' }}
                                </button>
                            </form>
                            <button type="button" @click='abrirEdicao(@json($conteudo))' class="text-blue-600 hover:underline text-xs font-semibold">
                                Editar
                            </button>
                            <form action="{{ route('meta-posts.conteudos.destroy', $conteudo) }}" method="POST" class="inline">
                                @csrf @method('DELETE')
                                <button type="submit" class="text-red-500 hover:underline text-xs"
                                        onclick="return confirm('Remover este conteúdo do banco? Postagens já agendadas com ele não são afetadas.')">
                                    Excluir
                                </button>
                            </form>
                        </div>
                    </div>

                    <div class="mt-2">
                        <h3 class="font-bold text-gray-900 text-sm">{{ $conteudo->titulo }}</h3>
                        <p class="text-sm text-gray-700 mt-1 whitespace-pre-line leading-relaxed line-clamp-3">{{ $conteudo->texto }}</p>
                    </div>
                </div>
            @endforeach
        @empty
            <div class="bg-white rounded-xl shadow p-8 text-center text-gray-400">
                Nenhum conteúdo salvo ainda. Clique em "+ Novo Conteúdo" para criar o primeiro.
            </div>
        @endforelse
    </div>

    {{-- Modal: Criar Conteúdo --}}
    <div x-show="modalCriar"
         x-transition:enter="transition ease-out duration-100"
         x-transition:enter-start="opacity-0 scale-95"
         x-transition:enter-end="opacity-100 scale-100"
         class="fixed inset-0 z-50 bg-black/60 backdrop-blur-sm flex items-center justify-center p-4 overflow-y-auto"
         style="display: none;">

        <div class="bg-white rounded-2xl max-w-lg w-full p-6 space-y-4 shadow-2xl border border-gray-100 my-8" @click.outside="modalCriar = false">
            <div class="flex items-center justify-between border-b border-gray-100 pb-3">
                <h2 class="text-base font-bold text-gray-900 flex items-center gap-2">
                    <span>✨</span> <span>Novo Conteúdo</span>
                </h2>
                <button @click="modalCriar = false" class="text-gray-400 hover:text-gray-600 text-lg font-bold">✕</button>
            </div>

            <form action="{{ route('meta-posts.conteudos.store') }}" method="POST" class="space-y-3">
                @csrf
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="block text-xs font-bold text-gray-700 mb-1">Categoria</label>
                        <input type="text" name="categoria" value="geral" placeholder="Ex: promocoes" class="w-full bg-gray-50 border border-gray-200 rounded-xl px-3 py-2 text-xs focus:ring-2 focus:ring-green-500">
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-gray-700 mb-1">Título (interno)</label>
                        <input type="text" name="titulo" required placeholder="Ex: Promo fim de semana" class="w-full bg-gray-50 border border-gray-200 rounded-xl px-3 py-2 text-xs focus:ring-2 focus:ring-green-500">
                    </div>
                </div>

                <div>
                    <label class="block text-xs font-bold text-gray-700 mb-1">Texto / Legenda</label>
                    <textarea name="texto" rows="4" required class="w-full bg-gray-50 border border-gray-200 rounded-xl px-3 py-2 text-xs focus:ring-2 focus:ring-green-500"></textarea>
                </div>

                <div>
                    <label class="block text-xs font-bold text-gray-700 mb-1">Imagem — URL (opcional)</label>
                    <input type="url" name="imagem_url" placeholder="https://..." class="w-full bg-gray-50 border border-gray-200 rounded-xl px-3 py-2 text-xs focus:ring-2 focus:ring-green-500">
                    <a href="{{ route('meta-posts.imagens') }}" target="_blank" class="text-[11px] text-green-700 hover:underline">Ver banco de imagens da empresa →</a>
                </div>

                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="block text-xs font-bold text-gray-700 mb-1">Botão CTA</label>
                        <select name="cta_tipo" class="w-full bg-gray-50 border border-gray-200 rounded-xl px-3 py-2 text-xs focus:ring-2 focus:ring-green-500">
                            <option value="NENHUM">Sem botão</option>
                            <option value="LEARN_MORE">Saiba Mais</option>
                            <option value="BOOK">Agendar</option>
                            <option value="ORDER">Fazer Pedido</option>
                            <option value="SIGN_UP">Cadastre-se</option>
                            <option value="CALL">Ligar</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-gray-700 mb-1">Link do Botão</label>
                        <input type="url" name="cta_url" placeholder="https://wa.me/..." class="w-full bg-gray-50 border border-gray-200 rounded-xl px-3 py-2 text-xs focus:ring-2 focus:ring-green-500">
                    </div>
                </div>

                <div>
                    <label class="block text-xs font-bold text-gray-700 mb-1">Gatilho de Comentário (Comment-to-DM)</label>
                    <select name="modo_gatilho" class="w-full bg-gray-50 border border-gray-200 rounded-xl px-3 py-2 text-xs focus:ring-2 focus:ring-green-500">
                        <option value="nenhum">Desativado</option>
                        <option value="palavra_chave">Palavra-chave específica</option>
                        <option value="qualquer_comentario">Qualquer comentário</option>
                    </select>
                </div>

                <div>
                    <label class="block text-xs font-bold text-gray-700 mb-1">Palavras-chave (separadas por vírgula)</label>
                    <input type="text" name="palavras_chave_texto" placeholder="QUERO, FRETE, ORÇAMENTO" class="w-full bg-gray-50 border border-gray-200 rounded-xl px-3 py-2 text-xs focus:ring-2 focus:ring-green-500 uppercase">
                </div>

                <div>
                    <label class="block text-xs font-bold text-gray-700 mb-1">Resposta pública ao comentário</label>
                    <input type="text" name="resposta_publica_comentario" class="w-full bg-gray-50 border border-gray-200 rounded-xl px-3 py-2 text-xs focus:ring-2 focus:ring-green-500">
                </div>

                <div>
                    <label class="block text-xs font-bold text-gray-700 mb-1">Mensagem privada no Direct</label>
                    <textarea name="mensagem_direct" rows="2" class="w-full bg-gray-50 border border-gray-200 rounded-xl px-3 py-2 text-xs focus:ring-2 focus:ring-green-500"></textarea>
                </div>

                <div class="flex justify-end gap-2 pt-2">
                    <button type="button" @click="modalCriar = false" class="px-4 py-2 bg-gray-100 text-gray-700 rounded-xl text-xs font-semibold">Cancelar</button>
                    <button type="submit" class="px-5 py-2 bg-green-600 hover:bg-green-700 text-white font-bold rounded-xl text-xs shadow transition">Salvar Conteúdo</button>
                </div>
            </form>
        </div>
    </div>

    {{-- Modal: Editar Conteúdo --}}
    <div x-show="modalEditar"
         x-transition:enter="transition ease-out duration-100"
         x-transition:enter-start="opacity-0 scale-95"
         x-transition:enter-end="opacity-100 scale-100"
         class="fixed inset-0 z-50 bg-black/60 backdrop-blur-sm flex items-center justify-center p-4 overflow-y-auto"
         style="display: none;">

        <div class="bg-white rounded-2xl max-w-lg w-full p-6 space-y-4 shadow-2xl border border-gray-100 my-8" @click.outside="modalEditar = false">
            <div class="flex items-center justify-between border-b border-gray-100 pb-3">
                <h2 class="text-base font-bold text-gray-900 flex items-center gap-2">
                    <span>✏️</span> <span>Editar Conteúdo</span>
                </h2>
                <button @click="modalEditar = false" class="text-gray-400 hover:text-gray-600 text-lg font-bold">✕</button>
            </div>

            <form :action="'{{ url('meta-posts/conteudos') }}/' + editId" method="POST" class="space-y-3">
                @csrf
                @method('PUT')

                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="block text-xs font-bold text-gray-700 mb-1">Categoria</label>
                        <input type="text" name="categoria" x-model="editCategoria" class="w-full bg-gray-50 border border-gray-200 rounded-xl px-3 py-2 text-xs focus:ring-2 focus:ring-green-500">
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-gray-700 mb-1">Título (interno)</label>
                        <input type="text" name="titulo" x-model="editTitulo" required class="w-full bg-gray-50 border border-gray-200 rounded-xl px-3 py-2 text-xs focus:ring-2 focus:ring-green-500">
                    </div>
                </div>

                <div>
                    <label class="block text-xs font-bold text-gray-700 mb-1">Texto / Legenda</label>
                    <textarea name="texto" rows="4" x-model="editTexto" required class="w-full bg-gray-50 border border-gray-200 rounded-xl px-3 py-2 text-xs focus:ring-2 focus:ring-green-500"></textarea>
                </div>

                <div>
                    <label class="block text-xs font-bold text-gray-700 mb-1">Imagem — URL (opcional)</label>
                    <input type="url" name="imagem_url" x-model="editImagemUrl" class="w-full bg-gray-50 border border-gray-200 rounded-xl px-3 py-2 text-xs focus:ring-2 focus:ring-green-500">
                </div>

                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="block text-xs font-bold text-gray-700 mb-1">Botão CTA</label>
                        <select name="cta_tipo" x-model="editCtaTipo" class="w-full bg-gray-50 border border-gray-200 rounded-xl px-3 py-2 text-xs focus:ring-2 focus:ring-green-500">
                            <option value="NENHUM">Sem botão</option>
                            <option value="LEARN_MORE">Saiba Mais</option>
                            <option value="BOOK">Agendar</option>
                            <option value="ORDER">Fazer Pedido</option>
                            <option value="SIGN_UP">Cadastre-se</option>
                            <option value="CALL">Ligar</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-gray-700 mb-1">Link do Botão</label>
                        <input type="url" name="cta_url" x-model="editCtaUrl" class="w-full bg-gray-50 border border-gray-200 rounded-xl px-3 py-2 text-xs focus:ring-2 focus:ring-green-500">
                    </div>
                </div>

                <div>
                    <label class="block text-xs font-bold text-gray-700 mb-1">Gatilho de Comentário</label>
                    <select name="modo_gatilho" x-model="editGatilho" class="w-full bg-gray-50 border border-gray-200 rounded-xl px-3 py-2 text-xs focus:ring-2 focus:ring-green-500">
                        <option value="nenhum">Desativado</option>
                        <option value="palavra_chave">Palavra-chave específica</option>
                        <option value="qualquer_comentario">Qualquer comentário</option>
                    </select>
                </div>

                <div>
                    <label class="block text-xs font-bold text-gray-700 mb-1">Palavras-chave</label>
                    <input type="text" name="palavras_chave_texto" x-model="editPalavrasChave" class="w-full bg-gray-50 border border-gray-200 rounded-xl px-3 py-2 text-xs focus:ring-2 focus:ring-green-500 uppercase">
                </div>

                <div>
                    <label class="block text-xs font-bold text-gray-700 mb-1">Resposta pública ao comentário</label>
                    <input type="text" name="resposta_publica_comentario" x-model="editRespostaPublica" class="w-full bg-gray-50 border border-gray-200 rounded-xl px-3 py-2 text-xs focus:ring-2 focus:ring-green-500">
                </div>

                <div>
                    <label class="block text-xs font-bold text-gray-700 mb-1">Mensagem privada no Direct</label>
                    <textarea name="mensagem_direct" rows="2" x-model="editMensagemDirect" class="w-full bg-gray-50 border border-gray-200 rounded-xl px-3 py-2 text-xs focus:ring-2 focus:ring-green-500"></textarea>
                </div>

                <div class="flex justify-end gap-2 pt-2">
                    <button type="button" @click="modalEditar = false" class="px-4 py-2 bg-gray-100 text-gray-700 rounded-xl text-xs font-semibold">Cancelar</button>
                    <button type="submit" class="px-5 py-2 bg-blue-600 hover:bg-blue-700 text-white font-bold rounded-xl text-xs shadow transition">Atualizar</button>
                </div>
            </form>
        </div>
    </div>

</div>
@endsection
