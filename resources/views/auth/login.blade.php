<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login — Lead Certo</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="bg-gray-900 min-h-screen flex items-center justify-center">

<div class="w-full max-w-sm">
    <div class="text-center mb-8">
        <h1 class="text-2xl font-bold text-white">Lead Certo</h1>
        <p class="text-gray-400 text-sm mt-1">Painel de Atendimento</p>
    </div>

    <div class="bg-gray-800 rounded-2xl shadow-xl px-8 py-8">
        <form method="POST" action="{{ route('login') }}">
            @csrf

            <div class="mb-5">
                <label class="block text-sm text-gray-300 mb-1.5" for="email">E-mail</label>
                <input
                    id="email" name="email" type="email" required autocomplete="email"
                    value="{{ old('email') }}"
                    class="w-full bg-gray-700 border border-gray-600 rounded-lg px-4 py-2.5 text-white text-sm placeholder-gray-500 focus:outline-none focus:ring-2 focus:ring-green-500 @error('email') border-red-500 @enderror"
                    placeholder="seu@email.com"
                >
                @error('email')
                    <p class="text-red-400 text-xs mt-1">{{ $message }}</p>
                @enderror
            </div>

            <div class="mb-6">
                <label class="block text-sm text-gray-300 mb-1.5" for="password">Senha</label>
                <input
                    id="password" name="password" type="password" required autocomplete="current-password"
                    class="w-full bg-gray-700 border border-gray-600 rounded-lg px-4 py-2.5 text-white text-sm placeholder-gray-500 focus:outline-none focus:ring-2 focus:ring-green-500"
                    placeholder="••••••••"
                >
            </div>

            <button type="submit"
                    class="w-full bg-green-600 hover:bg-green-500 text-white font-semibold py-2.5 rounded-lg transition-colors text-sm">
                Entrar
            </button>
        </form>
    </div>
</div>

</body>
</html>
