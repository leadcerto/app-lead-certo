<?php

namespace App\Http\Controllers\Painel;

use App\Http\Controllers\Controller;
use App\Models\AgenteMinerador;
use App\Models\CampanhaMineracao;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CampanhasController extends Controller
{
    public function view(): View
    {
        return view('campanhas.index');
    }

    // ── Campanhas ─────────────────────────────────────────────────────────────

    public function index(): JsonResponse
    {
        $tenantId = auth()->user()->tenant_id;

        $campanhas = CampanhaMineracao::withCount(['agentes'])
            ->where('tenant_id', $tenantId)
            ->orderByDesc('updated_at')
            ->get()
            ->map(fn ($c) => [
                'id'                  => $c->id,
                'nome'                => $c->nome,
                'nicho'               => $c->nicho,
                'regiao_alvo'         => $c->regiao_alvo,
                'status'              => $c->status,
                'contatos_importados' => $c->contatos_importados,
                'meta_contatos'       => $c->meta_contatos,
                'progresso'           => $c->progressoPercent(),
                'agentes_count'       => $c->agentes_count,
                'data_inicio'         => $c->data_inicio?->format('d/m/Y'),
                'data_fim'            => $c->data_fim?->format('d/m/Y'),
                'criado_em'           => $c->created_at->format('d/m/Y'),
            ]);

        return response()->json(['data' => $campanhas]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'nome'           => 'required|string|max:200',
            'descricao'      => 'nullable|string',
            'nicho'          => 'nullable|string|max:300',
            'regiao_alvo'    => 'nullable|string|max:300',
            'palavras_chave' => 'nullable|string',
            'data_inicio'    => 'nullable|date',
            'data_fim'       => 'nullable|date|after_or_equal:data_inicio',
            'meta_contatos'  => 'nullable|integer|min:1',
        ]);

        $campanha = CampanhaMineracao::create([
            ...$data,
            'tenant_id'  => auth()->user()->tenant_id,
            'criado_por' => auth()->id(),
            'status'     => 'rascunho',
        ]);

        return response()->json(['ok' => true, 'id' => $campanha->id], 201);
    }

    public function update(Request $request, CampanhaMineracao $campanha): JsonResponse
    {
        $this->autorizarTenant($campanha);

        $data = $request->validate([
            'nome'           => 'sometimes|string|max:200',
            'descricao'      => 'nullable|string',
            'nicho'          => 'nullable|string|max:300',
            'regiao_alvo'    => 'nullable|string|max:300',
            'palavras_chave' => 'nullable|string',
            'status'         => 'sometimes|in:rascunho,ativa,pausada,concluida',
            'data_inicio'    => 'nullable|date',
            'data_fim'       => 'nullable|date',
            'meta_contatos'  => 'nullable|integer|min:1',
        ]);

        $campanha->update($data);

        return response()->json(['ok' => true]);
    }

    // ── Agentes Mineradores ───────────────────────────────────────────────────

    public function agentes(CampanhaMineracao $campanha): JsonResponse
    {
        $this->autorizarTenant($campanha);

        $agentes = AgenteMinerador::where('campanha_id', $campanha->id)
            ->get()
            ->map(fn ($a) => [
                'id'                  => $a->id,
                'nome'                => $a->nome,
                'tipo'                => $a->tipo,
                'status'              => $a->status,
                'api_key_prefix'      => $a->api_key_prefix,
                'contatos_importados' => $a->contatos_importados,
                'ultima_execucao_em'  => $a->ultima_execucao_em?->format('d/m/Y H:i'),
            ]);

        return response()->json(['data' => $agentes]);
    }

    public function criarAgente(Request $request, CampanhaMineracao $campanha): JsonResponse
    {
        $this->autorizarTenant($campanha);

        $data = $request->validate([
            'nome' => 'required|string|max:200',
            'tipo' => 'required|in:instagram,facebook,google,email,whatsapp,linkedin,tiktok,custom',
            'configuracoes' => 'nullable|array',
        ]);

        $chave = AgenteMinerador::gerarApiKey();

        AgenteMinerador::create([
            'tenant_id'      => $campanha->tenant_id,
            'campanha_id'    => $campanha->id,
            'nome'           => $data['nome'],
            'tipo'           => $data['tipo'],
            'api_key_prefix' => $chave['prefix'],
            'api_key_hash'   => $chave['hash'],
            'escopo'         => ['gravar_contatos', 'ler_campanha'],
            'configuracoes'  => $data['configuracoes'] ?? null,
            'status'         => 'ativo',
        ]);

        return response()->json([
            'ok'      => true,
            'api_key' => $chave['raw'],
            'aviso'   => 'Esta chave é exibida apenas UMA VEZ. Copie e guarde agora.',
        ], 201);
    }

    public function ativarAgente(AgenteMinerador $agente): JsonResponse
    {
        $this->autorizarTenantAgente($agente);
        $agente->update(['status' => 'ativo']);
        return response()->json(['ok' => true]);
    }

    public function suspenderAgente(AgenteMinerador $agente): JsonResponse
    {
        $this->autorizarTenantAgente($agente);
        $agente->update(['status' => 'suspenso']);
        return response()->json(['ok' => true]);
    }

    public function regenerarChave(AgenteMinerador $agente): JsonResponse
    {
        $this->autorizarTenantAgente($agente);

        $chave = AgenteMinerador::gerarApiKey();
        $agente->update([
            'api_key_prefix' => $chave['prefix'],
            'api_key_hash'   => $chave['hash'],
        ]);

        return response()->json([
            'ok'      => true,
            'api_key' => $chave['raw'],
            'aviso'   => 'Chave anterior invalidada. Esta é exibida apenas UMA VEZ.',
        ]);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function autorizarTenant(CampanhaMineracao $campanha): void
    {
        if ($campanha->tenant_id !== auth()->user()->tenant_id) abort(403);
    }

    private function autorizarTenantAgente(AgenteMinerador $agente): void
    {
        if ($agente->tenant_id !== auth()->user()->tenant_id) abort(403);
    }
}
