<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login — Lead Certo</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="bg-gray-950 min-h-screen flex items-center justify-center p-4">

<div class="w-full max-w-sm" x-data="{ showPass: false }">
    <div class="text-center mb-8">
        <h1 class="text-2xl font-bold text-white tracking-tight font-heading">Lead Certo</h1>
        <p class="text-gray-400 text-sm mt-1">Painel de Atendimento & Inteligência Comercial</p>
    </div>

    @if(session('sucesso'))
        <div class="mb-4 p-3.5 bg-green-900/40 border border-green-700/50 text-green-300 rounded-xl text-xs flex items-center gap-2">
            <span>✅</span>
            <span>{{ session('sucesso') }}</span>
        </div>
    @endif

    @if(session('status'))
        <div class="mb-4 p-3.5 bg-blue-900/40 border border-blue-700/50 text-blue-300 rounded-xl text-xs flex items-center gap-2">
            <span>ℹ️</span>
            <span>{{ session('status') }}</span>
        </div>
    @endif

    <div class="bg-gray-900 border border-gray-800 rounded-2xl shadow-2xl px-7 py-7">
        <form method="POST" action="{{ route('login') }}" class="space-y-4">
            @csrf

            <div>
                <label class="block text-xs font-semibold text-gray-300 mb-1.5" for="email">E-mail de Acesso</label>
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

            <div>
                <div class="flex items-center justify-between mb-1.5">
                    <label class="block text-xs font-semibold text-gray-300" for="password">Senha</label>
                    <a href="{{ route('password.request') }}" class="text-xs text-green-400 hover:text-green-300 hover:underline transition">
                        Esqueci minha senha
                    </a>
                </div>
                
                <div class="relative">
                    <input
                        id="password" name="password" :type="showPass ? 'text' : 'password'" required autocomplete="current-password"
                        class="w-full bg-gray-800 border border-gray-700 rounded-xl px-3.5 py-2.5 text-white text-sm placeholder-gray-500 focus:outline-none focus:ring-2 focus:ring-green-500 pr-10"
                        placeholder="••••••••"
                    >
                    <button type="button" @click="showPass = !showPass" class="absolute right-3 top-2.5 text-gray-400 hover:text-gray-200 focus:outline-none transition">
                        {{-- Ícone Olho Aberto --}}
                        <svg x-show="!showPass" class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/>
                        </svg>
                        {{-- Ícone Olho Fechado --}}
                        <svg x-show="showPass" class="w-5 h-5 text-green-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M9.878 9.878l4.242 4.242M9.88 9.88l-3.29-3.29m7.532 7.532l3.29 3.29M3 3l3.59 3.59m0 0A9.953 9.953 0 0112 5c4.478 0 8.268 2.943 9.543 7a10.025 10.025 0 01-4.132 5.411m0 0L21 21"/>
                        </svg>
                    </button>
                </div>
            </div>

            <div class="flex items-center justify-between pt-1">
                <label class="flex items-center gap-2 cursor-pointer text-xs text-gray-400 hover:text-gray-300">
                    <input type="checkbox" name="remember" class="rounded bg-gray-800 border-gray-700 text-green-600 focus:ring-green-500">
                    Lembrar de mim
                </label>
            </div>

            <button type="submit"
                    class="w-full bg-green-600 hover:bg-green-500 text-white font-bold py-2.5 rounded-xl transition-all shadow-lg text-sm flex items-center justify-center gap-2">
                Entrar no Sistema →
            </button>
        </form>
    </div>
</div>

</body>
</html>
