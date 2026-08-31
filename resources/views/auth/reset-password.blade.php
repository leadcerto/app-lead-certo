<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Redefinir Senha — Lead Certo</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="bg-gray-950 min-h-screen flex items-center justify-center p-4">

<div class="w-full max-w-sm" x-data="{ showPass: false }">
    <div class="text-center mb-8">
        <h1 class="text-2xl font-bold text-white tracking-tight font-heading">Lead Certo</h1>
        <p class="text-gray-400 text-sm mt-1">Criação de Nova Senha</p>
    </div>

    @if($errors->any())
        <div class="mb-4 p-3.5 bg-red-900/40 border border-red-700/50 text-red-300 rounded-xl text-xs space-y-1">
            @foreach($errors->all() as $error)
                <p>{{ $error }}</p>
            @endforeach
        </div>
    @endif

    <div class="bg-gray-900 border border-gray-800 rounded-2xl shadow-2xl px-7 py-7 space-y-5">
        <div>
            <h2 class="text-base font-bold text-white">Defina sua nova senha</h2>
            <p class="text-xs text-gray-400 mt-1 leading-relaxed">
                Digite sua nova senha abaixo para atualizar seu acesso.
            </p>
        </div>

        <form method="POST" action="{{ route('password.update') }}" class="space-y-4">
            @csrf
            <input type="hidden" name="token" value="{{ $token }}">

            <div>
                <label class="block text-xs font-semibold text-gray-300 mb-1.5" for="email">E-mail</label>
                <input
                    id="email" name="email" type="email" required
                    value="{{ old('email', $email) }}" readonly
                    class="w-full bg-gray-800/60 border border-gray-700 rounded-xl px-3.5 py-2.5 text-gray-400 text-sm focus:outline-none"
                >
            </div>

            <div>
                <div class="flex items-center justify-between mb-1.5">
                    <label class="block text-xs font-semibold text-gray-300" for="password">Nova Senha</label>
                    <button type="button" @click="showPass = !showPass" class="text-xs text-green-400 hover:underline">
                        <span x-text="showPass ? 'Ocultar' : 'Mostrar'"></span>
                    </button>
                </div>
                <input
                    id="password" name="password" :type="showPass ? 'text' : 'password'" required autofocus
                    class="w-full bg-gray-800 border border-gray-700 rounded-xl px-3.5 py-2.5 text-white text-sm placeholder-gray-500 focus:outline-none focus:ring-2 focus:ring-green-500 @error('password') border-red-500 @enderror"
                    placeholder="Mínimo 8 caracteres"
                >
            </div>

            <div>
                <label class="block text-xs font-semibold text-gray-300 mb-1.5" for="password_confirmation">Confirme a Nova Senha</label>
                <input
                    id="password_confirmation" name="password_confirmation" :type="showPass ? 'text' : 'password'" required
                    class="w-full bg-gray-800 border border-gray-700 rounded-xl px-3.5 py-2.5 text-white text-sm placeholder-gray-500 focus:outline-none focus:ring-2 focus:ring-green-500"
                    placeholder="Digite a mesma senha novamente"
                >
            </div>

            <button type="submit"
                    class="w-full bg-green-600 hover:bg-green-500 text-white font-bold py-2.5 rounded-xl transition-all shadow-lg text-sm">
                Salvar Nova Senha & Entrar →
            </button>
        </form>

        <div class="pt-2 text-center border-t border-gray-800">
            <a href="{{ route('login') }}" class="text-xs text-gray-400 hover:text-white transition">
                ← Cancelar e voltar ao Login
            </a>
        </div>
    </div>
</div>

</body>
</html>
