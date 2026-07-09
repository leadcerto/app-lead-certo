<?php

namespace App\Http\Controllers\Painel;

use App\Http\Controllers\Controller;
use App\Models\Mensagem;
use App\Services\OpenRouterService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Smalot\PdfParser\Parser as PdfParser;

class ContextoIaController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $tenant = $request->user()->tenant;

        return response()->json([
            'ia_contexto'           => $tenant->ia_contexto ?? '',
            'tabela_precos_nome'    => $tenant->tabela_precos_pdf_path
                ? basename($tenant->tabela_precos_pdf_path)
                : null,
            'tabela_precos_chars'   => $tenant->tabela_precos_texto
                ? strlen($tenant->tabela_precos_texto)
                : 0,
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'ia_contexto' => 'nullable|string|max:50000',
        ]);

        $request->user()->tenant->update([
            'ia_contexto' => $validated['ia_contexto'] ?? '',
        ]);

        return response()->json(['ok' => true]);
    }

    public function uploadTabela(Request $request): JsonResponse
    {
        $request->validate([
            'pdf' => 'required|file|mimes:pdf|max:10240',
        ]);

        $tenant = $request->user()->tenant;

        // Remove PDF anterior se existir
        if ($tenant->tabela_precos_pdf_path) {
            Storage::delete($tenant->tabela_precos_pdf_path);
        }

        $path = $request->file('pdf')->storeAs(
            "tenants/{$tenant->id}",
            'tabela_precos.pdf'
        );

        try {
            $parser = new PdfParser();
            $pdf    = $parser->parseFile(Storage::path($path));
            $texto  = trim($pdf->getText());
        } catch (\Throwable $e) {
            Storage::delete($path);
            return response()->json([
                'error' => 'Não foi possível extrair o texto do PDF. Verifique se o arquivo não é uma imagem escaneada.',
            ], 422);
        }

        if (strlen($texto) < 20) {
            Storage::delete($path);
            return response()->json([
                'error' => 'O PDF não contém texto legível. Use um PDF gerado digitalmente (não escaneado).',
            ], 422);
        }

        $tenant->update([
            'tabela_precos_pdf_path' => $path,
            'tabela_precos_texto'    => $texto,
        ]);

        return response()->json([
            'ok'    => true,
            'nome'  => $request->file('pdf')->getClientOriginalName(),
            'chars' => strlen($texto),
        ]);
    }

    public function removerTabela(Request $request): JsonResponse
    {
        $tenant = $request->user()->tenant;

        if ($tenant->tabela_precos_pdf_path) {
            Storage::delete($tenant->tabela_precos_pdf_path);
        }

        $tenant->update([
            'tabela_precos_pdf_path' => null,
            'tabela_precos_texto'    => null,
        ]);

        return response()->json(['ok' => true]);
    }

    public function gerar(Request $request, OpenRouterService $openRouter): JsonResponse
    {
        $tenantId = $request->user()->tenant_id;

        $mensagens = Mensagem::whereHas('ticket', fn($q) => $q->where('tenant_id', $tenantId))
            ->whereIn('remetente', ['lead', 'contato', 'bot', 'humano'])
            ->where('tipo', 'texto')
            ->whereNotNull('conteudo')
            ->where('conteudo', '!=', '')
            ->orderByDesc('enviado_em')
            ->take(200)
            ->get(['remetente', 'conteudo'])
            ->reverse()
            ->values();

        if ($mensagens->count() < 5) {
            return response()->json([
                'error' => 'Poucas conversas registradas ainda. Faça alguns atendimentos antes de gerar o contexto automaticamente.',
            ], 422);
        }

        $dialogo = $mensagens->map(function ($m) {
            $quem = in_array($m->remetente, ['lead', 'contato']) ? 'Cliente' : 'Atendente';
            return "{$quem}: {$m->conteudo}";
        })->implode("\n");

        $promptSistema = 'Você é um especialista em análise de conversas comerciais. '
            . 'Sua tarefa é extrair informações de negócio de conversas de WhatsApp e organizar como base de conhecimento para uma IA de atendimento.';

        $promptUsuario = <<<EOT
Analise as conversas abaixo e crie uma BASE DE CONHECIMENTO estruturada do negócio.

O resultado deve incluir:
1. **Resumo do negócio** (o que faz, para quem, onde atua)
2. **Serviços/produtos oferecidos** (com detalhes de preço, prazo, condições quando mencionados)
3. **Processo de atendimento** (como funciona, quais informações o cliente precisa passar)
4. **Perguntas frequentes e respostas padrão** (situações comuns que aparecem nas conversas)
5. **Tom e estilo** (formal/informal, expressões usadas, como a empresa se comunica)
6. **Perguntas pendentes** (informações que NÃO apareceram nas conversas e que o dono deveria responder para melhorar o atendimento)

Seja específico com os dados reais das conversas. Use linguagem clara e direta. Formate com marcadores e seções.

--- CONVERSAS ---
{$dialogo}
--- FIM ---

Escreva a base de conhecimento:
EOT;

        $contexto = $openRouter->chat([
            ['role' => 'system', 'content' => $promptSistema],
            ['role' => 'user',   'content' => $promptUsuario],
        ], 'complexo', 2000);

        if (! $contexto) {
            return response()->json(['error' => 'Não foi possível gerar o contexto. Verifique a chave OpenRouter.'], 500);
        }

        return response()->json(['ia_contexto' => $contexto]);
    }
}
