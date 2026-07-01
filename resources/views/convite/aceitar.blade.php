<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Aceitar Convite — Lead Certo</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="bg-gray-900 min-h-screen flex items-center justify-center">

<div class="w-full max-w-sm">
    <div class="text-center mb-8">
        <h1 class="text-2xl font-bold text-white">Lead Certo</h1>
        <p class="text-gray-400 text-sm mt-1">Criar sua conta</p>
    </div>

    <div class="bg-gray-800 rounded-2xl shadow-xl px-8 py-8">
        <p class="text-gray-300 text-sm mb-5">
            Você foi convidado para a equipe. Defina seu nome e senha para ativar o acesso.
        </p>
        <p class="text-gray-400 text-xs mb-5">E-mail: <span class="text-white">{{ $convite->email }}</span></p>

        @if ($errors->any())
            <div class="bg-red-900/30 border border-red-700 rounded-lg px-4 py-3 mb-4">
                <ul class="text-red-300 text-sm space-y-1">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <form method="POST" action="{{ route('convite.aceitar.store', $token) }}" class="space-y-4">
            @csrf
            <div>
                <label class="block text-sm text-gray-300 mb-1">Seu nome</label>
                <input type="text" name="nome" value="{{ old('nome', $convite->nome ?? '') }}" required
                       class="w-full bg-gray-700 border border-gray-600 rounded-lg px-4 py-2.5 text-white text-sm placeholder-gray-500 focus:ring-2 focus:ring-green-500 focus:outline-none" />
            </div>
            <div>
                <label class="block text-sm text-gray-300 mb-1">Senha</label>
                <input type="password" name="password" required minlength="8"
                       class="w-full bg-gray-700 border border-gray-600 rounded-lg px-4 py-2.5 text-white text-sm placeholder-gray-500 focus:ring-2 focus:ring-green-500 focus:outline-none" />
            </div>
            <div>
                <label class="block text-sm text-gray-300 mb-1">Confirmar senha</label>
                <input type="password" name="password_confirmation" required minlength="8"
                       class="w-full bg-gray-700 border border-gray-600 rounded-lg px-4 py-2.5 text-white text-sm placeholder-gray-500 focus:ring-2 focus:ring-green-500 focus:outline-none" />
            </div>
            <button type="submit"
                    class="w-full bg-green-600 hover:bg-green-500 text-white font-medium py-2.5 rounded-lg text-sm transition-colors mt-2">
                Ativar minha conta
            </button>
        </form>
    </div>
</div>

</body>
</html>
