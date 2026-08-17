<x-mail::message>
# 📊 Relatório de Atrasos — Avaliações GMB

Foram identificadas avaliações em atraso. Segue o resumo por avaliador:

@foreach($resumo as $avaliadorId => $agendamentos)
@php $avaliador = $agendamentos->first()->avaliador; @endphp

## {{ $avaliador->nome }} ({{ $avaliador->email }}) — {{ $agendamentos->count() }} atraso(s)

<x-mail::table>
| Empresa | Cidade/UF | Data Agendada | Dias em Atraso |
|---------|-----------|--------------|----------------|
@foreach($agendamentos as $agendamento)
| {{ $agendamento->perfil->nome }} | {{ $agendamento->perfil->city }}/{{ $agendamento->perfil->state }} | {{ $agendamento->data_agendada->format('d/m/Y') }} | {{ $agendamento->data_agendada->diffInDays(now()) }} |
@endforeach
</x-mail::table>

---
@endforeach

**Total geral:** {{ $resumo->flatten()->count() }} avaliação(ões) em atraso, {{ $resumo->count() }} avaliador(es) inadimplente(s).

> Este e-mail foi enviado em cópia (CC) para os avaliadores listados acima.

**{{ config('app.name') }}**
</x-mail::message>
