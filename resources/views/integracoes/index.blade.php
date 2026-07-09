@extends('layouts.app')

@section('title', 'Integrações — Lead Certo')

@section('content')
<div class="max-w-2xl">

    <h1 class="text-xl font-bold text-gray-800 mb-1">Integrações</h1>
    <p class="text-sm text-gray-500 mb-6">Conecte serviços externos para expandir as funcionalidades do Lead Certo</p>

    @if(session('sucesso'))
        <div class="bg-green-50 border border-green-200 text-green-700 text-sm rounded-xl px-4 py-3 mb-5">
            {{ session('sucesso') }}
        </div>
    @endif

    @if(session('erro'))
        <div class="bg-red-50 border border-red-200 text-red-700 text-sm rounded-xl px-4 py-3 mb-5">
            {{ session('erro') }}
        </div>
    @endif

    {{-- Google Workspace --}}
    <div class="bg-white rounded-2xl shadow-sm p-6 mb-4">
        <div class="flex items-start justify-between gap-4">
            <div class="flex items-center gap-4">
                {{-- Logo Google --}}
                <div class="w-12 h-12 rounded-xl bg-white border border-gray-200 flex items-center justify-center flex-shrink-0">
                    <svg viewBox="0 0 24 24" class="w-7 h-7">
                        <path fill="#4285F4" d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09z"/>
                        <path fill="#34A853" d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z"/>
                        <path fill="#FBBC05" d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.07H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.93l2.85-2.22.81-.62z"/>
                        <path fill="#EA4335" d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.07l3.66 2.84c.87-2.6 3.3-4.53 6.16-4.53z"/>
                    </svg>
                </div>
                <div>
                    <p class="font-semibold text-gray-800">Google Workspace</p>
                    <p class="text-xs text-gray-500 mt-0.5">Contacts · Drive · Sheets · Calendar · Gmail</p>
                </div>
            </div>

            @if($google_conectado)
                <span class="flex items-center gap-1.5 text-xs text-green-600 font-medium bg-green-50 border border-green-200 px-3 py-1.5 rounded-lg flex-shrink-0">
                    <span class="w-1.5 h-1.5 rounded-full bg-green-500"></span>
                    Conectado
                </span>
            @else
                <span class="text-xs text-gray-400 flex-shrink-0">Não conectado</span>
            @endif
        </div>

        <div class="mt-5 space-y-4">

            @if($google_conectado)
                {{-- Conta conectada --}}
                <div class="bg-gray-50 rounded-xl p-4 space-y-2 text-sm">
                    <div class="flex justify-between">
                        <span class="text-gray-500">Conta</span>
                        <span class="font-medium text-gray-700">{{ $google_email }}</span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-gray-500">Token expira</span>
                        <span class="text-gray-700">{{ $google_expira }}</span>
                    </div>
                </div>

                {{-- Acessos concedidos --}}
                <div>
                    <p class="text-xs font-medium text-gray-500 mb-2">Acessos concedidos:</p>
                    <div class="flex flex-wrap gap-2">
                        @foreach([
                            'contacts'    => 'Contacts',
                            'drive'       => 'Drive',
                            'calendar'    => 'Calendar',
                            'mail.google' => 'Gmail',
                        ] as $key => $label)
                            @php $ativo = collect($google_scopes)->contains(fn($s) => str_contains($s, $key)); @endphp
                            @if($ativo)
                                <span class="text-xs px-3 py-1 rounded-full border border-green-300 bg-green-50 text-green-700 font-medium">{{ $label }}</span>
                            @else
                                <span class="text-xs px-3 py-1 rounded-full border border-gray-200 bg-gray-50 text-gray-400">{{ $label }}</span>
                            @endif
                        @endforeach
                    </div>
                </div>

                <div class="flex gap-3">
                    <a href="{{ route('google.autorizar') }}"
                       class="flex-1 text-center py-2 rounded-xl text-sm font-medium border border-gray-300 text-gray-600 hover:bg-gray-50 transition-colors">
                        Reconectar
                    </a>
                    <form method="POST" action="{{ route('google.desconectar') }}" class="flex-1">
                        @csrf
                        <button type="submit"
                                class="w-full py-2 rounded-xl text-sm font-medium bg-red-50 text-red-600 hover:bg-red-100 transition-colors">
                            Desconectar
                        </button>
                    </form>
                </div>

            @else
                {{-- Não conectado --}}
                <div class="space-y-2 text-sm text-gray-500">
                    <p class="font-medium text-gray-700">O que será liberado:</p>
                    <ul class="space-y-1.5">
                        <li class="flex items-center gap-2">
                            <svg class="w-4 h-4 text-green-500 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                            </svg>
                            Sincronizar agenda do Google Contacts automaticamente
                        </li>
                        <li class="flex items-center gap-2">
                            <svg class="w-4 h-4 text-green-500 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                            </svg>
                            Criar eventos no Google Agenda (follow-ups, reuniões)
                        </li>
                        <li class="flex items-center gap-2">
                            <svg class="w-4 h-4 text-green-500 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                            </svg>
                            Exportar relatórios para Google Sheets / Drive
                        </li>
                        <li class="flex items-center gap-2">
                            <svg class="w-4 h-4 text-green-500 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                            </svg>
                            Enviar e-mails via Gmail para clientes
                        </li>
                    </ul>
                </div>

                @if(config('services.google.client_id'))
                    <a href="{{ route('google.autorizar') }}"
                       class="flex items-center justify-center gap-3 w-full py-2.5 rounded-xl border border-gray-300 hover:bg-gray-50 transition-colors text-sm font-medium text-gray-700">
                        <svg viewBox="0 0 24 24" class="w-5 h-5">
                            <path fill="#4285F4" d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09z"/>
                            <path fill="#34A853" d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z"/>
                            <path fill="#FBBC05" d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.07H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.93l2.85-2.22.81-.62z"/>
                            <path fill="#EA4335" d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.07l3.66 2.84c.87-2.6 3.3-4.53 6.16-4.53z"/>
                        </svg>
                        Conectar com Google
                    </a>
                @else
                    <div class="bg-yellow-50 border border-yellow-200 rounded-xl p-4 text-sm text-yellow-800">
                        <p class="font-medium mb-1">Configuração pendente</p>
                        <p>Adicione as credenciais do Google Cloud no arquivo <code class="bg-yellow-100 px-1 rounded">.env</code>:</p>
                        <pre class="mt-2 text-xs bg-yellow-100 rounded p-2 overflow-x-auto">GOOGLE_CLIENT_ID=seu_client_id
GOOGLE_CLIENT_SECRET=seu_client_secret
GOOGLE_REDIRECT_URI={{ config('app.url') }}/google/callback</pre>
                    </div>
                @endif
            @endif
        </div>
    </div>

</div>
@endsection
