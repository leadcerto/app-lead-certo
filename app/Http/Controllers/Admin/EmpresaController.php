<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Models\User;
use App\Services\TenantSetupService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * EmpresaController (Admin)
 * 
 * Gerencia o cadastro completo das empresas parceiras / franqueadas, incluindo:
 * - Dados Corporativos e Contato
 * - Redes Sociais e Perfis Digitais
 * - Contexto Estratégico de IA (para geração automática de anúncios e posts)
 * - Cofre de Credenciais Criptografadas (Google, Google Ads, Meta Ads, WhatsApp, IA)
 */
class EmpresaController extends Controller
{
    public function __construct(private TenantSetupService $setup) {}

    public function index(Request $request): View
    {
        $query = Tenant::with(['users', 'perfisGmb', 'canais'])->latest('created_at');

        if ($request->filled('busca')) {
            $b = $request->busca;
            $query->where(function ($q) use ($b) {
                $q->where('nome', 'like', "%{$b}%")
                  ->orWhere('razao_social', 'like', "%{$b}%")
                  ->orWhere('email', 'like', "%{$b}%")
                  ->orWhere('cnpj', 'like', "%{$b}%")
                  ->orWhere('cidade', 'like', "%{$b}%");
            });
        }

        if ($request->filled('nicho')) {
            $query->where('nicho', $request->nicho);
        }

        $empresas = $query->paginate(15)->withQueryString();

        return view('admin.empresas.index', compact('empresas'));
    }

    public function create(): View
    {
        return view('admin.empresas.create', [
            'senhaSugerida' => Str::password(12, symbols: false),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            // Dados Básicos
            'nome'                         => 'required|string|max:200',
            'razao_social'                 => 'nullable|string|max:255',
            'cnpj'                         => 'nullable|string|max:30',
            'email'                        => ['required', 'email', 'max:200', Rule::unique('tenants', 'email')],
            'telefone'                     => 'nullable|string|max:30',
            'nicho'                        => 'required|string|max:100',
            'cidade'                       => 'nullable|string|max:100',
            'estado'                       => 'nullable|string|max:10',
            'cep'                          => 'nullable|string|max:20',
            'endereco'                     => 'nullable|string|max:255',
            'site_url'                     => 'nullable|url|max:500',

            // Contexto de IA / Negócio
            'descricao_negocio'            => 'nullable|string',
            'publico_alvo'                 => 'nullable|string',
            'diferenciais'                 => 'nullable|string',

            // Redes Sociais
            'instagram_url'                => 'nullable|url|max:500',
            'facebook_url'                 => 'nullable|url|max:500',
            'youtube_url'                  => 'nullable|url|max:500',
            'linkedin_url'                 => 'nullable|url|max:500',
            'gmb_url'                      => 'nullable|url|max:500',

            // Cofre: Google & Google Ads
            'google_conta_email'           => 'nullable|email|max:255',
            'google_conta_senha'           => 'nullable|string|max:255',
            'google_ads_customer_id'       => 'nullable|string|max:100',
            'google_ads_developer_token'   => 'nullable|string',
            'google_ads_client_id'         => 'nullable|string',
            'google_ads_client_secret'     => 'nullable|string',
            'google_ads_refresh_token'     => 'nullable|string',
            'google_business_location_id'  => 'nullable|string|max:150',

            // Cofre: Meta Ads
            'meta_bm_id'                   => 'nullable|string|max:100',
            'meta_ad_account_id'           => 'nullable|string|max:100',
            'meta_pixel_id'                => 'nullable|string|max:100',
            'meta_access_token'            => 'nullable|string',

            // Usuário Dono (Acesso Inicial)
            'dono_nome'                    => 'required|string|max:200',
            'dono_email'                   => ['required', 'email', 'max:200', Rule::unique('users', 'email')],
            'dono_senha'                   => 'required|string|min:8|max:100',
        ]);

        $tenantData = collect($validated)->except(['dono_nome', 'dono_email', 'dono_senha'])->toArray();
        $tenantData['status'] = 'ativo';

        $tenant = Tenant::create($tenantData);

        // Cria o Perfil GMB correspondente caso tenha preenchido o link do Google Maps
        if (!empty($validated['gmb_url'])) {
            $tenant->perfisGmb()->create([
                'nome'                => $tenant->nome,
                'city'                => $validated['cidade'] ?? 'Rio de Janeiro',
                'state'               => $validated['estado'] ?? 'RJ',
                'link_gmb'            => $validated['gmb_url'],
                'google_location_id'  => $validated['google_business_location_id'] ?? null,
                'ativo'               => true,
            ]);
        }

        // Cria o usuário Dono
        User::create([
            'tenant_id' => $tenant->id,
            'nome'      => $validated['dono_nome'],
            'email'     => $validated['dono_email'],
            'password'  => Hash::make($validated['dono_senha']),
            'perfil'    => 'dono',
            'ativo'     => true,
        ]);

        // Executa o setup padrão (Kanban, Funil, Personas, Motivos)
        $this->setup->configurar($tenant);

        return redirect()->route('admin.empresas.show', $tenant)
            ->with('sucesso', "Empresa \"{$tenant->nome}\" cadastrada com sucesso! Login do Dono: {$validated['dono_email']}");
    }

