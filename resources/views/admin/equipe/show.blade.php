@extends('layouts.app')
@section('title', $agente->nome . ' — Equipe Lead Certo')

@section('content')
<div class="max-w-4xl mx-auto">
    <a href="{{ route('admin.equipe.index') }}" class="text-sm text-gray-400 hover:text-gray-600">&larr; Equipe</a>

    @if(session('sucesso'))
        <div class="my-4 p-3 bg-green-100 text-green-800 rounded-lg text-sm">✅ {{ session('sucesso') }}</div>
    @endif

    {{-- Identidade --}}
    <div class="bg-white rounded-xl shadow p-6 mt-3 flex items-start gap-5">
        @if($agente->avatar_url)
            <img src="{{ $agente->avatar_url }}" class="w-20 h-20 rounded-full object-cover flex-shrink-0" alt="">
        @else
            <div class="w-20 h-20 rounded-full bg-gray-200 flex items-center justify-center text-3xl font-bold text-gray-500 flex-shrink-0">
                {{ mb_substr($agente->nome, 0, 1) }}
            </div>
        @endif
        <div class="flex-1 min-w-0">
            <h1 class="text-xl font-bold text-gray-800">{{ $agente->nome }}</h1>
            <dl class="grid grid-cols-1 sm:grid-cols-2 gap-x-6 gap-y-1 mt-2 text-sm">
                <div class="flex gap-2"><dt class="text-gray-400">E-mail:</dt><dd class="text-gray-700">{{ $agente->email }}</dd></div>
                <div class="flex gap-2"><dt class="text-gray-400">WhatsApp:</dt><dd class="text-gray-700">{{ $agente->whatsapp ?? '—' }}</dd></div>
                <div class="flex gap-2"><dt class="text-gray-400">Na equipe desde:</dt><dd class="text-gray-700">{{ $agente->created_at->format('d/m/Y') }}</dd></div>
            </dl>

            <form action="{{ route('admin.equipe.update', $agente->id) }}" method="POST" class="mt-3 flex flex-wrap gap-2 items-center">
                @csrf @method('PATCH')
                <input type="url" name="avatar_url" value="{{ $agente->avatar_url }}" placeholder="URL da foto"
                       class="text-xs border border-gray-300 rounded-lg px-2 py-1 w-56">
                <input type="text" name="whatsapp" value="{{ $agente->whatsapp }}" placeholder="WhatsApp"
                       class="text-xs border border-gray-300 rounded-lg px-2 py-1 w-40">
                <button class="text-xs bg-gray-100 hover:bg-gray-200 text-gray-700 px-3 py-1 rounded-lg">Salvar</button>
            </form>
        </div>
    </div>

    {{-- Cargos --}}
    <div class="bg-white rounded-xl shadow p-6 mt-4">
        <h2 class="font-semibold text-gray-800 mb-1">Cargos</h2>
        <p class="text-xs text-gray-400 mb-3">Um agente pode ocupar mais de um cargo ao mesmo tempo.</p>

        <form action="{{ route('admin.equipe.sincronizar-cargos', $agente->id) }}" method="POST">
            @csrf
            <div class="space-y-2">
                @foreach($cargos as $cargo)
                    <label class="flex items-start gap-2 p-2 rounded-lg hover:bg-gray-50 cursor-pointer">
                        <input type="checkbox" name="cargo_ids[]" value="{{ $cargo->id }}"
                               {{ $agente->cargos->contains($cargo->id) ? 'checked' : '' }}
                               class="mt-1">
                        <span>
                            <span class="text-sm font-medium text-gray-700">{{ $cargo->nome }}</span>
                            <span class="block text-xs text-gray-400">{{ $cargo->descricao }}</span>
                        </span>
                    </label>
                @endforeach
            </div>
            <button class="mt-3 text-xs bg-blue-600 hover:bg-blue-700 text-white px-3 py-1.5 rounded-lg font-medium">Salvar cargos</button>
        </form>
        <a href="{{ route('admin.equipe.cargos') }}" class="block mt-2 text-xs text-gray-400 hover:text-gray-600">Ver/criar cargos da estrutura &rarr;</a>
    </div>

    {{-- Acessos concedidos (sugestão 2, 2026-08-20) — nunca guarda senha,
         só o identificador (e-mail/número/usuário), pra visualizar rápido
         o que já está montado sem abrir a documentação. --}}
    <div class="bg-white rounded-xl shadow p-6 mt-4">
        <h2 class="font-semibold text-gray-800 mb-1">🔑 Acessos concedidos</h2>
        <p class="text-xs text-gray-400 mb-3">Nunca guarda senha aqui — só o que existe e qual identificador.</p>

        <div class="space-y-1.5">
            @forelse($agente->acessos as $acesso)
                <div class="flex items-center justify-between gap-2 p-2 rounded-lg {{ $acesso->ativo ? '' : 'opacity-50' }}">
                    <span class="text-sm">
                        <span class="font-medium text-gray-700">{{ $acesso->servico }}</span>
                        <span class="text-gray-400"> — {{ $acesso->identificador }}</span>
                    </span>
                    <form action="{{ route('admin.equipe.acessos.toggle', [$agente->id, $acesso->id]) }}" method="POST">
                        @csrf
                        <button class="text-xs {{ $acesso->ativo ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-500' }} px-2 py-0.5 rounded-full">
                            {{ $acesso->ativo ? 'Ativo' : 'Inativo' }}
                        </button>
                    </form>
                </div>
            @empty
                <p class="text-sm text-gray-400 text-center py-2">Nenhum acesso registrado ainda.</p>
            @endforelse
        </div>

        <form action="{{ route('admin.equipe.acessos.store', $agente->id) }}" method="POST" class="flex flex-wrap gap-2 mt-3">
            @csrf
            <input type="text" name="servico" required maxlength="100" placeholder="Serviço (ex.: Gmail, WhatsApp Messenger)"
                   class="text-xs border border-gray-300 rounded-lg px-2 py-1 flex-1 min-w-[160px]">
            <input type="text" name="identificador" required maxlength="200" placeholder="E-mail/número/usuário — nunca senha"
                   class="text-xs border border-gray-300 rounded-lg px-2 py-1 flex-1 min-w-[160px]">
            <button class="text-xs bg-gray-100 hover:bg-gray-200 text-gray-700 px-3 py-1 rounded-lg">Adicionar</button>
        </form>
    </div>

    {{-- Atividade real no Kanban (sugestão 6, 2026-08-20) — puxada
         automaticamente dos tickets que o agente atende de verdade,
         distinta do log manual de serviços executados abaixo. --}}
    @if($atividadeKanban->isNotEmpty())
    <div class="bg-white rounded-xl shadow p-6 mt-4">
        <h2 class="font-semibold text-gray-800 mb-1">📋 Atividade real no Kanban</h2>
        <p class="text-xs text-gray-400 mb-3">Últimos tickets que {{ $agente->nome }} atendeu de verdade, puxado automaticamente.</p>
        <div class="divide-y divide-gray-100">
            @foreach($atividadeKanban as $ticket)
                <div class="flex items-center justify-between gap-2 py-2 text-sm">
                    <span class="text-gray-700">{{ $ticket->contato?->nome ?? $ticket->contato?->telefone ?? 'Contato #' . $ticket->contato_id }} <span class="text-gray-400">· #{{ $ticket->id }} · {{ $ticket->coluna_kanban }}</span></span>
                    <span class="text-xs text-gray-400 flex-shrink-0">{{ $ticket->updated_at->format('d/m/Y H:i') }}</span>
                </div>
            @endforeach
        </div>
        <a href="{{ route('kanban') }}?tenant_id={{ $agente->tenant_id }}" target="_blank" class="block mt-2 text-xs text-blue-600 hover:underline">Ver Kanban completo dela &rarr;</a>
    </div>
    @endif

    {{-- Feedback de clientes (item pedido 2026-08-20) --}}
    <div class="bg-white rounded-xl shadow p-6 mt-4">
        <h2 class="font-semibold text-gray-800 mb-1">💬 Feedback dos clientes</h2>
        <p class="text-xs text-gray-400 mb-3">
            Mensagens que empresas logadas mandaram direto pra {{ $agente->nome }} — cada uma recebe uma resposta padrão
            confirmando que o assunto vai pra próxima reunião da equipe.
        </p>
        <div class="flex flex-wrap gap-3 text-xs text-gray-500 mb-3">
            <span>{{ $feedbackResumo['total'] }} no total</span>
            <span>{{ $feedbackResumo['este_mes'] }} este mês</span>
            @foreach($feedbackResumo['por_empresa'] as $nomeEmpresa => $qtd)
                <span class="bg-gray-100 px-2 py-0.5 rounded-full">{{ $nomeEmpresa }}: {{ $qtd }}</span>
            @endforeach
        </div>
        @forelse($feedbacks as $fb)
            <div class="border-b border-gray-100 py-3 last:border-0">
                <div class="flex justify-between items-baseline">
                    <span class="text-sm font-medium text-gray-700">{{ $fb->tenant?->nome ?? 'Empresa removida' }}</span>
                    <span class="text-xs text-gray-400">{{ $fb->created_at->format('d/m/Y H:i') }}</span>
                </div>
                <p class="text-sm text-gray-600 mt-1">{{ $fb->mensagem }}</p>
                <p class="text-xs text-gray-400 mt-1 italic">↳ {{ $fb->resposta }}</p>
            </div>
        @empty
            <p class="text-sm text-gray-400 text-center py-4">Nenhum feedback recebido ainda.</p>
        @endforelse
    </div>

    {{-- Serviços executados --}}
    <div class="bg-white rounded-xl shadow p-6 mt-4">
        <div class="flex items-center justify-between mb-1">
            <h2 class="font-semibold text-gray-800">🛠️ Serviços executados</h2>
            <div class="text-xs text-gray-400 flex gap-3">
                <span>{{ $resumo['total'] }} no total</span>
                <span>{{ $resumo['ultimos_7_dias'] }} nos últimos 7 dias</span>
                @if($resumo['minutos_totais'])
                    <span>{{ intdiv($resumo['minutos_totais'], 60) }}h{{ str_pad($resumo['minutos_totais'] % 60, 2, '0', STR_PAD_LEFT) }} registradas</span>
                @endif
            </div>
        </div>

        <form action="{{ route('admin.equipe.registrar-servico', $agente->id) }}" method="POST" class="bg-gray-50 rounded-lg p-3 mt-3 space-y-2">
            @csrf
            <textarea name="descricao" required placeholder="O que foi feito?"
                      class="w-full text-sm border border-gray-300 rounded-lg px-2 py-1.5" rows="2"></textarea>
            <textarea name="motivo" placeholder="Por que foi preciso fazer? (opcional)"
                      class="w-full text-sm border border-gray-300 rounded-lg px-2 py-1.5" rows="2"></textarea>
            <div class="flex flex-wrap gap-2 items-center">
                <select name="grau_dificuldade" class="text-xs border border-gray-300 rounded-lg px-2 py-1.5">
                    <option value="baixo">Dificuldade: baixa</option>
                    <option value="medio" selected>Dificuldade: média</option>
                    <option value="alto">Dificuldade: alta</option>
                </select>
                <input type="number" name="tempo_gasto_minutos" min="0" placeholder="Minutos gastos"
                       class="text-xs border border-gray-300 rounded-lg px-2 py-1.5 w-32">
                <input type="datetime-local" name="executado_em"
                       class="text-xs border border-gray-300 rounded-lg px-2 py-1.5">
                <button class="text-xs bg-blue-600 hover:bg-blue-700 text-white px-3 py-1.5 rounded-lg font-medium ml-auto">Registrar</button>
            </div>
        </form>

        <div class="divide-y divide-gray-100 mt-3">
            @forelse($servicos as $servico)
                <div class="py-3">
                    <div class="flex justify-between items-baseline gap-2">
                        <span class="text-sm font-medium text-gray-700">{{ $servico->descricao }}</span>
                        <span class="text-xs text-gray-400 flex-shrink-0">{{ $servico->executado_em->format('d/m/Y H:i') }}</span>
                    </div>
                    @if($servico->motivo)
                        <p class="text-xs text-gray-500 mt-0.5">Motivo: {{ $servico->motivo }}</p>
                    @endif
                    <div class="flex gap-2 mt-1">
                        <span @class([
                            'text-xs px-2 py-0.5 rounded-full font-medium',
                            'bg-green-100 text-green-700' => $servico->grau_dificuldade === 'baixo',
                            'bg-yellow-100 text-yellow-700' => $servico->grau_dificuldade === 'medio',
                            'bg-red-100 text-red-700' => $servico->grau_dificuldade === 'alto',
                        ])>{{ ucfirst($servico->grau_dificuldade) }}</span>
                        @if($servico->tempo_gasto_minutos)
                            <span class="text-xs bg-gray-100 text-gray-500 px-2 py-0.5 rounded-full">{{ $servico->tempo_gasto_minutos }} min</span>
                        @endif
                    </div>
                </div>
            @empty
                <p class="text-sm text-gray-400 text-center py-4">Nenhum serviço registrado ainda.</p>
            @endforelse
        </div>
        <div class="mt-3">{{ $servicos->links() }}</div>
    </div>
</div>
@endsection
