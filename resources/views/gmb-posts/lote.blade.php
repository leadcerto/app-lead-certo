@extends('layouts.app')

@section('title', 'Gerador de Postagens em Lote — Lead Certo')

@php
    $inicioSemana = $semana->copy()->startOfWeek(\Carbon\Carbon::SUNDAY);
    $diasSemana = collect(['domingo', 'segunda', 'terca', 'quarta', 'quinta', 'sexta', 'sabado'])
        ->values()
        ->mapWithKeys(fn ($dia, $i) => [$dia => $inicioSemana->copy()->addDays($i)]);
@endphp

@section('content')
<div class="max-w-7xl mx-auto space-y-6">

    {{-- Header --}}
    <div class="flex items-center justify-between flex-wrap gap-4">
        <div>
            <div class="flex items-center gap-2">
                <span class="text-2xl">📊</span>
                <h1 class="text-2xl font-bold text-gray-800">Gerador de Postagens em Lote (Matriz Semanal)</h1>
            </div>
            <p class="text-sm text-gray-500 mt-1">
                Defina a grade de postagens semanais no Google Meu Negócio para cada perfil —
                <span class="font-bold text-gray-700">{{ $inicioSemana->format('d/m') }} a {{ $inicioSemana->copy()->endOfWeek(\Carbon\Carbon::SATURDAY)->format('d/m/Y') }}</span>.
            </p>
        </div>
        <div class="flex items-center gap-2">
            <a href="{{ route('admin.gmb-posts.templates') }}" class="px-3.5 py-2 bg-purple-50 text-purple-700 border border-purple-200 rounded-xl hover:bg-purple-100 text-xs font-bold transition flex items-center gap-1.5 shadow-sm">
                📑 Templates Prontos
            </a>
            <a href="{{ route('admin.gmb-posts.index', ['semana' => $semana->toDateString()]) }}" class="px-3.5 py-2 bg-gray-100 text-gray-700 rounded-xl hover:bg-gray-200 text-xs font-semibold transition">
                ← Voltar para Agenda
            </a>
        </div>
    </div>

    @if(isset($errors) && $errors->any())
        <div class="p-4 bg-red-100 border border-red-200 text-red-800 rounded-2xl text-sm space-y-1 shadow-sm">
            @foreach($errors->all() as $error)
                <p class="font-medium">• {{ $error }}</p>
            @endforeach
        </div>
    @endif

    <form action="{{ route('admin.gmb-posts.lote.store') }}" method="POST" enctype="multipart/form-data" class="space-y-6" id="formLote">
        @csrf
        <input type="hidden" name="semana_referencia" value="{{ $semana->toDateString() }}">

        {{-- Painel de Configurações do Lote --}}
        <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-5 grid grid-cols-1 md:grid-cols-4 gap-4">
            
            {{-- 1. Estratégia de Conteúdo --}}
            <div>
                <label class="block text-xs font-bold text-gray-700 mb-1.5 uppercase tracking-wide">1. Conteúdo</label>
                <select name="modo_conteudo" id="modoConteudo" onchange="alternarModo()" class="w-full bg-gray-50 border border-gray-200 rounded-xl px-3 py-2 text-xs text-gray-800 focus:ring-2 focus:ring-green-500 focus:bg-white transition">
                    <option value="template_rotativo" selected>🔄 Revezar Templates Prontos</option>
                    <option value="ia">🤖 Gerar com IA (Por Bairro)</option>
                    <option value="template_especifico">📋 Usar Template Específico</option>
                </select>
                <p class="text-[10px] text-gray-400 mt-1">Revezamento alterna ofertas, dicas e serviços.</p>
            </div>

            {{-- 2. Seleção de Template Específico (condicional) --}}
            <div id="boxTemplateEspecifico" class="hidden">
                <label class="block text-xs font-bold text-gray-700 mb-1.5 uppercase tracking-wide">2. Template</label>
                <select name="template_id" class="w-full bg-gray-50 border border-gray-200 rounded-xl px-3 py-2 text-xs text-gray-800 focus:ring-2 focus:ring-green-500 focus:bg-white transition">
                    @foreach($templates as $tpl)
                        <option value="{{ $tpl->id }}">[{{ strtoupper(substr($tpl->categoria, 0, 3)) }}] {{ $tpl->titulo_template }}</option>
                    @endforeach
                </select>
            </div>

            {{-- 3. Imagem Opcional com SEO Automático --}}
            <div>
                <label class="block text-xs font-bold text-gray-700 mb-1.5 uppercase tracking-wide">2. Imagem (SEO Automático)</label>
                <select name="modo_imagem" id="modoImagem" onchange="alternarModoImagem()" class="w-full bg-gray-50 border border-gray-200 rounded-xl px-3 py-2 text-xs text-gray-800 focus:ring-2 focus:ring-green-500 focus:bg-white transition">
                    @if(isset($imagensSalvas) && $imagensSalvas->isNotEmpty())
                        <option value="galeria_rotativa" selected>🔄 Revezar Imagens Salvas ({{ $imagensSalvas->count() }} na Galeria)</option>
                        <option value="galeria_especifica">📋 Escolher Imagem da Galeria</option>
                    @endif
                    <option value="upload" {{ (!isset($imagensSalvas) || $imagensSalvas->isEmpty()) ? 'selected' : '' }}>📤 Enviar Nova Imagem</option>
                    <option value="nenhuma">🚫 Sem Imagem</option>
                </select>

                <div id="boxUploadImagem" class="{{ (isset($imagensSalvas) && $imagensSalvas->isNotEmpty()) ? 'hidden' : '' }} mt-2">
                    <input type="file" name="imagem_padrao" accept="image/*" class="w-full bg-gray-50 border border-gray-200 rounded-xl px-2.5 py-1.5 text-xs text-gray-700 file:mr-2 file:py-1 file:px-2 file:rounded-md file:border-0 file:text-xs file:font-semibold file:bg-green-50 file:text-green-700 hover:file:bg-green-100 transition">
                </div>

                @if(isset($imagensSalvas) && $imagensSalvas->isNotEmpty())
                <div id="boxGaleriaEspecifica" class="hidden mt-2">
                    <select name="imagem_galeria_id" class="w-full bg-gray-50 border border-gray-200 rounded-xl px-3 py-1.5 text-xs text-gray-800 focus:ring-2 focus:ring-green-500">
                        @foreach($imagensSalvas as $img)
                            <option value="{{ $img->id }}">{{ $img->tipo === 'pronta' ? '🎭 ' : '' }}{{ $img->titulo ?: $img->nome_arquivo_seo }}</option>
                        @endforeach
                    </select>
                </div>
                @endif
                <p class="text-[10px] text-green-700 font-medium mt-1">✨ Nome SEO com palavras-chave e data/hora.</p>
            </div>

            {{-- 4. Janela de Publicação (fixa, calculada automaticamente pelo sistema) --}}
            <div>
                <label class="block text-xs font-bold text-gray-700 mb-1.5 uppercase tracking-wide">3. Horários</label>
                <div class="w-full bg-gray-50 border border-gray-200 rounded-xl px-3 py-2 text-xs text-gray-600">
                    ⏰ 08:00 às 18:00
                </div>
                <p class="text-[10px] text-gray-400 mt-1">Quando o perfil tiver mais de 1 post no mesmo dia, o sistema espalha os horários automaticamente dentro dessa janela (mínimo 10 min entre eles).</p>
            </div>

            {{-- 5. Botões de Ação Rápida --}}
            <div class="md:col-span-4 pt-2 border-t border-gray-100 flex items-center justify-between flex-wrap gap-2">
                <span class="text-xs font-bold text-gray-600">Preenchimento Rápido:</span>
                <div class="flex gap-2 flex-wrap">
                    <button type="button" onclick="marcarPadrao('seg-qua-sex')" class="px-3 py-1 bg-gray-100 hover:bg-gray-200 text-gray-700 text-xs font-semibold rounded-lg transition">
                        Seg / Qua / Sex
                    </button>
                    <button type="button" onclick="marcarPadrao('ter-qui-sab')" class="px-3 py-1 bg-gray-100 hover:bg-gray-200 text-gray-700 text-xs font-semibold rounded-lg transition">
                        Ter / Qui / Sáb
                    </button>
                    <button type="button" onclick="marcarPadrao('todos')" class="px-3 py-1 bg-green-50 hover:bg-green-100 text-green-700 text-xs font-bold rounded-lg transition">
                        Todos os Dias
                    </button>
                    <button type="button" onclick="marcarPadrao('limpar')" class="px-3 py-1 bg-red-50 hover:bg-red-100 text-red-600 text-xs font-semibold rounded-lg transition">
                        Limpar
                    </button>
                </div>
            </div>
        </div>

        {{-- Matriz Semanal --}}
        <div class="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-gray-50 border-b border-gray-100 text-gray-600">
                        <tr>
                            <th class="px-5 py-3.5 text-left font-bold text-gray-700 min-w-[200px]">Perfil GMB (Ficha)</th>
                            @foreach(['domingo' => 'Domingo', 'segunda' => 'Segunda', 'terca' => 'Terça', 'quarta' => 'Quarta', 'quinta' => 'Quinta', 'sexta' => 'Sexta', 'sabado' => 'Sábado'] as $dia => $label)
                            <th class="px-3 py-3.5 text-center min-w-[90px]">
                                <div class="font-bold text-gray-800">{{ $label }}</div>
                                <div class="text-[11px] font-normal text-gray-400">{{ $diasSemana[$dia]->format('d/m') }}</div>
                            </th>
                            @endforeach
                            <th class="px-4 py-3.5 text-center font-bold text-gray-700 w-24">Total</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @forelse($perfis as $perfil)
                        <tr class="hover:bg-gray-50/80 transition" data-perfil-row="{{ $perfil->id }}">
                            <td class="px-5 py-4">
                                <div class="font-bold text-gray-800 flex items-center gap-1.5">
                                    <span>📍</span>
                                    <span>{{ $perfil->nome }}</span>
                                </div>
                                <div class="text-xs text-gray-400 mt-0.5">{{ $perfil->city }}/{{ $perfil->state }}</div>
                            </td>
                            @foreach(['domingo', 'segunda', 'terca', 'quarta', 'quinta', 'sexta', 'sabado'] as $dia)
                            <td class="px-3 py-4 text-center">
                                <input type="number"
                                       name="matriz[{{ $perfil->id }}][{{ $dia }}]"
                                       value="0"
                                       min="0"
                                       data-dia="{{ $dia }}"
                                       oninput="calcularTotais()"
                                       class="w-14 text-center border border-gray-300 rounded-lg px-1 py-1.5 text-sm focus:ring-2 focus:ring-green-500">
                            </td>
                            @endforeach
                            <td class="px-4 py-4 text-center font-bold text-gray-700 total-perfil">
                                0
                            </td>
                        </tr>
                        @empty
                        <tr>
                            <td colspan="9" class="px-6 py-12 text-center text-gray-400">
                                Nenhum perfil do Google Meu Negócio cadastrado para este tenant.
                            </td>
                        </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        {{-- Footer de Ações --}}
        <div class="bg-gray-900 text-white rounded-2xl p-5 flex items-center justify-between flex-wrap gap-4 shadow-lg">
            <div class="flex items-center gap-3">
                <span class="text-3xl">🚀</span>
                <div>
                    <div class="text-base font-bold text-white">
                        Total de postagens selecionadas: <span id="totalGeral" class="text-green-400 text-xl font-mono">0</span>
                    </div>
                    <p class="text-xs text-gray-400 mt-0.5">
                        Os posts serão distribuídos e publicados automaticamente na semana de {{ $inicioSemana->format('d/m') }} a {{ $inicioSemana->copy()->endOfWeek(\Carbon\Carbon::SATURDAY)->format('d/m/Y') }}.
                    </p>
                </div>
            </div>

            <button type="submit" class="px-7 py-3 bg-green-500 hover:bg-green-400 text-gray-950 font-bold rounded-xl text-sm transition shadow-lg flex items-center gap-2">
                <span>Agendar Postagens em Lote →</span>
            </button>
        </div>

    </form>
