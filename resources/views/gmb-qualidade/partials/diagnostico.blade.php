{{-- Uma linha de diagnóstico (mensagem + link de ação opcional). Props: $diag. --}}
@php
    $corDiag = match($diag['tipo']) {
        'ok' => 'border-green-400 text-gray-700',
        'aviso' => 'border-amber-400 text-gray-700',
        'erro' => 'border-red-400 text-gray-700',
        'info' => 'border-blue-400 text-gray-700',
        default => 'border-gray-300 text-gray-500',
    };
@endphp
<div class="pl-3 border-l-2 {{ $corDiag }} text-sm mb-2">
    <p>{{ $diag['mensagem'] }}</p>
    @if(!empty($diag['acao_url']))
        <a href="{{ $diag['acao_url'] }}" class="inline-block mt-1 text-xs font-semibold text-green-700 hover:underline">{{ $diag['acao_label'] }} →</a>
    @endif
</div>