    public function show(Tenant $empresa): View
    {
        $empresa->load(['users', 'perfisGmb', 'canais', 'gmbPosts']);
        return view('admin.empresas.show', compact('empresa'));
    }

    public function edit(Tenant $empresa): View
    {
        return view('admin.empresas.edit', compact('empresa'));
    }

    public function update(Request $request, Tenant $empresa): RedirectResponse
    {
        $validated = $request->validate([
            'nome'                         => 'required|string|max:200',
            'razao_social'                 => 'nullable|string|max:255',
            'cnpj'                         => 'nullable|string|max:30',
            'email'                        => ['required', 'email', 'max:200', Rule::unique('tenants', 'email')->ignore($empresa->id)],
            'telefone'                     => 'nullable|string|max:30',
            'nicho'                        => 'required|string|max:100',
            'status'                       => 'required|in:ativo,inativo,suspenso',
            'cidade'                       => 'nullable|string|max:100',
            'estado'                       => 'nullable|string|max:10',
            'cep'                          => 'nullable|string|max:20',
            'endereco'                     => 'nullable|string|max:255',
            'site_url'                     => 'nullable|url|max:500',

            // Contexto de IA / Negócio
            'descricao_negocio'            => 'nullable|string',
            'publico_alvo'                 => 'nullable|string',
            'diferenciais'                 => 'nullable|string',

            // Redes Sociais
            'instagram_url'                => 'nullable|url|max:500',
            'facebook_url'                 => 'nullable|url|max:500',
            'youtube_url'                  => 'nullable|url|max:500',
            'linkedin_url'                 => 'nullable|url|max:500',
            'gmb_url'                      => 'nullable|url|max:500',

            // Cofre: Google & Google Ads
            'google_conta_email'           => 'nullable|email|max:255',
            'google_conta_senha'           => 'nullable|string|max:255',
            'google_ads_customer_id'       => 'nullable|string|max:100',
            'google_ads_developer_token'   => 'nullable|string',
            'google_ads_client_id'         => 'nullable|string',
            'google_ads_client_secret'     => 'nullable|string',
            'google_ads_refresh_token'     => 'nullable|string',
            'google_business_location_id'  => 'nullable|string|max:150',

            // Cofre: Meta Ads
            'meta_bm_id'                   => 'nullable|string|max:100',
            'meta_ad_account_id'           => 'nullable|string|max:100',
            'meta_pixel_id'                => 'nullable|string|max:100',
            'meta_access_token'            => 'nullable|string',
        ]);

        // Evita sobrescrever senhas/tokens por vazio se o usuário não digitou novo valor
        $camposSensiveis = [
            'google_conta_senha',
            'google_ads_developer_token',
            'google_ads_client_id',
            'google_ads_client_secret',
            'google_ads_refresh_token',
            'meta_access_token',
        ];

        foreach ($camposSensiveis as $campo) {
            if (empty($validated[$campo])) {
                unset($validated[$campo]);
            }
        }

        $empresa->update($validated);

        return redirect()->route('admin.empresas.show', $empresa)
            ->with('sucesso', "Configurações da empresa \"{$empresa->nome}\" atualizadas com sucesso!");
    }
}
