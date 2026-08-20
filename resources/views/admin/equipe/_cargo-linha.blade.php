<div class="p-4">
    <div class="flex items-baseline justify-between gap-2 flex-wrap">
        <span class="font-medium text-gray-800">{{ $cargo->nome }}</span>
        <div class="flex items-center gap-2">
            @if($cargo->agentes_count > 0)
                <span class="text-xs text-gray-400">{{ $cargo->agentes_count }} agente(s) ocupando</span>
            @else
                <span class="text-xs bg-amber-100 text-amber-700 px-2 py-0.5 rounded-full font-medium">Dormente — sem ninguém ocupando</span>
            @endif
            <form action="{{ route('admin.equipe.cargos.toggle-visivel', $cargo->id) }}" method="POST">
                @csrf
                <button class="text-xs {{ $cargo->visivel_para_clientes ? 'bg-blue-100 text-blue-700' : 'bg-gray-100 text-gray-500' }} px-2 py-0.5 rounded-full"
                        title="Se os clientes veem esse setor na tela de Suporte">
                    {{ $cargo->visivel_para_clientes ? 'Visível pro cliente' : 'Interno' }}
                </button>
            </form>
        </div>
    </div>
    <p class="text-sm text-gray-600 mt-1">{{ $cargo->descricao }}</p>
</div>
