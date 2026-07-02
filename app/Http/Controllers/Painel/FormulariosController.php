<?php

namespace App\Http\Controllers\Painel;

use App\Http\Controllers\Controller;
use App\Models\Formulario;
use App\Models\FormularioCampo;
use App\Models\FormularioDominio;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class FormulariosController extends Controller
{
    public function view(): View
    {
        return view('formularios.index');
    }

    public function index(Request $request): JsonResponse
    {
        $formularios = Formulario::where('tenant_id', $request->user()->tenant_id)
            ->with('dominios', 'campos')
            ->orderByDesc('created_at')
            ->get()
            ->map(fn ($f) => $this->formatarFormulario($f));

        return response()->json($formularios);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'nome'            => 'required|string|max:100',
            'acao_pos_envio'  => 'required|in:bot_sdr,mensagem_unica',
            'mensagem_custom' => 'nullable|string',
            'double_optin'    => 'boolean',
            'dominios'        => 'array',
            'dominios.*'      => 'string|max:255',
            'campos'          => 'array',
            'campos.*.chave'  => 'required|string|max:50',
            'campos.*.rotulo' => 'required|string|max:100',
            'campos.*.tipo'   => 'required|in:texto,email,telefone,numero,selecao,area_texto',
            'campos.*.opcoes' => 'nullable|array',
            'campos.*.obrigatorio' => 'boolean',
        ]);

        $formulario = Formulario::create([
            'tenant_id'       => $request->user()->tenant_id,
            'nome'            => $validated['nome'],
            'acao_pos_envio'  => $validated['acao_pos_envio'],
            'mensagem_custom' => $validated['mensagem_custom'] ?? null,
            'double_optin'    => $validated['double_optin'] ?? false,
        ]);

        $this->sincronizarDominios($formulario, $validated['dominios'] ?? []);
        $this->sincronizarCampos($formulario, $validated['campos'] ?? []);

        return response()->json($this->formatarFormulario($formulario->load('dominios', 'campos')), 201);
    }

    public function update(Request $request, Formulario $formulario): JsonResponse
    {
        $this->autorizarTenant($formulario, $request);

        $validated = $request->validate([
            'nome'            => 'sometimes|string|max:100',
            'acao_pos_envio'  => 'sometimes|in:bot_sdr,mensagem_unica',
            'mensagem_custom' => 'nullable|string',
            'double_optin'    => 'boolean',
            'ativo'           => 'boolean',
            'dominios'        => 'array',
            'dominios.*'      => 'string|max:255',
            'campos'          => 'array',
            'campos.*.chave'  => 'required|string|max:50',
            'campos.*.rotulo' => 'required|string|max:100',
            'campos.*.tipo'   => 'required|in:texto,email,telefone,numero,selecao,area_texto',
            'campos.*.opcoes' => 'nullable|array',
            'campos.*.obrigatorio' => 'boolean',
        ]);

        $formulario->update($validated);

        if (isset($validated['dominios'])) {
            $this->sincronizarDominios($formulario, $validated['dominios']);
        }

        if (isset($validated['campos'])) {
            $this->sincronizarCampos($formulario, $validated['campos']);
        }

        return response()->json($this->formatarFormulario($formulario->load('dominios', 'campos')));
    }

    public function destroy(Request $request, Formulario $formulario): JsonResponse
    {
        $this->autorizarTenant($formulario, $request);
        $formulario->delete();
        return response()->json(['ok' => true]);
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────

    private function autorizarTenant(Formulario $formulario, Request $request): void
    {
        abort_if($formulario->tenant_id !== $request->user()->tenant_id, 403);
    }

    private function sincronizarDominios(Formulario $formulario, array $dominios): void
    {
        $formulario->dominios()->delete();
        foreach ($dominios as $dominio) {
            $dominio = strtolower(trim(preg_replace('#^https?://#', '', $dominio), '/'));
            if ($dominio) {
                $formulario->dominios()->create(['dominio' => $dominio]);
            }
        }
    }

    private function sincronizarCampos(Formulario $formulario, array $campos): void
    {
        $formulario->campos()->delete();
        foreach ($campos as $i => $campo) {
            $formulario->campos()->create([
                'chave'       => Str::slug($campo['chave'], '_'),
                'rotulo'      => $campo['rotulo'],
                'tipo'        => $campo['tipo'],
                'opcoes'      => $campo['opcoes'] ?? null,
                'obrigatorio' => $campo['obrigatorio'] ?? false,
                'ordem'       => $i,
            ]);
        }
    }

    private function formatarFormulario(Formulario $f): array
    {
        $appUrl = config('app.url', 'https://app.leadcerto.app.br');

        return [
            'id'              => $f->id,
            'uuid'            => $f->uuid,
            'nome'            => $f->nome,
            'acao_pos_envio'  => $f->acao_pos_envio,
            'mensagem_custom' => $f->mensagem_custom,
            'double_optin'    => $f->double_optin,
            'ativo'           => $f->ativo,
            'dominios'        => $f->dominios->pluck('dominio'),
            'campos'          => $f->campos->map(fn ($c) => [
                'chave'       => $c->chave,
                'rotulo'      => $c->rotulo,
                'tipo'        => $c->tipo,
                'opcoes'      => $c->opcoes,
                'obrigatorio' => $c->obrigatorio,
            ]),
            'embed_code'      => "<script src=\"{$appUrl}/js/lc-form.js\" data-form-id=\"{$f->uuid}\" async></script>",
            'form_url'        => "{$appUrl}/f/{$f->uuid}",
        ];
    }
}
