<?php

namespace App\Console\Commands;

use App\Services\FreeModelsService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class IdentificarNomesConversas extends Command
{
    protected $signature = 'contatos:identificar-nomes
                            {--limit=50 : Máximo de contatos a processar por rodada}
                            {--dry-run  : Mostra o que seria identificado sem salvar}';

    protected $description = 'Lê as conversas de contatos "Sem Nome" e usa IA gratuita para identificar e salvar o nome';

    // Modelos carregados em runtime via FreeModelsService (atualizado às 00:01 diariamente)

    public function handle(): int
    {
        $limit  = (int) $this->option('limit');
        $dry    = $this->option('dry-run');
        $apiKey = config('services.openrouter.key', '');

        // Contatos sem nome real: "Sem Nome" ou nome = telefone (número)
        $contatos = DB::table('contatos as c')
            ->join('tickets_atendimento as t', 't.contato_id', '=', 'c.id')
            ->join('mensagens as m', 'm.ticket_id', '=', 't.id')
            ->where(function ($q) {
                $q->where('c.nome', 'Sem Nome')
                  ->orWhereColumn('c.nome', 'c.telefone')
                  ->orWhere('c.nome', 'REGEXP', '^[0-9+]+$'); // nome é só dígitos/+
            })
            ->whereNull('c.deleted_at')
            ->where('m.remetente', 'lead')
            ->whereNotNull('m.conteudo')
            ->where('m.conteudo', '!=', '')
            ->select('c.id', 'c.telefone', 'c.nome')
            ->distinct()
            ->limit($limit)
            ->get();

        if (! $apiKey) {
            $this->error('OPENROUTER_KEY não configurado no .env');
            return Command::FAILURE;
        }

        $this->info("Contatos sem nome com mensagens: {$contatos->count()}");

        $identificados = 0;
        $naoEncontrou  = 0;

        foreach ($contatos as $row) {
            // Pega até 30 mensagens do lead para esse contato
            $mensagens = DB::table('mensagens as m')
                ->join('tickets_atendimento as t', 't.id', '=', 'm.ticket_id')
                ->where('t.contato_id', $row->id)
                ->where('m.remetente', 'lead')
                ->whereNotNull('m.conteudo')
                ->where('m.conteudo', '!=', '')
                ->orderBy('m.enviado_em')
                ->limit(30)
                ->pluck('m.conteudo')
                ->toArray();

            if (empty($mensagens)) continue;

            $conversa = implode("\n", array_map(fn($m) => "Lead: {$m}", $mensagens));

            [$nome, $erro] = $this->extrairNome($apiKey, $conversa, $row->telefone);

            if ($nome) {
                $this->line("  ✓ #{$row->id} ({$row->telefone}) → {$nome}");
                if (! $dry) {
                    DB::table('contatos')->where('id', $row->id)->update(['nome' => $nome]);
                }
                $identificados++;
            } elseif ($erro) {
                $this->warn("  ! #{$row->id} ({$row->telefone}) → ERRO: {$erro}");
                $naoEncontrou++;
            } else {
                $this->line("  - #{$row->id} ({$row->telefone}) → não identificado");
                $naoEncontrou++;
            }

            // Aguarda entre chamadas para não estourar rate-limit dos modelos gratuitos
            sleep(4);
        }

        $this->info("Identificados: {$identificados} | Não encontrados: {$naoEncontrou}");
        if ($dry) $this->warn('DRY-RUN — nenhuma alteração foi salva.');

        return Command::SUCCESS;
    }

    /** @return array{0: ?string, 1: ?string} [nome_identificado, mensagem_de_erro] */
    private function extrairNome(string $apiKey, string $conversa, string $telefone): array
    {
        try {
            $response = Http::withHeaders([
                'Authorization' => "Bearer {$apiKey}",
                'HTTP-Referer'  => config('app.url', 'https://app.leadcerto.app.br'),
                'X-Title'       => 'Lead Certo',
            ])->timeout(30)->post('https://openrouter.ai/api/v1/chat/completions', [
                'models'     => FreeModelsService::text(),
                'route'      => 'fallback',
                'max_tokens' => 50,
                'messages'   => [
                    [
                        'role'    => 'system',
                        'content' => "Você identifica nomes de pessoas em conversas de WhatsApp.\n"
                            . "Retorne APENAS o nome próprio da pessoa, sem mais nada.\n"
                            . "Se a pessoa se apresentou (ex: 'Aqui é o João', 'Sou a Maria', 'Meu nome é Carlos'), retorne esse nome.\n"
                            . "Se assinou a mensagem, retorne a assinatura.\n"
                            . "Se não há nome identificável, retorne exatamente: NAO_IDENTIFICADO\n"
                            . "Capitalize corretamente. Não invente nomes.",
                    ],
                    [
                        'role'    => 'user',
                        'content' => "Telefone: {$telefone}\n\nMensagens do contato:\n{$conversa}",
                    ],
                ],
            ]);
        } catch (\Exception $e) {
            Log::warning('IdentificarNomes: timeout/rede', ['telefone' => $telefone, 'erro' => $e->getMessage()]);
            return [null, 'timeout: ' . $e->getMessage()];
        }

        if (! $response->successful()) {
            $msg = $response->json('error.message') ?? "HTTP {$response->status()}";
            Log::warning('IdentificarNomes: API error', ['telefone' => $telefone, 'status' => $response->status(), 'msg' => $msg]);
            return [null, $msg];
        }

        $resposta = trim($response->json('choices.0.message.content') ?? '');

        if (! $resposta || $resposta === 'NAO_IDENTIFICADO') return [null, null];
        if (strlen($resposta) < 2 || strlen($resposta) > 100) return [null, null];
        if (! preg_match('/[A-Za-zÀ-ú]{2,}/', $resposta)) return [null, null];

        return [$resposta, null];
    }
}
