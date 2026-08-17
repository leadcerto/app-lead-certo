@extends('layouts.app')
@section('title', 'Nova Empresa — Lead Certo')

@section('content')
<div class="max-w-2xl mx-auto">
    <div class="mb-6">
        <h1 class="text-2xl font-bold text-gray-800">🏢 Nova Empresa</h1>
        <p class="text-sm text-gray-500 mt-1">
            Cria a empresa e o usuário dono no mesmo passo — a configuração padrão Lead Certo
            (Kanban, colunas, persona, motivos de desfecho) é aplicada automaticamente.
        </p>
    </div>

    @if($errors->any())
        <div class="mb-4 p-3 bg-red-100 text-red-800 rounded-lg text-sm">
            @foreach($errors->all() as $error) <p>{{ $error }}</p> @endforeach
        </div>
    @endif

    <form action="{{ route('admin.empresas.store') }}" method="POST" class="space-y-6">
        @csrf

        <div class="bg-white rounded-xl shadow p-6 space-y-4">
            <h2 class="text-sm font-semibold text-gray-700 uppercase tracking-wide">Dados da Empresa</h2>

            <div>
                <label for="nome" class="block text-sm font-medium text-gray-700 mb-1">Nome da Empresa</label>
                <input type="text" name="nome" id="nome" required maxlength="200" value="{{ old('nome') }}"
                       class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-green-500 focus:border-green-500"
                       placeholder="Frete Rio">
            </div>

            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label for="email" class="block text-sm font-medium text-gray-700 mb-1">E-mail da Empresa</label>
                    <input type="email" name="email" id="email" required maxlength="200" value="{{ old('email') }}"
                           class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-green-500 focus:border-green-500">
                </div>
                <div>
                    <label for="telefone" class="block text-sm font-medium text-gray-700 mb-1">Telefone</label>
                    <input type="text" name="telefone" id="telefone" maxlength="30" value="{{ old('telefone') }}"
                           class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-green-500 focus:border-green-500"
                           placeholder="21999990000">
                </div>
            </div>

            <div>
                <label for="nicho" class="block text-sm font-medium text-gray-700 mb-1">Nicho</label>
                <input type="text" name="nicho" id="nicho" maxlength="100" value="{{ old('nicho', 'frete') }}"
                       class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-green-500 focus:border-green-500">
            </div>
        </div>

        <div class="bg-white rounded-xl shadow p-6 space-y-4">
            <h2 class="text-sm font-semibold text-gray-700 uppercase tracking-wide">Usuário Dono (primeiro acesso)</h2>

            <div>
                <label for="dono_nome" class="block text-sm font-medium text-gray-700 mb-1">Nome do Dono</label>
                <input type="text" name="dono_nome" id="dono_nome" required maxlength="200" value="{{ old('dono_nome') }}"
                       class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-green-500 focus:border-green-500">
            </div>

            <div>
                <label for="dono_email" class="block text-sm font-medium text-gray-700 mb-1">E-mail de Login</label>
                <input type="email" name="dono_email" id="dono_email" required maxlength="200" value="{{ old('dono_email') }}"
                       class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-green-500 focus:border-green-500">
            </div>

            <div>
                <label for="dono_senha" class="block text-sm font-medium text-gray-700 mb-1">Senha Inicial</label>
                <div class="flex gap-2">
                    <input type="text" name="dono_senha" id="dono_senha" required minlength="8" maxlength="100"
                           value="{{ old('dono_senha', $senhaSugerida) }}"
                           class="flex-1 border border-gray-300 rounded-lg px-3 py-2 text-sm font-mono focus:ring-2 focus:ring-green-500 focus:border-green-500">
                    <button type="button" onclick="document.getElementById('dono_senha').value = crypto.randomUUID().replace(/-/g, '').slice(0, 12)"
                            class="px-3 py-2 bg-gray-200 text-gray-700 rounded-lg hover:bg-gray-300 text-xs font-semibold transition whitespace-nowrap">
                        🎲 Gerar outra
                    </button>
                </div>
                <p class="text-xs text-gray-400 mt-1">Já vem preenchida com uma senha aleatória — pode trocar por qualquer outra.</p>
            </div>
        </div>

        <div class="flex gap-3">
            <button type="submit" class="px-6 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 text-sm font-semibold transition">
                Criar Empresa
            </button>
            <a href="{{ route('admin.empresas.index') }}" class="px-6 py-2 bg-gray-200 text-gray-700 rounded-lg hover:bg-gray-300 text-sm transition">
                Cancelar
            </a>
        </div>
    </form>
</div>
@endsection
