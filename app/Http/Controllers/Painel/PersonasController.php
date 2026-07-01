<?php

namespace App\Http\Controllers\Painel;

use App\Http\Controllers\Controller;
use App\Models\QaAuditoria;
use App\Models\SdrPersona;
use App\Models\RegraRoteamento;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PersonasController extends Controller
{
    public function view(): View
    {
        return view('personas.index');
    }

    // ── Lista personas do tenant ──────────────────────────────────────────────

    public function index(): JsonResponse
    {
        $tenantId = auth()->user()->tenant_id;

        $personas = SdrPersona::with('regras')
            ->where('tenant_id', $tenantId)
            ->orderBy('ativo', 'desc')
            ->orderBy('nome_display')
            ->get()
            ->map(fn ($p) => [
                'id'              => $p->id,
                'nome_interno'    => $p->nome_interno,
                'nome_display'    => $p->nome_display,
                'genero'          => $p->genero,
                'idade_aparente'  => $p->idade_aparente,
                'localidade'      => $p->localidade,
                'tom_de_voz'      => $p->tom_de_voz,
                'ativo'           => $p->ativo,
                'is_default'      => $p->is_default,
                'tags'            => $p->regras->pluck('tag')->values(),
                'system_prompt'   => $p->system_prompt,
            ]);

        return response()->json(['data' => $personas]);
    }

    // ── Criar persona ─────────────────────────────────────────────────────────

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'nome_interno'   => 'required|string|max:100',
            'nome_display'   => 'required|string|max:150',
            'genero'         => 'required|in:masculino,feminino,neutro',
            'idade_aparente' => 'nullable|integer|min:18|max:80',
            'localidade'     => 'nullable|string|max:150',
            'tom_de_voz'     => 'required|in:suave,formal,jovial,direto,tecnico',
            'system_prompt'  => 'required|string|min:50',
            'is_default'     => 'boolean',
            'tags'           => 'nullable|array',
            'tags.*'         => 'string|max:100',
        ]);

        $tenantId = auth()->user()->tenant_id;

        // Apenas uma persona pode ser default por tenant
        if (! empty($data['is_default'])) {
            SdrPersona::where('tenant_id', $tenantId)->update(['is_default' => false]);
        }

        $persona = SdrPersona::create([
            'tenant_id'      => $tenantId,
            'nome_interno'   => $data['nome_interno'],
            'nome_display'   => $data['nome_display'],
            'genero'         => $data['genero'],
            'idade_aparente' => $data['idade_aparente'] ?? null,
            'localidade'     => $data['localidade'] ?? null,
            'tom_de_voz'     => $data['tom_de_voz'],
            'system_prompt'  => $data['system_prompt'],
            'is_default'     => $data['is_default'] ?? false,
            'ativo'          => true,
        ]);

        if (! empty($data['tags'])) {
            foreach ($data['tags'] as $tag) {
                RegraRoteamento::create(['sdr_persona_id' => $persona->id, 'tag' => $tag]);
            }
        }

        return response()->json(['ok' => true, 'id' => $persona->id], 201);
    }

    // ── Atualizar persona ─────────────────────────────────────────────────────

    public function update(Request $request, SdrPersona $persona): JsonResponse
    {
        $this->autorizarTenant($persona);

        $data = $request->validate([
            'nome_interno'   => 'sometimes|string|max:100',
            'nome_display'   => 'sometimes|string|max:150',
            'genero'         => 'sometimes|in:masculino,feminino,neutro',
            'idade_aparente' => 'nullable|integer|min:18|max:80',
            'localidade'     => 'nullable|string|max:150',
            'tom_de_voz'     => 'sometimes|in:suave,formal,jovial,direto,tecnico',
            'system_prompt'  => 'sometimes|string|min:50',
            'ativo'          => 'boolean',
            'is_default'     => 'boolean',
            'tags'           => 'nullable|array',
            'tags.*'         => 'string|max:100',
        ]);

        if (! empty($data['is_default'])) {
            SdrPersona::where('tenant_id', $persona->tenant_id)->update(['is_default' => false]);
        }

        $persona->update($data);

        if (array_key_exists('tags', $data)) {
            $persona->regras()->delete();
            foreach (($data['tags'] ?? []) as $tag) {
                RegraRoteamento::create(['sdr_persona_id' => $persona->id, 'tag' => $tag]);
            }
        }

        return response()->json(['ok' => true]);
    }

    // ── QA: Auditorias pendentes de revisão humana ────────────────────────────

    public function qasPendentes(): JsonResponse
    {
        $tenantId = auth()->user()->tenant_id;

        $auditorias = QaAuditoria::with(['persona', 'ticket'])
            ->whereHas('persona', fn ($q) => $q->where('tenant_id', $tenantId))
            ->where('requer_revisao_humana', true)
            ->where('status', 'aguardando')
            ->orderBy('criado_em', 'desc')
            ->paginate(50);

        return response()->json([
            'data'  => $auditorias->map(fn ($a) => [
                'id'                    => $a->id,
                'persona'               => $a->persona?->nome_display,
                'ticket_id'             => $a->ticket_id,
                'confidence_score'      => $a->confidence_score,
                'sugestoes_melhoria'    => $a->sugestoes_melhoria,
                'criado_em'             => $a->criado_em?->format('d/m/Y H:i'),
            ]),
            'total' => $auditorias->total(),
        ]);
    }

    // ── QA: Auditor humano aprova/rejeita avaliação ───────────────────────────

    public function qaRevisar(Request $request, QaAuditoria $auditoria): JsonResponse
    {
        $this->autorizarTenant($auditoria->persona ?? throw abort(404));

        $request->validate(['acao' => 'required|in:aprovar,rejeitar']);

        $auditoria->update([
            'status'        => $request->acao === 'aprovar' ? 'aprovado' : 'rejeitado',
            'revisado_por'  => auth()->id(),
            'revisado_em'   => now(),
            'requer_revisao_humana' => false,
        ]);

        return response()->json(['ok' => true]);
    }

    // ── Helper ────────────────────────────────────────────────────────────────

    private function autorizarTenant(SdrPersona $persona): void
    {
        if ($persona->tenant_id !== auth()->user()->tenant_id) {
            abort(403);
        }
    }
}
