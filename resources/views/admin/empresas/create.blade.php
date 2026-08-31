@extends('layouts.app')
@section('title', 'Cadastrar Nova Empresa — Lead Certo')

@section('content')
<div class="max-w-5xl mx-auto space-y-6" x-data="{ tab: 'geral' }">
    
    {{-- Header --}}
    <div class="flex items-center justify-between border-b border-gray-200 pb-4">
        <div>
            <div class="flex items-center gap-2 text-xs text-gray-500 mb-1">
                <a href="{{ route('admin.empresas.index') }}" class="hover:underline">Empresas</a>
                <span>/</span>
                <span class="text-gray-700">Nova Empresa</span>
            </div>
            <h1 class="text-2xl font-bold text-gray-900 font-heading">🏢 Onboarding de Empresa / Franqueada</h1>
            <p class="text-sm text-gray-500 mt-0.5">Cadastre a empresa, suas redes sociais e configure com segurança o cofre de credenciais de anúncios e automações.</p>
        </div>
        <a href="{{ route('admin.empresas.index') }}" class="px-4 py-2 bg-gray-100 hover:bg-gray-200 text-gray-700 text-sm font-medium rounded-lg transition">
            Voltar
        </a>
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

        <button type="button" @click="tab = 'dono'" :class="tab === 'dono' ? 'border-green-600 text-green-700 font-bold border-b-2 bg-green-50/50' : 'text-gray-500 hover:text-gray-700'" class="px-4 py-3 text-sm transition flex items-center gap-2 whitespace-nowrap rounded-t-lg">
            <span>👤 6. Usuário Dono</span>
        </button>
    </div>

    <form action="{{ route('admin.empresas.store') }}" method="POST" class="space-y-6">
        @csrf

        {{-- TAB 1: DADOS GERAIS --}}
        <div x-show="tab === 'geral'" class="bg-white rounded-b-xl rounded-t-none border border-t-0 border-gray-200 shadow-sm p-6 space-y-5">
            <h3 class="text-sm font-bold text-gray-800 uppercase tracking-wider border-b pb-2">Informações Cadastrais da Empresa</h3>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label class="block text-xs font-semibold text-gray-700 mb-1">Nome Fantasia / Marca *</label>
                    <input type="text" name="nome" required value="{{ old('nome') }}" placeholder="Ex: Frete Rio" class="w-full border-gray-300 rounded-lg text-sm">
                </div>

                <div>
                    <label class="block text-xs font-semibold text-gray-700 mb-1">Razão Social</label>
                    <input type="text" name="razao_social" value="{{ old('razao_social') }}" placeholder="Ex: Rio Transportes e Logística LTDA" class="w-full border-gray-300 rounded-lg text-sm">
                </div>

                <div>
                    <label class="block text-xs font-semibold text-gray-700 mb-1">CNPJ / CPF</label>
                    <input type="text" name="cnpj" value="{{ old('cnpj') }}" placeholder="00.000.000/0001-00" class="w-full border-gray-300 rounded-lg text-sm font-mono">
                </div>

                <div>
                    <label class="block text-xs font-semibold text-gray-700 mb-1">Nicho / Segmento *</label>
                    <select name="nicho" required class="w-full border-gray-300 rounded-lg text-sm">
                        <option value="frete" {{ old('nicho') == 'frete' ? 'selected' : '' }}>Fretes & Mudanças</option>
                        <option value="pizza" {{ old('nicho') == 'pizza' ? 'selected' : '' }}>Pizzaria / Gastronomia</option>
                        <option value="imoveis_caixa" {{ old('nicho') == 'imoveis_caixa' ? 'selected' : '' }}>Imóveis & Leilões Caixa</option>
                        <option value="varejo" {{ old('nicho') == 'varejo' ? 'selected' : '' }}>Comércio / Varejo</option>
                        <option value="servicos" {{ old('nicho') == 'servicos' ? 'selected' : '' }}>Prestação de Serviços</option>
                        <option value="educacao" {{ old('nicho') == 'educacao' ? 'selected' : '' }}>Educação & Network</option>
                    </select>
                </div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-3 gap-4 pt-2">
                <div>
                    <label class="block text-xs font-semibold text-gray-700 mb-1">E-mail Comercial *</label>
                    <input type="email" name="email" required value="{{ old('email') }}" placeholder="contato@empresa.com" class="w-full border-gray-300 rounded-lg text-sm">
                </div>

                <div>
                    <label class="block text-xs font-semibold text-gray-700 mb-1">Telefone / WhatsApp Comercial</label>
                    <input type="text" name="telefone" value="{{ old('telefone') }}" placeholder="21981813106" class="w-full border-gray-300 rounded-lg text-sm font-mono">
                </div>

                <div>
                    <label class="block text-xs font-semibold text-gray-700 mb-1">Site Oficial / Landing Page</label>
                    <input type="url" name="site_url" value="{{ old('site_url') }}" placeholder="https://frete.rio.br" class="w-full border-gray-300 rounded-lg text-sm">
                </div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-4 gap-4 pt-2">
                <div class="md:col-span-2">
                    <label class="block text-xs font-semibold text-gray-700 mb-1">Endereço Completo</label>
                    <input type="text" name="endereco" value="{{ old('endereco') }}" placeholder="Rua / Av, Número, Bairro" class="w-full border-gray-300 rounded-lg text-sm">
                </div>

                <div>
                    <label class="block text-xs font-semibold text-gray-700 mb-1">Cidade</label>
                    <input type="text" name="cidade" value="{{ old('cidade', 'Rio de Janeiro') }}" class="w-full border-gray-300 rounded-lg text-sm">
                </div>

                <div>
                    <label class="block text-xs font-semibold text-gray-700 mb-1">Estado (UF)</label>
                    <input type="text" name="estado" value="{{ old('estado', 'RJ') }}" maxlength="2" class="w-full border-gray-300 rounded-lg text-sm uppercase">
                </div>
            </div>

            <div class="flex justify-end pt-4">
                <button type="button" @click="tab = 'redes'" class="px-5 py-2 bg-green-600 hover:bg-green-700 text-white text-sm font-semibold rounded-lg">
                    Avançar para Redes Sociais →
                </button>
            </div>
        </div>

        {{-- TAB 2: REDES SOCIAIS & GOOGLE MAPS --}}
        <div x-show="tab === 'redes'" class="bg-white rounded-b-xl rounded-t-none border border-t-0 border-gray-200 shadow-sm p-6 space-y-5">
            <h3 class="text-sm font-bold text-gray-800 uppercase tracking-wider border-b pb-2">Presença Digital & Canais</h3>

            <div class="space-y-4">
                <div>
                    <label class="block text-xs font-semibold text-gray-700 mb-1">Link do Perfil no Google Meu Negócio (Google Maps URL)</label>
                    <input type="url" name="gmb_url" value="{{ old('gmb_url') }}" placeholder="https://maps.app.goo.gl/..." class="w-full border-gray-300 rounded-lg text-sm">
                    <p class="text-xs text-gray-400 mt-1">Usado para agendamento automático de avaliações e posts locais.</p>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-xs font-semibold text-gray-700 mb-1">Instagram URL</label>
                        <input type="url" name="instagram_url" value="{{ old('instagram_url') }}" placeholder="https://instagram.com/freterio" class="w-full border-gray-300 rounded-lg text-sm">
                    </div>

                    <div>
                        <label class="block text-xs font-semibold text-gray-700 mb-1">Facebook Page URL</label>
                        <input type="url" name="facebook_url" value="{{ old('facebook_url') }}" placeholder="https://facebook.com/freterio" class="w-full border-gray-300 rounded-lg text-sm">
                    </div>

                    <div>
                        <label class="block text-xs font-semibold text-gray-700 mb-1">YouTube Channel URL</label>
                        <input type="url" name="youtube_url" value="{{ old('youtube_url') }}" placeholder="https://youtube.com/@freterio" class="w-full border-gray-300 rounded-lg text-sm">
                    </div>

                    <div>
                        <label class="block text-xs font-semibold text-gray-700 mb-1">LinkedIn URL</label>
                        <input type="url" name="linkedin_url" value="{{ old('linkedin_url') }}" placeholder="https://linkedin.com/company/freterio" class="w-full border-gray-300 rounded-lg text-sm">
                    </div>
                </div>
            </div>

            <div class="flex justify-between pt-4">
                <button type="button" @click="tab = 'geral'" class="px-4 py-2 border border-gray-300 text-gray-700 text-sm font-medium rounded-lg">← Voltar</button>
                <button type="button" @click="tab = 'ia'" class="px-5 py-2 bg-green-600 hover:bg-green-700 text-white text-sm font-semibold rounded-lg">Avançar para IA →</button>
            </div>
        </div>

        {{-- TAB 3: CONTEXTO DE IA & POSICIONAMENTO --}}
        <div x-show="tab === 'ia'" class="bg-white rounded-b-xl rounded-t-none border border-t-0 border-gray-200 shadow-sm p-6 space-y-5">
            <h3 class="text-sm font-bold text-gray-800 uppercase tracking-wider border-b pb-2">Contexto Estratégico (Alimenta Agentes IA, Anúncios e Posts)</h3>

            <div class="space-y-4">
                <div>
                    <label class="block text-xs font-semibold text-gray-700 mb-1">O que a empresa faz (Descrição em 2 a 3 frases)</label>
                    <textarea name="descricao_negocio" rows="3" placeholder="Ex: Especialistas em fretes expressos, mudanças residenciais e comerciais no RJ com 5 estrelas no Google..." class="w-full border-gray-300 rounded-lg text-sm">{{ old('descricao_negocio') }}</textarea>
                </div>

                <div>
                    <label class="block text-xs font-semibold text-gray-700 mb-1">Perfil do Público-Alvo / Personas</label>
                    <textarea name="publico_alvo" rows="2" placeholder="Ex: Famílias se mudando de bairro, empresas que precisam de logística rápida no RJ..." class="w-full border-gray-300 rounded-lg text-sm">{{ old('publico_alvo') }}</textarea>
                </div>

                <div>
                    <label class="block text-xs font-semibold text-gray-700 mb-1">Principais Diferenciais Competitivos</label>
                    <textarea name="diferenciais" rows="2" placeholder="Ex: Embalagem inclusa, ajudantes uniformizados, orçamento em 5 minutos pelo WhatsApp..." class="w-full border-gray-300 rounded-lg text-sm">{{ old('diferenciais') }}</textarea>
                </div>
            </div>

            <div class="flex justify-between pt-4">
                <button type="button" @click="tab = 'redes'" class="px-4 py-2 border border-gray-300 text-gray-700 text-sm font-medium rounded-lg">← Voltar</button>
                <button type="button" @click="tab = 'google'" class="px-5 py-2 bg-green-600 hover:bg-green-700 text-white text-sm font-semibold rounded-lg">Avançar para Cofre Google →</button>
            </div>
        </div>

        {{-- TAB 4: COFRE SEGURO GOOGLE & ADS --}}
        <div x-show="tab === 'google'" class="bg-white rounded-b-xl rounded-t-none border border-t-0 border-gray-200 shadow-sm p-6 space-y-5" x-data="{ verSenhaGoogle: false }">
            <div class="flex items-center justify-between border-b pb-2">
                <h3 class="text-sm font-bold text-gray-800 uppercase tracking-wider">Cofre de Credenciais: Google & Google Ads</h3>
                <span class="text-xs bg-purple-100 text-purple-800 font-mono px-2 py-0.5 rounded font-semibold">🔒 Criptografia AES-256</span>
            </div>

            <div class="p-3 bg-blue-50 border border-blue-200 rounded-xl text-xs text-blue-800 leading-relaxed">
                Essas credenciais permitem que os robôs da LeadCerto criem campanhas de Google Ads e publiquem posts no Google Meu Negócio de forma automatizada. Todas as senhas e tokens são salvos criptografados.
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label class="block text-xs font-semibold text-gray-700 mb-1">E-mail da Conta Google da Empresa</label>
                    <input type="email" name="google_conta_email" value="{{ old('google_conta_email') }}" placeholder="empresa@gmail.com" class="w-full border-gray-300 rounded-lg text-sm font-mono">
                </div>

                <div>
                    <label class="block text-xs font-semibold text-gray-700 mb-1">Senha de Aplicativo Google (App Password)</label>
                    <div class="relative">
                        <input :type="verSenhaGoogle ? 'text' : 'password'" name="google_conta_senha" value="{{ old('google_conta_senha') }}" placeholder="xxxx xxxx xxxx xxxx" class="w-full border-gray-300 rounded-lg text-sm font-mono pr-10">
                        <button type="button" @click="verSenhaGoogle = !verSenhaGoogle" class="absolute right-2 top-2.5 text-gray-400 hover:text-gray-600 text-xs">
                            <span x-text="verSenhaGoogle ? 'Ocultar' : 'Ver'"></span>
                        </button>
                    </div>
                </div>
            </div>

            <div class="border-t border-gray-100 pt-3 space-y-4">
                <h4 class="text-xs font-bold text-gray-700 uppercase">Google Ads (API & Campanhas Automáticas)</h4>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-xs font-semibold text-gray-700 mb-1">Google Ads Customer ID (CID)</label>
                        <input type="text" name="google_ads_customer_id" value="{{ old('google_ads_customer_id') }}" placeholder="123-456-7890" class="w-full border-gray-300 rounded-lg text-sm font-mono">
                    </div>

                    <div>
                        <label class="block text-xs font-semibold text-gray-700 mb-1">Google Business Location ID (Local Post)</label>
                        <input type="text" name="google_business_location_id" value="{{ old('google_business_location_id') }}" placeholder="locations/123456789..." class="w-full border-gray-300 rounded-lg text-sm font-mono">
                    </div>

                    <div>
                        <label class="block text-xs font-semibold text-gray-700 mb-1">Developer Token (Google Ads)</label>
                        <input type="password" name="google_ads_developer_token" value="{{ old('google_ads_developer_token') }}" class="w-full border-gray-300 rounded-lg text-sm font-mono">
                    </div>

                    <div>
                        <label class="block text-xs font-semibold text-gray-700 mb-1">OAuth Refresh Token (Google Ads)</label>
                        <input type="password" name="google_ads_refresh_token" value="{{ old('google_ads_refresh_token') }}" class="w-full border-gray-300 rounded-lg text-sm font-mono">
                    </div>
                </div>
            </div>

            <div class="flex justify-between pt-4">
                <button type="button" @click="tab = 'ia'" class="px-4 py-2 border border-gray-300 text-gray-700 text-sm font-medium rounded-lg">← Voltar</button>
                <button type="button" @click="tab = 'meta'" class="px-5 py-2 bg-green-600 hover:bg-green-700 text-white text-sm font-semibold rounded-lg">Avançar para Meta Ads →</button>
            </div>
        </div>

        {{-- TAB 5: COFRE SEGURO META ADS --}}
        <div x-show="tab === 'meta'" class="bg-white rounded-b-xl rounded-t-none border border-t-0 border-gray-200 shadow-sm p-6 space-y-5">
            <div class="flex items-center justify-between border-b pb-2">
                <h3 class="text-sm font-bold text-gray-800 uppercase tracking-wider">Cofre de Credenciais: Meta Ads (Facebook & Instagram)</h3>
                <span class="text-xs bg-purple-100 text-purple-800 font-mono px-2 py-0.5 rounded font-semibold">🔒 Criptografia AES-256</span>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                <div>
                    <label class="block text-xs font-semibold text-gray-700 mb-1">Meta Business Manager ID</label>
                    <input type="text" name="meta_bm_id" value="{{ old('meta_bm_id') }}" placeholder="123456789012345" class="w-full border-gray-300 rounded-lg text-sm font-mono">
                </div>

                <div>
                    <label class="block text-xs font-semibold text-gray-700 mb-1">Conta de Anúncios ID (Ad Account)</label>
                    <input type="text" name="meta_ad_account_id" value="{{ old('meta_ad_account_id') }}" placeholder="act_1234567890" class="w-full border-gray-300 rounded-lg text-sm font-mono">
                </div>

                <div>
                    <label class="block text-xs font-semibold text-gray-700 mb-1">Meta Pixel ID</label>
                    <input type="text" name="meta_pixel_id" value="{{ old('meta_pixel_id') }}" placeholder="123456789012345" class="w-full border-gray-300 rounded-lg text-sm font-mono">
                </div>
            </div>

            <div>
                <label class="block text-xs font-semibold text-gray-700 mb-1">Token de Acesso do Usuário do Sistema (System User Token)</label>
                <textarea name="meta_access_token" rows="3" placeholder="EAA..." class="w-full border-gray-300 rounded-lg text-sm font-mono">{{ old('meta_access_token') }}</textarea>
                <p class="text-xs text-gray-400 mt-1">Usado para criação de anúncios e captura de leads de formulários instantâneos.</p>
            </div>

            <div class="flex justify-between pt-4">
                <button type="button" @click="tab = 'google'" class="px-4 py-2 border border-gray-300 text-gray-700 text-sm font-medium rounded-lg">← Voltar</button>
                <button type="button" @click="tab = 'dono'" class="px-5 py-2 bg-green-600 hover:bg-green-700 text-white text-sm font-semibold rounded-lg">Avançar para Usuário Dono →</button>
            </div>
        </div>

        {{-- TAB 6: USUÁRIO DONO --}}
        <div x-show="tab === 'dono'" class="bg-white rounded-b-xl rounded-t-none border border-t-0 border-gray-200 shadow-sm p-6 space-y-5">
            <h3 class="text-sm font-bold text-gray-800 uppercase tracking-wider border-b pb-2">Primeiro Acesso do Franqueado / Proprietário</h3>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label class="block text-xs font-semibold text-gray-700 mb-1">Nome do Proprietário / Responsável *</label>
                    <input type="text" name="dono_nome" required value="{{ old('dono_nome') }}" placeholder="Leonardo Leão" class="w-full border-gray-300 rounded-lg text-sm">
                </div>

                <div>
                    <label class="block text-xs font-semibold text-gray-700 mb-1">E-mail de Login do Dono *</label>
                    <input type="email" name="dono_email" required value="{{ old('dono_email') }}" placeholder="dono@empresa.com" class="w-full border-gray-300 rounded-lg text-sm">
                </div>
            </div>

            <div>
                <label class="block text-xs font-semibold text-gray-700 mb-1">Senha Inicial de Acesso *</label>
                <div class="flex gap-2">
                    <input type="text" name="dono_senha" id="dono_senha" required minlength="8" value="{{ old('dono_senha', $senhaSugerida) }}" class="flex-1 border-gray-300 rounded-lg text-sm font-mono">
                    <button type="button" onclick="document.getElementById('dono_senha').value = crypto.randomUUID().replace(/-/g, '').slice(0, 12)" class="px-4 py-2 bg-gray-100 hover:bg-gray-200 text-gray-700 text-xs font-semibold rounded-lg">
                        🎲 Gerar outra
                    </button>
                </div>
            </div>

            <div class="pt-6 border-t border-gray-100 flex items-center justify-between">
                <button type="button" @click="tab = 'meta'" class="px-4 py-2 border border-gray-300 text-gray-700 text-sm font-medium rounded-lg">← Voltar</button>

                <button type="submit" class="px-8 py-3 bg-green-600 hover:bg-green-700 text-white text-base font-bold rounded-xl shadow-lg transition">
                    🚀 Concluir Cadastro & Inicializar Empresa
                </button>
            </div>
        </div>

    </form>
</div>
@endsection
