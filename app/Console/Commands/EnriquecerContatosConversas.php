<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class EnriquecerContatosConversas extends Command
{
    protected $signature = 'contatos:enriquecer-conversas
                            {--limit=30 : Máximo de contatos por rodada}
                            {--dry-run  : Mostra o que seria extraído sem salvar}';

    protected $description = 'Lê conversas e extrai email, profissão e empresa dos contatos via IA';

    public function handle(): int
    {
        $limit  = (int) $this->option('limit');
        $dry    = $this->option('dry-run');
        $apiKey = config('services.openrouter.key', '');
        $modelo = config('services.openrouter.modelo_simples', 'openai/gpt-4o-mini');

        if (! $apiKey) {
            $this->error('OPENROUTER_KEY não configurado no .env');
            return Command::FAILURE;
        }

        // Contatos com mensagens mas sem email E sem profissão E sem empresa
        $contatos = DB::table('contatos as c')
            ->join('tickets_atendimento as t', 't.contato_id', '=', 'c.id')
            ->join('mensagens as m', 'm.ticket_id', '=', 't.id')
            ->where(function ($q) {
                $q->whereNull('c.email')
                  ->orWhereNull('c.profissao')
                  ->orWhereNull('c.empresa');
            })
            ->whereNull('c.deleted_at')
            ->where('m.remetente', 'lead')
            ->whereNotNull('m.conteudo')
            ->where('m.conteudo', '!=', '')
            ->select('c.id', 'c.telefone', 'c.nome', 'c.email', 'c.profissao', 'c.empresa')
            ->distinct()
            ->limit($limit)
            ->get();

        $this->info("Contatos para enriquecer: {$contatos->count()}");

        $enriquecidos = 0;
        $semDados     = 0;

        foreach ($contatos as $row) {
            $mensagens = DB::table('mensagens as m')
                ->join('tickets_atendimento as t', 't.id', '=', 'm.ticket_id')
                ->where('t.contato_id', $row->id)
                ->where('m.remetente', 'lead')
                ->whereNotNull('m.conteudo')
                ->where('m.conteudo', '!=', '')
                ->orderBy('m.id')
                ->limit(40)
                ->pluck('m.conteudo')
                ->implode("\n");

            if (mb_strlen($mensagens) < 20) {
                $semDados++;
                continue;
            }

            $prompt = [
                [
                    'role'    => 'system',
                    'content' => 'Você extrai dados de contato de conversas de WhatsApp. Responda APENAS com JSON válido, sem markdown. Retorne null nos campos não encontrados. Nunca invente dados.',
                ],
                [
                    'role'    => 'user',
                    'content' => <<<PROMPT
Extraia do texto abaixo os dados do cliente. Retorne SOMENTE este JSON:
{"nome": null, "email": null, "profissao": null, "empresa": null}

Texto:
{$mensagens}
PROMPT,
                ],
            ];

            try {
                $response = Http::withHeaders([
                    'Authorization' => "Bearer {$apiKey}",
                    'HTTP-Referer'  => config('app.url'),
                    'X-Title'       => 'Lead Certo',
                ])->timeout(20)->post('https://openrouter.ai/api/v1/chat/completions', [
                    'model'       => $modelo,
                    'temperature' => 0.1,
                    'max_tokens'  => 150,
                    'messages'    => $prompt,
                ]);

                if ($response->failed()) {
                    $semDados++;
                    continue;
                }

                $raw  = $response->json('choices.0.message.content', '');
                $raw  = trim(preg_replace('/^```(?:json)?\s*|\s*```$/m', '', $raw));
                $data = json_decode($raw, true);

                if (! is_array($data)) {
                    $semDados++;
                    continue;
                }

                $updates = [];
                foreach (['nome', 'email', 'profissao', 'empresa'] as $campo) {
                    if (! empty($data[$campo]) && empty($row->{$campo})) {
                        $updates[$campo] = mb_substr(trim($data[$campo]), 0, 200);
                    }
                }

                if (empty($updates)) {
                    $semDados++;
                    continue;
                }

                $this->line("  #{$row->id} {$row->nome}: " . implode(', ', array_keys($updates)));

                if (! $dry) {
                    DB::table('contatos')->where('id', $row->id)->update($updates);
                    Log::info('EnriquecerContatos: atualizado', ['id' => $row->id, 'campos' => array_keys($updates)]);
                }

                $enriquecidos++;
            } catch (\Throwable $e) {
                Log::warning('EnriquecerContatos: erro', ['id' => $row->id, 'erro' => $e->getMessage()]);
                $semDados++;
            }
        }

        $this->table(['Status', 'Qtd'], [
            ['Enriquecidos', $enriquecidos],
            ['Sem dados suficientes', $semDados],
        ]);

        if ($dry) {
            $this->warn('DRY-RUN — nada foi salvo.');
        }

        return Command::SUCCESS;
    }
}
