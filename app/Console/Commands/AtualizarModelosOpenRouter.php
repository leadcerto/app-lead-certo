<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Busca a lista atual de modelos gratuitos no OpenRouter e salva em
 * storage/app/openrouter-free-models.json.
 *
 * Agendado diariamente às 00:01 para evitar falhas silenciosas por
 * modelos descontinuados ou indisponíveis no plano gratuito.
 */
class AtualizarModelosOpenRouter extends Command
{
    protected $signature   = 'openrouter:atualizar-modelos';
    protected $description = 'Atualiza lista de modelos gratuitos do OpenRouter disponíveis hoje';

    private const FILE = 'openrouter-free-models.json';
    private const MAX  = 3;

    // Modelos de visão são identificados por keywords no ID
    private const VISION_KEYWORDS = ['vision', 'vl', 'visual', 'multimodal', 'omni', 'vlm'];

    // Tipos de modelo que não servem para extração de nomes / chat geral
    private const EXCLUDE_KEYWORDS = ['embed', 'rerank', 'content-safety', 'reward', 'classify', 'coder', 'code-'];

    public function handle(): int
    {
        $this->info('Buscando modelos gratuitos no OpenRouter...');

        try {
            $response = Http::timeout(20)->get('https://openrouter.ai/api/v1/models');
        } catch (\Exception $e) {
            $this->error('Falha na requisição: ' . $e->getMessage());
            Log::error('AtualizarModelosOpenRouter: falha HTTP', ['erro' => $e->getMessage()]);
            return Command::FAILURE;
        }

        if (! $response->successful()) {
            $this->error('API retornou status ' . $response->status());
            Log::error('AtualizarModelosOpenRouter: status inesperado', ['status' => $response->status()]);
            return Command::FAILURE;
        }

        $todos = $response->json('data', []);

        // Só modelos gratuitos com contexto razoável (≥ 8k tokens)
        $livres = array_filter($todos, fn($m) =>
            str_ends_with($m['id'] ?? '', ':free') && ($m['context_length'] ?? 0) >= 8192
        );

        $textModels   = [];
        $visionModels = [];

        foreach ($livres as $m) {
            $id  = $m['id'];
            $ctx = $m['context_length'] ?? 0;

            // Exclui modelos não-chat
            $idLower  = strtolower($id);
            $excluido = false;
            foreach (self::EXCLUDE_KEYWORDS as $kw) {
                if (str_contains($idLower, $kw)) {
                    $excluido = true;
                    break;
                }
            }
            if ($excluido) continue;

            // Classifica entre visão e texto
            $temVisao = false;
            foreach (self::VISION_KEYWORDS as $kw) {
                if (str_contains($idLower, $kw)) {
                    $temVisao = true;
                    break;
                }
            }

            if ($temVisao) {
                $visionModels[$id] = $ctx;
            } else {
                $textModels[$id] = $ctx;
            }
        }

        // Ordena por context_length desc: mais contexto = modelo mais capaz
        arsort($textModels);
        arsort($visionModels);

        $topText   = array_slice(array_keys($textModels), 0, self::MAX);
        $topVision = array_slice(array_keys($visionModels), 0, self::MAX);

        // Lê estado anterior para detectar mudanças
        $storagePath = storage_path('app/' . self::FILE);
        $anterior    = file_exists($storagePath)
            ? (json_decode(file_get_contents($storagePath), true) ?? [])
            : [];

        $textAnterior   = $anterior['text']   ?? [];
        $visionAnterior = $anterior['vision'] ?? [];

        // Salva
        $dados = [
            'text'         => $topText,
            'vision'       => $topVision,
            'total_livres' => count($livres),
            'updated_at'   => now()->toDateTimeString(),
        ];

        file_put_contents($storagePath, json_encode($dados, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        // Relatório de mudanças
        $mudouText   = $this->reportarMudancas('TEXT', $textAnterior, $topText);
        $mudouVision = $this->reportarMudancas('VISION', $visionAnterior, $topVision);

        $this->info('');
        $this->info('Modelos TEXT   (' . count($topText) . '): ' . implode(', ', $topText));
        $this->info('Modelos VISION (' . count($topVision) . '): ' . ($topVision ? implode(', ', $topVision) : '(nenhum gratuito)'));
        $this->info("Total modelos :free na plataforma: {$dados['total_livres']}");

        if ($mudouText || $mudouVision) {
            Log::warning('AtualizarModelosOpenRouter: lista de modelos alterada', $dados);
        } else {
            Log::info('AtualizarModelosOpenRouter: sem mudanças', ['updated_at' => $dados['updated_at']]);
        }

        return Command::SUCCESS;
    }

    private function reportarMudancas(string $tipo, array $antes, array $depois): bool
    {
        $removidos   = array_values(array_diff($antes, $depois));
        $adicionados = array_values(array_diff($depois, $antes));

        if ($removidos) {
            $this->warn("[{$tipo}] Removidos: " . implode(', ', $removidos));
        }
        if ($adicionados) {
            $this->line("[{$tipo}] Adicionados: " . implode(', ', $adicionados));
        }

        return (bool) ($removidos || $adicionados);
    }
}
