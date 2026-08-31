@extends('layouts.app')
@section('title', 'Editar Empresa — ' . $empresa->nome)

@section('content')
<div class="max-w-5xl mx-auto space-y-6" x-data="{ tab: 'geral' }">
    
    {{-- Header --}}
    <div class="flex items-center justify-between border-b border-gray-200 pb-4">
        <div>
            <div class="flex items-center gap-2 text-xs text-gray-500 mb-1">
                <a href="{{ route('admin.empresas.index') }}" class="hover:underline">Empresas</a>
                <span>/</span>
                <a href="{{ route('admin.empresas.show', $empresa) }}" class="hover:underline">{{ $empresa->nome }}</a>
                <span>/</span>
                <span class="text-gray-700">Editar Configurações</span>
            </div>
            <h1 class="text-2xl font-bold text-gray-900 font-heading">⚙️ Configurações: {{ $empresa->nome }}</h1>
        </div>
        <div class="flex items-center gap-2">
            <a href="{{ route('admin.empresas.show', $empresa) }}" class="px-4 py-2 bg-gray-100 hover:bg-gray-200 text-gray-700 text-sm font-medium rounded-lg transition">
                Ver Detalhes
            </a>
            <a href="{{ route('admin.empresas.index') }}" class="px-4 py-2 bg-gray-100 hover:bg-gray-200 text-gray-700 text-sm font-medium rounded-lg transition">
                Lista
            </a>
        </div>
    </div>

    @if($errors->any())
        <div class="p-4 bg-red-50 border border-red-200 text-red-800 rounded-xl text-sm space-y-1">
            <p class="font-bold">Atenção! Verifique os seguintes campos:</p>
            <ul class="list-disc list-inside text-xs">
                @foreach($errors->all() as $error) <li>{{ $error }}</li> @endforeach
            </ul>
        </div>
    @endif

    {{-- Tabs Navigation --}}
    <div class="flex border-b border-gray-200 bg-white rounded-t-xl px-4 pt-2 gap-2 overflow-x-auto">
        <button type="button" @click="tab = 'geral'" :class="tab === 'geral' ? 'border-green-600 text-green-700 font-bold border-b-2 bg-green-50/50' : 'text-gray-500 hover:text-gray-700'" class="px-4 py-3 text-sm transition flex items-center gap-2 whitespace-nowrap rounded-t-lg">
            <span>🏢 1. Dados Gerais</span>
        </button>

        <button type="button" @click="tab = 'redes'" :class="tab === 'redes' ? 'border-green-600 text-green-700 font-bold border-b-2 bg-green-50/50' : 'text-gray-500 hover:text-gray-700'" class="px-4 py-3 text-sm transition flex items-center gap-2 whitespace-nowrap rounded-t-lg">
            <span>📱 2. Redes & Google Maps</span>
        </button>

        <button type="button" @click="tab = 'ia'" :class="tab === 'ia' ? 'border-green-600 text-green-700 font-bold border-b-2 bg-green-50/50' : 'text-gray-500 hover:text-gray-700'" class="px-4 py-3 text-sm transition flex items-center gap-2 whitespace-nowrap rounded-t-lg">
            <span>✨ 3. IA & Posicionamento</span>
        </button>

        <button type="button" @click="tab = 'google'" :class="tab === 'google' ? 'border-green-600 text-green-700 font-bold border-b-2 bg-green-50/50' : 'text-gray-500 hover:text-gray-700'" class="px-4 py-3 text-sm transition flex items-center gap-2 whitespace-nowrap rounded-t-lg">
            <span>🔐 4. Cofre: Google & Ads</span>
        </button>

        <button type="button" @click="tab = 'meta'" :class="tab === 'meta' ? 'border-green-600 text-green-700 font-bold border-b-2 bg-green-50/50' : 'text-gray-500 hover:text-gray-700'" class="px-4 py-3 text-sm transition flex items-center gap-2 whitespace-nowrap rounded-t-lg">
            <span>⚡ 5. Cofre: Meta Ads</span>
        </button>
    </div>

    <form action="{{ route('admin.empresas.update', $empresa) }}" method="POST" class="space-y-6">
        @csrf
        @method('PUT')

        {{-- TAB 1: DADOS GERAIS --}}
        <div x-show="tab === 'geral'" class="bg-white rounded-b-xl rounded-t-none border border-t-0 border-gray-200 shadow-sm p-6 space-y-5">
            <h3 class="text-sm font-bold text-gray-800 uppercase tracking-wider border-b pb-2">Informações Cadastrais</h3>

            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                <div>
                    <label class="block text-xs font-semibold text-gray-700 mb-1">Nome Fantasia / Marca *</label>
                    <input type="text" name="nome" required value="{{ old('nome', $empresa->nome) }}" class="w-full border-gray-300 rounded-lg text-sm">
                </div>

                <div>
                    <label class="block text-xs font-semibold text-gray-700 mb-1">Razão Social</label>
                    <input type="text" name="razao_social" value="{{ old('razao_social', $empresa->razao_social) }}" class="w-full border-gray-300 rounded-lg text-sm">
                </div>

                <div>
                    <label class="block text-xs font-semibold text-gray-700 mb-1">CNPJ / CPF</label>
                    <input type="text" name="cnpj" value="{{ old('cnpj', $empresa->cnpj) }}" class="w-full border-gray-300 rounded-lg text-sm font-mono">
                </div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-3 gap-4 pt-2">
                <div>
                    <label class="block text-xs font-semibold text-gray-700 mb-1">Nicho / Segmento *</label>
                    <input type="text" name="nicho" required value="{{ old('nicho', $empresa->nicho) }}" class="w-full border-gray-300 rounded-lg text-sm">
                </div>

                <div>
                    <label class="block text-xs font-semibold text-gray-700 mb-1">Status da Empresa</label>
                    <select name="status" class="w-full border-gray-300 rounded-lg text-sm">
                        <option value="ativo" {{ old('status', $empresa->status) == 'ativo' ? 'selected' : '' }}>Ativo</option>
                        <option value="inativo" {{ old('status', $empresa->status) == 'inativo' ? 'selected' : '' }}>Inativo</option>
                        <option value="suspenso" {{ old('status', $empresa->status) == 'suspenso' ? 'selected' : '' }}>Suspenso</option>
                    </select>
                </div>

                <div>
                    <label class="block text-xs font-semibold text-gray-700 mb-1">Site Oficial</label>
                    <input type="url" name="site_url" value="{{ old('site_url', $empresa->site_url) }}" class="w-full border-gray-300 rounded-lg text-sm">
                </div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 pt-2">
                <div>
                    <label class="block text-xs font-semibold text-gray-700 mb-1">E-mail Comercial *</label>
                    <input type="email" name="email" required value="{{ old('email', $empresa->email) }}" class="w-full border-gray-300 rounded-lg text-sm">
                </div>

                <div>
                    <label class="block text-xs font-semibold text-gray-700 mb-1">Telefone Comercial</label>
                    <input type="text" name="telefone" value="{{ old('telefone', $empresa->telefone) }}" class="w-full border-gray-300 rounded-lg text-sm font-mono">
                </div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-4 gap-4 pt-2">
                <div class="md:col-span-2">
                    <label class="block text-xs font-semibold text-gray-700 mb-1">Endereço</label>
                    <input type="text" name="endereco" value="{{ old('endereco', $empresa->endereco) }}" class="w-full border-gray-300 rounded-lg text-sm">
                </div>
                <div>
                    <label class="block text-xs font-semibold text-gray-700 mb-1">Cidade</label>
                    <input type="text" name="cidade" value="{{ old('cidade', $empresa->cidade) }}" class="w-full border-gray-300 rounded-lg text-sm">
                </div>
                <div>
                    <label class="block text-xs font-semibold text-gray-700 mb-1">Estado</label>
                    <input type="text" name="estado" value="{{ old('estado', $empresa->estado) }}" maxlength="2" class="w-full border-gray-300 rounded-lg text-sm uppercase">
                </div>
            </div>

            <div class="flex justify-end pt-4">
                <button type="submit" class="px-6 py-2 bg-green-600 hover:bg-green-700 text-white text-sm font-bold rounded-lg shadow">Salvar Alterações</button>
            </div>
        </div>

        {{-- TAB 2: REDES SOCIAIS & GOOGLE MAPS --}}
        <div x-show="tab === 'redes'" class="bg-white rounded-b-xl rounded-t-none border border-t-0 border-gray-200 shadow-sm p-6 space-y-5">
            <h3 class="text-sm font-bold text-gray-800 uppercase tracking-wider border-b pb-2">Presença Digital</h3>

            <div class="space-y-4">
                <div>
                    <label class="block text-xs font-semibold text-gray-700 mb-1">Google Meu Negócio (Google Maps URL)</label>
                    <input type="url" name="gmb_url" value="{{ old('gmb_url', $empresa->gmb_url) }}" class="w-full border-gray-300 rounded-lg text-sm">
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-xs font-semibold text-gray-700 mb-1">Instagram URL</label>
                        <input type="url" name="instagram_url" value="{{ old('instagram_url', $empresa->instagram_url) }}" class="w-full border-gray-300 rounded-lg text-sm">
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-gray-700 mb-1">Facebook URL</label>
                        <input type="url" name="facebook_url" value="{{ old('facebook_url', $empresa->facebook_url) }}" class="w-full border-gray-300 rounded-lg text-sm">
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-gray-700 mb-1">YouTube URL</label>
                        <input type="url" name="youtube_url" value="{{ old('youtube_url', $empresa->youtube_url) }}" class="w-full border-gray-300 rounded-lg text-sm">
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-gray-700 mb-1">LinkedIn URL</label>
                        <input type="url" name="linkedin_url" value="{{ old('linkedin_url', $empresa->linkedin_url) }}" class="w-full border-gray-300 rounded-lg text-sm">
                    </div>
                </div>
            </div>

            <div class="flex justify-end pt-4">
                <button type="submit" class="px-6 py-2 bg-green-600 hover:bg-green-700 text-white text-sm font-bold rounded-lg shadow">Salvar Alterações</button>
            </div>
        </div>

        {{-- TAB 3: IA & POSICIONAMENTO --}}
        <div x-show="tab === 'ia'" class="bg-white rounded-b-xl rounded-t-none border border-t-0 border-gray-200 shadow-sm p-6 space-y-5">
            <h3 class="text-sm font-bold text-gray-800 uppercase tracking-wider border-b pb-2">Contexto para Agentes de IA</h3>

            <div class="space-y-4">
                <div>
                    <label class="block text-xs font-semibold text-gray-700 mb-1">Descrição do Negócio</label>
                    <textarea name="descricao_negocio" rows="3" class="w-full border-gray-300 rounded-lg text-sm">{{ old('descricao_negocio', $empresa->descricao_negocio) }}</textarea>
                </div>
                <div>
                    <label class="block text-xs font-semibold text-gray-700 mb-1">Público-Alvo</label>
                    <textarea name="publico_alvo" rows="2" class="w-full border-gray-300 rounded-lg text-sm">{{ old('publico_alvo', $empresa->publico_alvo) }}</textarea>
                </div>
                <div>
                    <label class="block text-xs font-semibold text-gray-700 mb-1">Diferenciais</label>
                    <textarea name="diferenciais" rows="2" class="w-full border-gray-300 rounded-lg text-sm">{{ old('diferenciais', $empresa->diferenciais) }}</textarea>
                </div>
            </div>

            <div class="flex justify-end pt-4">
                <button type="submit" class="px-6 py-2 bg-green-600 hover:bg-green-700 text-white text-sm font-bold rounded-lg shadow">Salvar Alterações</button>
            </div>
        </div>

        {{-- TAB 4: COFRE GOOGLE & ADS --}}
        <div x-show="tab === 'google'" class="bg-white rounded-b-xl rounded-t-none border border-t-0 border-gray-200 shadow-sm p-6 space-y-5" x-data="{ verSenha: false }">
            <div class="flex items-center justify-between border-b pb-2">
                <h3 class="text-sm font-bold text-gray-800 uppercase tracking-wider">Cofre de Credenciais: Google & Google Ads</h3>
                <span class="text-xs bg-purple-100 text-purple-800 font-mono px-2 py-0.5 rounded font-semibold">🔒 Criptografia Ativa</span>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label class="block text-xs font-semibold text-gray-700 mb-1">E-mail da Conta Google</label>
                    <input type="email" name="google_conta_email" value="{{ old('google_conta_email', $empresa->google_conta_email) }}" class="w-full border-gray-300 rounded-lg text-sm font-mono">
                </div>

                <div>
                    <label class="block text-xs font-semibold text-gray-700 mb-1">Senha de Aplicativo (Deixe em branco para manter)</label>
                    <input type="password" name="google_conta_senha" placeholder="••••••••••••" class="w-full border-gray-300 rounded-lg text-sm font-mono">
                </div>

                <div>
                    <label class="block text-xs font-semibold text-gray-700 mb-1">Google Ads Customer ID (CID)</label>
                    <input type="text" name="google_ads_customer_id" value="{{ old('google_ads_customer_id', $empresa->google_ads_customer_id) }}" class="w-full border-gray-300 rounded-lg text-sm font-mono">
                </div>

                <div>
                    <label class="block text-xs font-semibold text-gray-700 mb-1">Google Business Location ID</label>
                    <input type="text" name="google_business_location_id" value="{{ old('google_business_location_id', $empresa->google_business_location_id) }}" class="w-full border-gray-300 rounded-lg text-sm font-mono">
                </div>
            </div>

            <div class="border-t border-gray-100 pt-3 space-y-4">
                <h4 class="text-xs font-bold text-gray-700 uppercase">Tokens da API Google Ads (Deixe em branco para manter)</h4>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-xs font-semibold text-gray-700 mb-1">Developer Token</label>
                        <input type="password" name="google_ads_developer_token" placeholder="••••••••••••" class="w-full border-gray-300 rounded-lg text-sm font-mono">
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-gray-700 mb-1">OAuth Refresh Token</label>
                        <input type="password" name="google_ads_refresh_token" placeholder="••••••••••••" class="w-full border-gray-300 rounded-lg text-sm font-mono">
                    </div>
                </div>
            </div>

            <div class="flex justify-end pt-4">
                <button type="submit" class="px-6 py-2 bg-green-600 hover:bg-green-700 text-white text-sm font-bold rounded-lg shadow">Salvar Alterações</button>
            </div>
        </div>

        {{-- TAB 5: COFRE META ADS --}}
        <div x-show="tab === 'meta'" class="bg-white rounded-b-xl rounded-t-none border border-t-0 border-gray-200 shadow-sm p-6 space-y-5">
            <div class="flex items-center justify-between border-b pb-2">
                <h3 class="text-sm font-bold text-gray-800 uppercase tracking-wider">Cofre de Credenciais: Meta Ads</h3>
                <span class="text-xs bg-purple-100 text-purple-800 font-mono px-2 py-0.5 rounded font-semibold">🔒 Criptografia Ativa</span>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                <div>
                    <label class="block text-xs font-semibold text-gray-700 mb-1">Business Manager ID</label>
                    <input type="text" name="meta_bm_id" value="{{ old('meta_bm_id', $empresa->meta_bm_id) }}" class="w-full border-gray-300 rounded-lg text-sm font-mono">
                </div>
                <div>
                    <label class="block text-xs font-semibold text-gray-700 mb-1">Conta de Anúncios ID (Ad Account)</label>
                    <input type="text" name="meta_ad_account_id" value="{{ old('meta_ad_account_id', $empresa->meta_ad_account_id) }}" class="w-full border-gray-300 rounded-lg text-sm font-mono">
                </div>
                <div>
                    <label class="block text-xs font-semibold text-gray-700 mb-1">Meta Pixel ID</label>
                    <input type="text" name="meta_pixel_id" value="{{ old('meta_pixel_id', $empresa->meta_pixel_id) }}" class="w-full border-gray-300 rounded-lg text-sm font-mono">
                </div>
            </div>

            <div>
                <label class="block text-xs font-semibold text-gray-700 mb-1">Access Token (Deixe em branco para manter)</label>
                <textarea name="meta_access_token" rows="2" placeholder="••••••••••••" class="w-full border-gray-300 rounded-lg text-sm font-mono"></textarea>
            </div>

            <div class="flex justify-end pt-4">
                <button type="submit" class="px-6 py-2 bg-green-600 hover:bg-green-700 text-white text-sm font-bold rounded-lg shadow">Salvar Alterações</button>
            </div>
        </div>

    </form>
</div>
@endsection
