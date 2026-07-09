@extends('layouts.app')

@section('title', 'Marcadores — Lead Certo')

@section('content')
<div class="p-6 max-w-4xl mx-auto">

    {{-- Cabeçalho --}}
    <div class="flex items-center justify-between mb-6">
        <div>
            <h1 class="text-xl font-bold text-gray-800">Marcadores</h1>
            <p class="text-sm text-gray-500 mt-0.5">Grupos e rótulos para organizar os contatos no Google Contacts</p>
        </div>
        @if($google_conectado)
        <button onclick="abrirModalCriar()"
                class="flex items-center gap-2 px-4 py-2 bg-green-600 hover:bg-green-700 text-white text-sm font-medium rounded-lg transition-colors">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
            </svg>
            Novo Marcador
        </button>
        @endif
    </div>

    @if(!$google_conectado)
    <div class="bg-yellow-50 border border-yellow-200 rounded-xl p-4 mb-6 flex items-start gap-3">
        <svg class="w-5 h-5 text-yellow-500 flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
        </svg>
        <div>
            <p class="text-sm font-medium text-yellow-800">Google Contacts não conectado</p>
            <p class="text-xs text-yellow-600 mt-0.5">Conecte o Google em <a href="{{ route('integracoes') }}" class="underline">Integrações</a> para ver e criar marcadores.</p>
        </div>
    </div>
    @endif

    {{-- Lista de marcadores --}}
    @if(empty($grupos))
        <div class="bg-white rounded-xl border border-gray-200 py-16 text-center">
            <svg class="w-12 h-12 mx-auto mb-3 text-gray-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 7h.01M7 3h5c.512 0 1.024.195 1.414.586l7 7a2 2 0 010 2.828l-7 7a2 2 0 01-2.828 0l-7-7A1.994 1.994 0 013 12V7a4 4 0 014-4z"/>
            </svg>
            <p class="text-sm font-medium text-gray-500">Nenhum marcador encontrado</p>
            @if($google_conectado)
                <p class="text-xs text-gray-400 mt-1">Crie marcadores para organizar seus contatos</p>
                <button onclick="abrirModalCriar()" class="mt-4 px-4 py-2 bg-green-600 hover:bg-green-700 text-white text-sm font-medium rounded-lg transition-colors">
                    Criar primeiro marcador
                </button>
            @endif
        </div>
    @else
    <div class="grid gap-3">
        @foreach($grupos as $grupo)
        <div class="bg-white rounded-xl border border-gray-200 px-5 py-4 flex items-center gap-4 hover:border-gray-300 transition-colors">
            {{-- Ícone de marcador --}}
            <div class="w-10 h-10 rounded-full flex items-center justify-center flex-shrink-0
                {{ $grupo['name'] === 'Lead Certo' ? 'bg-green-100' : 'bg-blue-100' }}">
                <svg class="w-5 h-5 {{ $grupo['name'] === 'Lead Certo' ? 'text-green-600' : 'text-blue-600' }}"
                     fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                          d="M7 7h.01M7 3h5c.512 0 1.024.195 1.414.586l7 7a2 2 0 010 2.828l-7 7a2 2 0 01-2.828 0l-7-7A1.994 1.994 0 013 12V7a4 4 0 014-4z"/>
                </svg>
            </div>

            {{-- Info --}}
            <div class="flex-1 min-w-0">
                <div class="flex items-center gap-2">
                    <span class="font-medium text-gray-800">{{ $grupo['name'] }}</span>
                    @if($grupo['name'] === 'Lead Certo')
                        <span class="bg-green-100 text-green-700 text-xs px-2 py-0.5 rounded-full font-medium">Sistema</span>
                    @endif
                </div>
                <p class="text-xs text-gray-400 mt-0.5 truncate text-mono">{{ $grupo['resourceName'] }}</p>
            </div>

            {{-- Contagem --}}
            <div class="text-right flex-shrink-0">
                <div class="text-xl font-bold text-gray-800">{{ number_format($grupo['memberCount']) }}</div>
                <div class="text-xs text-gray-400">contatos</div>
            </div>
        </div>
        @endforeach
    </div>
    @endif

    {{-- Nota informativa --}}
    <div class="mt-6 bg-blue-50 border border-blue-100 rounded-xl p-4 text-sm text-blue-700">
        <p class="font-medium mb-1">Como funcionam os marcadores</p>
        <ul class="text-xs space-y-1 text-blue-600 list-disc list-inside">
            <li>O marcador <strong>Lead Certo</strong> é criado automaticamente e agrupa todos os contatos enriquecidos pelo sistema.</li>
            <li>Outros marcadores podem ser criados aqui e usados para organizar contatos por categoria, tipo ou campanha.</li>
            <li>Os marcadores são sincronizados com o Google Contacts do franqueado.</li>
        </ul>
    </div>
</div>

{{-- Modal criar marcador --}}
<div id="modal-criar" class="hidden fixed inset-0 bg-black bg-opacity-40 z-50 flex items-center justify-center p-4">
    <div class="bg-white rounded-2xl shadow-xl w-full max-w-sm">
        <div class="px-6 py-4 border-b border-gray-100">
            <h3 class="font-semibold text-gray-800">Novo Marcador</h3>
        </div>
        <div class="px-6 py-4">
            <label class="text-xs font-medium text-gray-500 uppercase tracking-wide block mb-1">Nome do marcador</label>
            <input id="input-nome-marcador" type="text" maxlength="100"
                   class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-green-500"
                   placeholder="Ex: Clientes VIP, Bairro Tijuca...">
            <p id="erro-marcador" class="text-xs text-red-500 mt-1 hidden"></p>
        </div>
        <div class="px-6 py-4 border-t border-gray-100 flex justify-end gap-3">
            <button onclick="fecharModalCriar()" class="px-4 py-2 text-sm text-gray-600 hover:text-gray-800 rounded-lg">Cancelar</button>
            <button onclick="criarMarcador()" class="px-4 py-2 text-sm bg-green-600 hover:bg-green-700 text-white rounded-lg font-medium">Criar</button>
        </div>
    </div>
</div>

<script>
function abrirModalCriar() {
    document.getElementById('input-nome-marcador').value = '';
    document.getElementById('erro-marcador').classList.add('hidden');
    document.getElementById('modal-criar').classList.remove('hidden');
    document.getElementById('input-nome-marcador').focus();
}
function fecharModalCriar() {
    document.getElementById('modal-criar').classList.add('hidden');
}

async function criarMarcador() {
    const nome = document.getElementById('input-nome-marcador').value.trim();
    const erroEl = document.getElementById('erro-marcador');

    if (!nome) {
        erroEl.textContent = 'Digite um nome para o marcador.';
        erroEl.classList.remove('hidden');
        return;
    }

    const res = await fetch('/api/painel/contatos/marcadores', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
        },
        body: JSON.stringify({ nome }),
    });

    const data = await res.json();
    if (res.ok && data.ok) {
        fecharModalCriar();
        window.location.reload();
    } else {
        erroEl.textContent = data.erro || 'Erro ao criar marcador. Tente novamente.';
        erroEl.classList.remove('hidden');
    }
}

document.getElementById('modal-criar').addEventListener('click', function(e) {
    if (e.target === this) fecharModalCriar();
});
document.getElementById('input-nome-marcador').addEventListener('keydown', function(e) {
    if (e.key === 'Enter') criarMarcador();
});
</script>
@endsection