</div>

<script>
function alternarModo() {
    const modo = document.getElementById('modoConteudo').value;
    const box = document.getElementById('boxTemplateEspecifico');
    if (modo === 'template_especifico') {
        box.classList.remove('hidden');
    } else {
        box.classList.add('hidden');
    }
}

function alternarModoImagem() {
    const modo = document.getElementById('modoImagem').value;
    const boxUpload = document.getElementById('boxUploadImagem');
    const boxGaleria = document.getElementById('boxGaleriaEspecifica');

    if (boxUpload) {
        if (modo === 'upload') {
            boxUpload.classList.remove('hidden');
        } else {
            boxUpload.classList.add('hidden');
        }
    }

    if (boxGaleria) {
        if (modo === 'galeria_especifica') {
            boxGaleria.classList.remove('hidden');
        } else {
            boxGaleria.classList.add('hidden');
        }
    }
}

function marcarPadrao(tipo) {
    const campos = document.querySelectorAll('input[type="number"][data-dia]');
    campos.forEach(campo => {
        const dia = campo.getAttribute('data-dia');
        if (tipo === 'todos') {
            campo.value = 1;
        } else if (tipo === 'limpar') {
            campo.value = 0;
        } else if (tipo === 'seg-qua-sex') {
            campo.value = ['segunda', 'quarta', 'sexta'].includes(dia) ? 1 : 0;
        } else if (tipo === 'ter-qui-sab') {
            campo.value = ['terca', 'quinta', 'sabado'].includes(dia) ? 1 : 0;
        }
    });
    calcularTotais();
}

function calcularTotais() {
    let totalGeral = 0;
    document.querySelectorAll('tr[data-perfil-row]').forEach(row => {
        let totalPerfil = 0;
        row.querySelectorAll('input[type="number"]').forEach(campo => {
            totalPerfil += parseInt(campo.value, 10) || 0;
        });
        row.querySelector('.total-perfil').textContent = totalPerfil;
        totalGeral += totalPerfil;
    });
    document.getElementById('totalGeral').textContent = totalGeral;
}

// Inicializar na carga da página
document.addEventListener('DOMContentLoaded', () => {
    marcarPadrao('seg-qua-sex');
});
</script>
@endsection
