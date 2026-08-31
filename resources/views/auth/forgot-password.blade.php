<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Recuperar Senha — Lead Certo</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="bg-gray-950 min-h-screen flex items-center justify-center p-4">

<div class="w-full max-w-sm">
    <div class="text-center mb-8">
        <h1 class="text-2xl font-bold text-white tracking-tight font-heading">Lead Certo</h1>
        <p class="text-gray-400 text-sm mt-1">Recuperação de Senha</p>
    </div>

    @if(session('sucesso'))
        <div class="mb-4 p-4 bg-green-900/40 border border-green-700/50 text-green-300 rounded-2xl text-xs space-y-2">
            <p class="font-bold flex items-center gap-1.5">
                <span>✅</span>
                <span>{{ session('sucesso') }}</span>
            </p>
            @if(session('resetUrl'))
                <div class="p-2.5 bg-gray-900 rounded-xl border border-green-700/40 text-[11px] break-all">
                    <p class="text-gray-400 mb-1 font-semibold">Link de Acesso Direto:</p>
                    <a href="{{ session('resetUrl') }}" class="text-green-400 underline font-mono">
                        {{ session('resetUrl') }}
                    </a>
                </div>
            @endif
        </div>
    @endif

    <div class="bg-gray-900 border border-gray-800 rounded-2xl shadow-2xl px-7 py-7 space-y-5">
        <div>
            <h2 class="text-base font-bold text-white">Esqueceu sua senha?</h2>
            <p class="text-xs text-gray-400 mt-1 leading-relaxed">
                Informe o seu e-mail cadastrado e enviaremos as instruções para você redefinir sua senha com segurança.
            </p>
        </div>

        <form method="POST" action="{{ route('password.email') }}" class="space-y-4">
            @csrf

            <div>
                <label class="block text-xs font-semibold text-gray-300 mb-1.5" for="email">E-mail Cadastrado</label>
                <input
                    id="email" name="email" type="email" required autocomplete="email" autofocus
                    value="{{ old('email') }}"
                    class="w-full bg-gray-800 border border-gray-700 rounded-xl px-3.5 py-2.5 text-white text-sm placeholder-gray-500 focus:outline-none focus:ring-2 focus:ring-green-500 @error('email') border-red-500 @enderror"
                    placeholder="seu@email.com"
                >
                @error('email')
                    <p class="text-red-400 text-xs mt-1.5 font-medium">{{ $message }}</p>
                @enderror
            </div>

            <button type="submit"
                    class="w-full bg-green-600 hover:bg-green-500 text-white font-bold py-2.5 rounded-xl transition-all shadow-lg text-sm">
                Enviar Link de Recuperação →
            </button>
        </form>

        <div class="pt-2 text-center border-t border-gray-800">
            <a href="{{ route('login') }}" class="text-xs text-gray-400 hover:text-white transition inline-flex items-center gap-1">
                ← Voltar para a tela de Login
            </a>
        </div>
    </div>
</div>

</body>
</html>
