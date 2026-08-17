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
 * Cadastro de empresa (franqueado). Mesma sequência do comando de terminal
 * `tenant:criar`, só que pela web: cria o Tenant, cria o usuário dono, e
 * aplica a configuração padrão Lead Certo via TenantSetupService (Kanban +
 * colunas + Persona + Motivos de Desfecho) — idempotente, mesmo serviço
 * usado no seeder.
 *
 * Só o perfil admin (equipe Lead Certo) acessa — não é autosserviço.
 */
class EmpresaController extends Controller
{
    public function __construct(private TenantSetupService $setup) {}

    public function index(): View
    {
        $empresas = Tenant::orderByDesc('created_at')->paginate(20);

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
            'nome'          => 'required|string|max:200',
            'email'         => ['required', 'email', 'max:200', Rule::unique('tenants', 'email')],
            'telefone'      => 'nullable|string|max:30',
            'nicho'         => 'nullable|string|max:100',
            'dono_nome'     => 'required|string|max:200',
            'dono_email'    => ['required', 'email', 'max:200', Rule::unique('users', 'email')],
            'dono_senha'    => 'required|string|min:8|max:100',
        ]);

        $tenant = Tenant::create([
            'nome'     => $validated['nome'],
            'email'    => $validated['email'],
            'telefone' => $validated['telefone'] ?? null,
            'nicho'    => $validated['nicho'] ?: 'frete',
            'status'   => 'ativo',
        ]);

        User::create([
            'tenant_id' => $tenant->id,
            'nome'      => $validated['dono_nome'],
            'email'     => $validated['dono_email'],
            'password'  => Hash::make($validated['dono_senha']),
            'perfil'    => 'dono',
            'ativo'     => true,
        ]);

        $this->setup->configurar($tenant);

        return redirect()->route('admin.empresas.index')
            ->with('sucesso', "Empresa \"{$tenant->nome}\" criada! Login do dono: {$validated['dono_email']} — senha: {$validated['dono_senha']} (só aparece aqui, não fica salva em lugar nenhum além do hash).");
    }
}
