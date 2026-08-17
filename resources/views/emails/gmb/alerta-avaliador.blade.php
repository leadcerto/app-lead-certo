<x-mail::message>
# Olá, {{ $avaliador->nome }}! 👋

Você possui **{{ $tarefas->count() }} avaliação(ões)** pendente(s) para esta semana.

<x-mail::table>
| Empresa | Data | Status |
|---------|------|--------|
@foreach($tarefas as $tarefa)
| {{ $tarefa->perfil->nome }} | {{ $tarefa->data_agendada->format('d/m (l)') }} | {{ ucfirst($tarefa->status) }} |
@endforeach
</x-mail::table>

Acesse o painel para visualizar os detalhes e concluir suas avaliações:

<x-mail::button :url="route('avaliador.dashboard')">
Acessar Meu Painel
</x-mail::button>

Obrigado,<br>
**{{ config('app.name') }}**
</x-mail::message>
