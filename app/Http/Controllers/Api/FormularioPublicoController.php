<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Formulario;
use App\Services\FormularioService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class FormularioPublicoController extends Controller
{
    public function __construct(private FormularioService $service) {}

    /**
     * Retorna configuração de campos — usado pelo widget para renderizar o form.
     * Rota: GET /api/formulario/{uuid}/campos
     */
    public function campos(string $uuid): JsonResponse
    {
        $formulario = Formulario::with('campos')
            ->where('uuid', $uuid)
            ->where('ativo', true)
            ->first();

        if (! $formulario) {
            return response()->json(['erro' => 'Formulário não encontrado'], 404);
        }

        return response()->json([
            'nome'   => $formulario->nome,
            'campos' => $formulario->campos->map(fn ($c) => [
                'chave'       => $c->chave,
                'rotulo'      => $c->rotulo,
                'tipo'        => $c->tipo,
                'opcoes'      => $c->opcoes,
                'obrigatorio' => $c->obrigatorio,
            ]),
        ]);
    }

    /**
     * Recebe submissão do formulário.
     * Rota: POST /api/formulario/{uuid}/submit
     *
     * REGRA ARQUITETO: Sem auth token no payload. UUID público identifica o formulário.
     * REGRA ARQUITETO: Origin/Referer verificado contra whitelist de domínios.
     * REGRA ARQUITETO: Retorna 200 imediatamente. WhatsApp disparado via Job assíncrono.
     */
    public function submit(Request $request, string $uuid): JsonResponse
    {
        $formulario = Formulario::with(['dominios', 'campos', 'tenant'])
            ->where('uuid', $uuid)
            ->where('ativo', true)
            ->first();

        if (! $formulario) {
            return response()->json(['erro' => 'Formulário não encontrado'], 404);
        }

        // REGRA ARQUITETO: Verifica domínio antes de qualquer outra coisa
        $origin  = $request->header('Origin');
        $referer = $request->header('Referer');

        $dominioOrigem = parse_url($origin ?? $referer ?? '', PHP_URL_HOST) ?? 'direto';

        // Requisições vindas do nosso próprio servidor (iframe) são sempre permitidas
        $appHost    = parse_url(config('app.url', ''), PHP_URL_HOST) ?? '';
        $deNossoApp = $dominioOrigem === $appHost;

        // Se o formulário tem whitelist configurada, valida rigorosamente
        if ($formulario->dominios->isNotEmpty() && ! $deNossoApp) {
            // Sem Origin nem Referer = requisição direta (bot/curl) — bloqueia
            if (! $origin && ! $referer) {
                return response()->json(['erro' => 'Domínio não autorizado'], 403);
            }
            if (! $this->service->dominioAutorizado($formulario, $origin, $referer)) {
                return response()->json(['erro' => 'Domínio não autorizado'], 403);
            }
        }

        $dados = $request->all();

        // REGRA ARQUITETO: Validação obrigatória no backend
        $erros = $this->service->validarCampos($formulario, $dados);
        if (! empty($erros)) {
            return response()->json(['erro' => 'Dados inválidos', 'campos' => $erros], 422);
        }

        // Campos mínimos obrigatórios
        if (empty(trim($dados['telefone'] ?? ''))) {
            return response()->json(['erro' => 'Telefone é obrigatório'], 422);
        }

        // REGRA ARQUITETO: Salva e retorna 200 ANTES de disparar WhatsApp
        $resultado = $this->service->processar($formulario, $dados, $dominioOrigem);

        return response()->json(['ok' => true, 'mensagem' => 'Recebemos seu cadastro!']);
    }
}
