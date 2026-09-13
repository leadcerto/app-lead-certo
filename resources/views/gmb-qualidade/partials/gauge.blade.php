{{--
    Gauge circular proporcional (arco preenchido conforme a nota), estilo PageSpeed
    Insights. Reaproveitado pra nota geral (tamanho maior) e pra cada categoria
    (tamanho menor) — ver show.blade.php.

    Props:
    - $nota (int|null) — 0 a 100, ou null quando ainda não há nota calculada.
    - $size (int, opcional, default 96) — largura/altura do gauge em px.
    - $strokeWidth (int, opcional) — espessura do traço; default proporcional ao size.
--}}
@php
    $size = $size ?? 96;
    $strokeWidth = $strokeWidth ?? max(4, (int) round($size / 12));
    $raio = ($size - $strokeWidth) / 2;
    $circunferencia = 2 * M_PI * $raio;
    $percentual = is_null($nota) ? 0 : max(0, min(100, $nota));
    $offset = $circunferencia * (1 - $percentual / 100);
    $cor = is_null($nota) ? '#d1d5db' : ($nota >= 90 ? '#16a34a' : ($nota >= 50 ? '#f59e0b' : '#dc2626'));
    $corTexto = is_null($nota) ? 'text-gray-400' : ($nota >= 90 ? 'text-green-600' : ($nota >= 50 ? 'text-amber-500' : 'text-red-600'));
    $fontSize = max(11, (int) round($size / 4));
@endphp
<div class="relative inline-flex items-center justify-center flex-shrink-0" style="width: {{ $size }}px; height: {{ $size }}px;">
    <svg width="{{ $size }}" height="{{ $size }}" viewBox="0 0 {{ $size }} {{ $size }}" class="-rotate-90">
        <circle cx="{{ $size / 2 }}" cy="{{ $size / 2 }}" r="{{ $raio }}" fill="none" stroke="#e5e7eb" stroke-width="{{ $strokeWidth }}" />
        @if(! is_null($nota))
            <circle cx="{{ $size / 2 }}" cy="{{ $size / 2 }}" r="{{ $raio }}" fill="none" stroke="{{ $cor }}"
                    stroke-width="{{ $strokeWidth }}" stroke-linecap="round"
                    stroke-dasharray="{{ $circunferencia }}" stroke-dashoffset="{{ $offset }}" />
        @endif
    </svg>
    <span class="absolute font-bold {{ $corTexto }}" style="font-size: {{ $fontSize }}px;">{{ $nota ?? '—' }}</span>
</div>
