<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\GmbPost;
use App\Models\IaUsage;
use App\Models\PerfilGmb;
use App\Models\SdrPersona;
use App\Models\Tenant;
use App\Models\TicketAtendimento;
use App\Models\User;
use App\Models\WhatsappCanal;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\View\View;

class AdminDashboardController extends Controller
{
    public function index(Request $request): View
    {
        // ── KPIs Globais do Grupo ───────────────────────────────────────────
        $totalEmpresas = Tenant::count();
        $empresasAtivas = Tenant::where('status', 'ativo')->count();

        $hoje = now()->startOfDay();
        $inicioMes = now()->startOfMonth();

        $leadsHoje = TicketAtendimento::withoutGlobalScopes()->where('created_at', '>=', $hoje)->count();
        $leadsMes = TicketAtendimento::withoutGlobalScopes()->where('created_at', '>=', $inicioMes)->count();
        $fechadosMes = TicketAtendimento::withoutGlobalScopes()->where('status', 'fechado')->where('updated_at', '>=', $inicioMes)->count();
        $taxaConversao = $leadsMes > 0 ? round(($fechadosMes / $leadsMes) * 100, 1) : 0;

        $totalAgentesIa = SdrPersona::withoutGlobalScopes()->where('ativo', true)->count();
        $totalAgentesHumanos = User::withoutGlobalScopes()->where('ativo', true)->count();

        $whatsappConectados = WhatsappCanal::withoutGlobalScopes()->where('status', 'conectado')->count();
        $whatsappTotal = WhatsappCanal::withoutGlobalScopes()->count();

        $postsAgendadosGmb = GmbPost::withoutGlobalScopes()->where('status', 'agendado')->count();
        $postsPublicadosGmb = GmbPost::withoutGlobalScopes()->where('status', 'publicado')->count();

        $tokensUsadosMes = IaUsage::withoutGlobalScopes()
            ->where('created_at', '>=', $inicioMes)
            ->selectRaw('COALESCE(SUM(tokens_input + tokens_output), 0) as total')
            ->value('total') ?? 0;

        $kpis = [
            'total_empresas'        => $totalEmpresas,
            'empresas_ativas'       => $empresasAtivas,
            'leads_hoje'            => $leadsHoje,
            'leads_mes'             => $leadsMes,
            'fechados_mes'          => $fechadosMes,
            'taxa_conversao'        => $taxaConversao,
            'total_agentes_ia'      => $totalAgentesIa,
            'total_agentes_humanos' => $totalAgentesHumanos,
            'whatsapp_conectados'   => $whatsappConectados,
            'whatsapp_total'        => $whatsappTotal,
            'posts_agendados_gmb'   => $postsAgendadosGmb,
            'posts_publicados_gmb'  => $postsPublicadosGmb,
            'tokens_usados_mes'     => number_format($tokensUsadosMes, 0, ',', '.'),
        ];

        // ── Listagens Operacionais ───────────────────────────────────────────
        $empresas = Tenant::with(['users', 'canais', 'perfisGmb'])->latest('created_at')->get();
        $agentesIa = SdrPersona::withoutGlobalScopes()->with('tenant')->latest('updated_at')->get();
        $agentesHumanos = User::withoutGlobalScopes()->with('tenant')->latest('created_at')->paginate(20, ['*'], 'humanos_page');
        $canaisWhatsapp = WhatsappCanal::withoutGlobalScopes()->with('tenant')->get();

        return view('admin.dashboard.index', compact(
            'kpis',
            'empresas',
            'agentesIa',
            'agentesHumanos',
            'canaisWhatsapp'
        ));
    }

    /**
     * Cadastro Rápido de Agente Humano a partir do Dashboard Admin.
     */
    public function storeAgenteHumano(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'tenant_id' => 'required|exists:tenants,id',
            'nome'      => 'required|string|max:200',
            'email'     => 'required|email|max:200|unique:users,email',
            'perfil'    => 'required|in:vendedor,gerente,gestor,diretor,dono,avaliador,growth_manager,pos_venda',
            'whatsapp'  => 'nullable|string|max:30',
            'city'      => 'nullable|string|max:100',
            'state'     => 'nullable|string|max:10',
            'password'  => 'required|string|min:8|max:100',
        ]);

        User::create([
            'tenant_id' => $validated['tenant_id'],
            'nome'      => $validated['nome'],
            'email'     => $validated['email'],
            'perfil'    => $validated['perfil'],
            'whatsapp'  => $validated['whatsapp'] ?? null,
            'city'      => $validated['city'] ?? null,
            'state'     => $validated['state'] ?? null,
            'password'  => Hash::make($validated['password']),
            'ativo'     => true,
        ]);

        return redirect()->route('admin.dashboard')
            ->with('sucesso', "Agente Humano \"{$validated['nome']}\" cadastrado com sucesso!");
    }

    /**
     * Criação Rápida de Agente de IA (SDR Persona) a partir do Dashboard Admin.
     */
    public function storeAgenteIa(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'tenant_id'       => 'required|exists:tenants,id',
            'nome_interno'    => 'required|string|max:100',
            'nome_display'    => 'required|string|max:100',
            'genero'          => 'required|in:M,F,N',
            'idade_aparente'  => 'nullable|integer|min:18|max:80',
            'localidade'      => 'nullable|string|max:100',
            'tom_de_voz'      => 'required|string|max:150',
            'system_prompt'   => 'required|string',
            'tier'            => 'nullable|string|max:50',
        ]);

        SdrPersona::create([
            'tenant_id'      => $validated['tenant_id'],
            'nome_interno'   => $validated['nome_interno'],
            'nome_display'   => $validated['nome_display'],
            'genero'         => $validated['genero'],
            'idade_aparente' => $validated['idade_aparente'] ?? 28,
            'localidade'     => $validated['localidade'] ?? 'Rio de Janeiro/RJ',
            'tom_de_voz'     => $validated['tom_de_voz'],
            'system_prompt'  => $validated['system_prompt'],
            'tier'           => $validated['tier'] ?? 'complexo',
            'ativo'          => true,
            'is_default'     => false,
        ]);

        return redirect()->route('admin.dashboard')
            ->with('sucesso', "Agente de IA \"{$validated['nome_display']}\" criado e ativado com sucesso!");
    }
}
