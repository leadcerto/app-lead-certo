<x-mail::message>
# 🚨 Atenção, {{ $avaliador->nome }}!

Você possui **{{ $agendamentos->count() }} avaliação(ões) em atraso**. As seguintes empresas precisam ser avaliadas com urgência:

<x-mail::table>
| Empresa | Data Agendada | Dias em Atraso |
|---------|--------------|----------------|
@foreach($agendamentos as $agendamento)
| {{ $agendamento->perfil->nome }} | {{ $agendamento->data_agendada->format('d/m/Y') }} | {{ $agendamento->data_agendada->diffInDays(now()) }} dia(s) |
@endforeach
</x-mail::table>

**Por favor, conclua essas avaliações o mais rápido possível.**

Lembre-se: avaliações feitas com atraso impactam na sua remuneração.

<x-mail::button :url="route('avaliador.dashboard')" color="error">
Concluir Avaliações Agora
</x-mail::button>

Obrigado,<br>
**{{ config('app.name') }}**
</x-mail::message>
