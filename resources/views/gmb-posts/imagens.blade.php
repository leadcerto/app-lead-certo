@extends('layouts.app')
@section('title', 'Galeria de Imagens (SEO) — Lead Certo')

@section('content')
<div class="max-w-7xl mx-auto space-y-6" x-data="{ modalPreview: false, imgPreviewUrl: '', imgPreviewTitulo: '' }">

    {{-- Header --}}
    <div class="flex items-center justify-between flex-wrap gap-4">
        <div>
            <h1 class="text-2xl font-bold text-gray-800">🖼️ Galeria de Imagens & SEO Local</h1>
            <p class="text-sm text-gray-500 mt-1">
                Banco de imagens da sua empresa para publicações no Google Meu Negócio (proporção ideal: <strong>1200 × 900 px — 4:3</strong>). Ao fazer upload, as imagens são salvas e renomeadas automaticamente com palavras-chave estratégicas para rankeamento no Google Maps e Google Imagens.
            </p>
        </div>
        <div class="flex gap-2">
            <a href="{{ route('admin.gmb-posts.lote') }}" class="px-3.5 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 text-xs font-semibold transition">
                📊 Gerador em Lote
            </a>
            <a href="{{ route('admin.gmb-posts.index') }}" class="px-3.5 py-2 bg-gray-100 text-gray-700 rounded-lg hover:bg-gray-200 text-xs font-semibold transition">
                ← Agenda de Postagens
            </a>
        </div>
    </div>

    @if(session('sucesso'))
        <div class="p-3 bg-green-100 text-green-800 rounded-xl text-sm flex items-center gap-2">
            <span>✅</span>
            <span>{{ session('sucesso') }}</span>
        </div>
    @endif
    @if(isset($errors) && $errors->any())
        <div class="p-3 bg-red-100 text-red-800 rounded-xl text-sm">
            @foreach($errors->all() as $error) <p>• {{ $error }}</p> @endforeach
        </div>
    @endif

    {{-- Formulário de Upload de Imagens com SEO --}}
    <form action="{{ route('admin.gmb-posts.imagens.store') }}" method="POST" enctype="multipart/form-data"
          class="bg-white rounded-2xl shadow-sm border border-gray-200 p-5 space-y-4">
        @csrf

        <div class="flex items-center justify-between border-b border-gray-100 pb-3 flex-wrap gap-2">
            <h2 class="text-base font-bold text-gray-800 flex items-center gap-2">
                <span>📤</span>
                <span>Adicionar Novas Imagens (1200 × 900 px Recomendado)</span>
            </h2>
            <span class="text-xs text-green-700 font-semibold bg-green-50 px-3 py-1.5 rounded-lg border border-green-200">
                ✨ Renomeação Automática SEO
            </span>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-3 gap-5">
            <div>
                <label class="block text-sm font-semibold text-gray-700 mb-1.5">Selecionar Imagens (Múltiplas) *</label>
                <input type="file" name="imagens[]" multiple required accept="image/*"
                       class="w-full text-sm text-gray-700 file:mr-3 file:py-2.5 file:px-4 file:rounded-xl file:border-0 file:text-sm file:font-bold file:bg-green-600 file:text-white hover:file:bg-green-700 cursor-pointer bg-gray-50 border border-gray-200 rounded-xl p-1">
                <p class="text-xs text-gray-400 mt-1.5">Formato ideal: 1200x900 px (PNG, JPG ou WEBP até 10MB).</p>
            </div>

            <div>
                <label class="block text-sm font-semibold text-gray-700 mb-1.5">Título / Identificação (Opcional)</label>
                <input type="text" name="titulo" placeholder="Ex: Cartão Corporativo, Caminhão em trânsito"
                       class="w-full text-sm px-4 py-2.5 border border-gray-300 rounded-xl bg-gray-50 focus:bg-white focus:ring-2 focus:ring-green-500 focus:border-green-500 transition shadow-sm">
            </div>

            <div>
                <label class="block text-sm font-semibold text-gray-700 mb-1.5">Palavras-chave SEO Extras (Opcional)</label>
                <input type="text" name="palavras" placeholder="Ex: mudancas-residenciais-rj, frete-urgente"
                       class="w-full text-sm px-4 py-2.5 border border-gray-300 rounded-xl bg-gray-50 focus:bg-white focus:ring-2 focus:ring-green-500 focus:border-green-500 transition shadow-sm">
            </div>
        </div>

        <div class="flex justify-end pt-2">
            <button type="submit" class="px-6 py-3 bg-green-600 hover:bg-green-700 text-white text-sm font-bold rounded-xl shadow-md hover:shadow-lg transition flex items-center gap-2">
                <span>Enviar e Aplicar SEO →</span>
            </button>
        </div>
    </form>

    {{-- Grid da Galeria de Imagens em Proporção 4:3 (1200x900) Sem Cortes --}}
    <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-6 space-y-4">
        <div class="flex items-center justify-between border-b border-gray-100 pb-3">
            <h2 class="text-sm font-bold text-gray-800">Minhas Imagens Otimizadas ({{ $imagens->total() }})</h2>
            <span class="text-xs text-gray-400">Exibição completa 4:3 sem distorções</span>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-3 xl:grid-cols-4 gap-6">
            @forelse($imagens as $img)
                <div class="group bg-white border border-gray-200 rounded-2xl overflow-hidden shadow-sm hover:shadow-lg transition-all duration-300 flex flex-col justify-between">
                    
                    {{-- Container da Imagem em Proporção 4:3 (1200x900) com fundo suave e visualização 100% inteira --}}
                    <div class="relative aspect-[4/3] bg-slate-900/5 flex items-center justify-center overflow-hidden border-b border-gray-100 p-2 cursor-pointer"
                         @click="imgPreviewUrl = '{{ $img->imagem_url }}'; imgPreviewTitulo = '{{ $img->titulo ?: $img->nome_arquivo_seo }}'; modalPreview = true">
                        <img src="{{ $img->imagem_url }}" 
                             alt="{{ $img->titulo }}" 
                             class="max-w-full max-h-full object-contain rounded-lg group-hover:scale-105 transition-transform duration-300">
                        
                        <div class="absolute inset-0 bg-black/30 opacity-0 group-hover:opacity-100 transition-opacity duration-200 flex items-center justify-center text-white text-xs font-semibold gap-1.5 backdrop-blur-[2px]">
                            <span>🔍 Clique para Ampliar</span>
                        </div>
                    </div>

                    {{-- Informações e Nome SEO da Imagem --}}
                    <div class="p-4 space-y-2 text-left">
                        <div class="flex items-start justify-between gap-2">
                            <h3 class="text-xs font-bold text-gray-900 truncate" title="{{ $img->titulo }}">
                                {{ $img->titulo ?: 'Imagem sem título' }}
                            </h3>
                            <span class="text-[10px] font-bold px-1.5 py-0.5 bg-green-50 text-green-700 border border-green-200 rounded flex-shrink-0">
                                4:3 GMB
                            </span>
                        </div>

                        <div class="p-2 bg-gray-50 rounded-lg border border-gray-100">
                            <p class="text-[10px] font-mono text-gray-600 break-all leading-tight" title="{{ $img->nome_arquivo_seo }}">
                                🏷️ <span class="font-bold text-green-700">{{ $img->nome_arquivo_seo }}</span>
                            </p>
                        </div>

                        <div class="flex items-center justify-between text-[11px] text-gray-400 pt-1">
                            <span>📅 {{ $img->created_at->format('d/m/Y H:i') }}</span>
                            <span>📦 {{ round(($img->tamanho_bytes ?? 0) / 1024) }} KB</span>
                        </div>
                    </div>

                    {{-- Ações Rápidas --}}
                    <div class="px-4 py-2.5 bg-gray-50 border-t border-gray-100 flex items-center justify-between text-xs">
                        <button type="button" 
                                onclick="navigator.clipboard.writeText('{{ $img->imagem_url }}'); alert('Link da imagem copiado com sucesso!');" 
                                class="text-blue-600 hover:text-blue-800 font-semibold transition flex items-center gap-1">
                            📋 Copiar Link
                        </button>

                        <form action="{{ route('admin.gmb-posts.imagens.destroy', $img) }}" method="POST" onsubmit="return confirm('Deseja realmente excluir esta imagem da galeria?')">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="text-red-500 hover:text-red-700 font-semibold transition">
                                Excluir
                            </button>
                        </form>
                    </div>
                </div>
            @empty
                <div class="col-span-full py-16 text-center text-gray-400 text-sm">
                    <span class="text-4xl block mb-2">📷</span>
                    Nenhuma imagem cadastrada no banco ainda.<br>
                    Use o formulário acima para enviar fotos da sua empresa no formato 1200 × 900 px!
                </div>
            @endforelse
        </div>

        <div class="pt-4">
            {{ $imagens->links() }}
        </div>
    </div>

    {{-- Modal de Preview em Alta Resolução / Tela Cheia --}}
    <div x-show="modalPreview" 
         x-transition:enter="transition ease-out duration-150"
         x-transition:enter-start="opacity-0"
         x-transition:enter-end="opacity-100"
         class="fixed inset-0 z-50 bg-black/80 backdrop-blur-sm flex items-center justify-center p-4" 
         style="display: none;">
        
        <div class="bg-white rounded-2xl max-w-4xl w-full p-4 space-y-3 shadow-2xl border border-gray-100" @click.outside="modalPreview = false">
            <div class="flex items-center justify-between border-b border-gray-100 pb-2">
                <h3 class="text-sm font-bold text-gray-800 truncate" x-text="imgPreviewTitulo"></h3>
                <button @click="modalPreview = false" class="text-gray-400 hover:text-gray-700 text-lg font-bold">✕</button>
            </div>

            <div class="max-h-[75vh] flex items-center justify-center bg-gray-900 rounded-xl overflow-hidden p-2">
                <img :src="imgPreviewUrl" class="max-h-[70vh] max-w-full object-contain rounded-lg shadow-lg">
            </div>

            <div class="flex justify-end gap-2 pt-1">
                <button type="button" @click="modalPreview = false" class="px-4 py-1.5 bg-gray-100 hover:bg-gray-200 text-gray-700 text-xs font-semibold rounded-lg">Fechar</button>
            </div>
        </div>
    </div>

</div>
@endsection
