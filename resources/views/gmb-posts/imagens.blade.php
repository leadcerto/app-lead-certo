@extends('layouts.app')
@section('title', 'Galeria de Imagens (SEO) — Lead Certo')

@section('content')
<div class="max-w-6xl mx-auto space-y-6">

    {{-- Header --}}
    <div class="flex items-center justify-between flex-wrap gap-4">
        <div>
            <h1 class="text-2xl font-bold text-gray-800">🖼️ Galeria de Imagens & SEO Local</h1>
            <p class="text-sm text-gray-500 mt-1">
                Banco de imagens da sua empresa para publicações no Google Meu Negócio. Ao fazer upload, as imagens são salvas e renomeadas automaticamente com palavras-chave estratégicas para rankeamento no Google Maps e Google Imagens.
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

        <div class="flex items-center justify-between border-b border-gray-100 pb-3">
            <h2 class="text-sm font-bold text-gray-800 flex items-center gap-2">
                <span>📤</span>
                <span>Adicionar Novas Imagens ao Banco</span>
            </h2>
            <span class="text-xs text-green-700 font-semibold bg-green-50 px-2.5 py-1 rounded-md border border-green-200">
                ✨ Renomeação Automática SEO
            </span>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
            <div>
                <label class="block text-xs font-semibold text-gray-700 mb-1">Selecionar Imagens (Permite Múltiplas) *</label>
                <input type="file" name="imagens[]" multiple required accept="image/*"
                       class="w-full text-xs text-gray-700 file:mr-2 file:py-2 file:px-3 file:rounded-lg file:border-0 file:text-xs file:font-semibold file:bg-green-600 file:text-white hover:file:bg-green-700 cursor-pointer">
                <p class="text-[11px] text-gray-400 mt-1">PNG, JPG ou WEBP até 10MB.</p>
            </div>

            <div>
                <label class="block text-xs font-semibold text-gray-700 mb-1">Título / Identificação (Opcional)</label>
                <input type="text" name="titulo" placeholder="Ex: Caminhão em trânsito, Equipe em atendimento"
                       class="w-full text-xs border-gray-300 rounded-lg bg-gray-50 focus:bg-white focus:ring-green-500">
            </div>

            <div>
                <label class="block text-xs font-semibold text-gray-700 mb-1">Palavras-chave SEO Extras (Opcional)</label>
                <input type="text" name="palavras" placeholder="Ex: mudancas-residenciais-rj, frete-urgente"
                       class="w-full text-xs border-gray-300 rounded-lg bg-gray-50 focus:bg-white focus:ring-green-500">
            </div>
        </div>

        <div class="flex justify-end pt-2">
            <button type="submit" class="px-5 py-2.5 bg-green-600 hover:bg-green-700 text-white text-xs font-bold rounded-xl shadow transition flex items-center gap-1.5">
                <span>Enviar e Aplicar SEO →</span>
            </button>
        </div>
    </form>

    {{-- Grid da Galeria de Imagens --}}
    <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-6 space-y-4">
        <div class="flex items-center justify-between border-b border-gray-100 pb-3">
            <h2 class="text-sm font-bold text-gray-800">Minhas Imagens Otimizadas ({{ $imagens->total() }})</h2>
        </div>

        <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-6 gap-4">
            @forelse($imagens as $img)
                <div class="group relative bg-gray-50 border border-gray-200 rounded-xl overflow-hidden shadow-sm hover:shadow-md transition flex flex-col justify-between">
                    <div class="aspect-square bg-gray-100 overflow-hidden relative">
                        <img src="{{ $img->imagem_url }}" alt="{{ $img->titulo }}" class="w-full h-full object-cover group-hover:scale-105 transition duration-300">
                    </div>

                    <div class="p-2.5 space-y-1 text-left bg-white border-t border-gray-100">
                        <p class="text-[11px] font-bold text-gray-800 truncate" title="{{ $img->titulo }}">{{ $img->titulo ?: 'Imagem' }}</p>
                        <p class="text-[9px] font-mono text-green-700 truncate" title="{{ $img->nome_arquivo_seo }}">{{ $img->nome_arquivo_seo }}</p>
                        <p class="text-[10px] text-gray-400">{{ $img->created_at->format('d/m/Y') }} • {{ round(($img->tamanho_bytes ?? 0) / 1024) }} KB</p>
                    </div>

                    <div class="p-1.5 bg-gray-50 border-t border-gray-100 flex items-center justify-between text-[11px]">
                        <button type="button" onclick="navigator.clipboard.writeText('{{ $img->imagem_url }}'); alert('Link copiado!');" class="text-blue-600 hover:underline font-semibold text-[10px]">
                            Copiar Link
                        </button>

                        <form action="{{ route('admin.gmb-posts.imagens.destroy', $img) }}" method="POST" onsubmit="return confirm('Deseja excluir esta imagem da galeria?')">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="text-red-500 hover:underline font-semibold text-[10px]">
                                Excluir
                            </button>
                        </form>
                    </div>
                </div>
            @empty
                <div class="col-span-full py-12 text-center text-gray-400 text-sm">
                    Nenhuma imagem cadastrada no banco ainda. Use o formulário acima para enviar fotos da sua empresa!
                </div>
            @endforelse
        </div>

        <div class="pt-4">
            {{ $imagens->links() }}
        </div>
    </div>

</div>
@endsection
