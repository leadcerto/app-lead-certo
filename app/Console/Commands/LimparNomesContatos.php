<?php

namespace App\Console\Commands;

use App\Models\AuditoriaContato;
use App\Models\Contato;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Usa IA para limpar nomes legados, marcar não-nomes como "Sem Nome"
 * e normalizar telefones inválidos.
 *
 * Fluxo:
 *  1. Filtra contatos com padrão suspeito (prefixo, só números, empresa, etc.)
 *  2. Envia lotes de 30 para OpenRouter (gemini-flash-1.5-8b) para análise
 *  3. Salva: nome limpo ou "Sem Nome", descritor → sobrenome
 *  4. Normaliza telefone no banco (remove zeros iniciais, corrige formato)
 */
class LimparNomesContatos extends Command
{
    protected $signature   = 'contatos:limpar-nomes
                                {--dry-run : Mostra o que seria alterado sem salvar}
                                {--lote=30 : Contatos por chamada de IA}
                                {--tenant= : Filtrar por tenant_id}
                                {--so-telefones : Apenas normaliza telefones, sem processar nomes via IA}';
    protected $description = 'Usa IA para extrair nome real, marcar não-nomes como "Sem Nome" e normalizar telefones';

    private string $promptSistema = <<<'PROMPT'
Você é um especialista em limpeza de dados de contatos de uma empresa de fretes no Rio de Janeiro.

Receberá um array JSON com contatos, cada um com "id" e "nome_original".

Para cada contato, classifique em um de TRÊS tipos e retorne:
- "tipo": "pessoa" | "empresa" | "lixo"
- "nome": nome limpo (pessoa ou empresa) ou null se lixo
- "descritor": contexto extra que não é o nome (só para tipo="pessoa")

═══ TIPO "pessoa" — Nome de pessoa física ═══
Use quando o nome_original contém um nome humano real, mesmo que misturado com prefixos/códigos/descritores.
Extraia APENAS o nome humano e coloque o resto no descritor.

Exemplos de pessoa:
"- 01 Vinicius Chaves 3516 Gestor de Condominio" → tipo="pessoa", nome="Vinicius Chaves", descritor="Gestor De Condominio"
"- BAU-0300 Vitor Rufino 9542 BONGO Guaratiba"  → tipo="pessoa", nome="Vitor Rufino",    descritor="Bongo Guaratiba"
"FRETE Paulo SOFA"                              → tipo="pessoa", nome="Paulo",            descritor="Sofa"
"- Rafael Quentinha Marmita 3141"               → tipo="pessoa", nome="Rafael",           descritor="Quentinha Marmita"
"Ricardo Henrique CARRETINHA"                   → tipo="pessoa", nome="Ricardo Henrique", descritor="Carretinha"
"Marinho PINTOR CARRO"                          → tipo="pessoa", nome="Marinho",          descritor="Pintor Carro"
"Valmir 4452 Programador"                       → tipo="pessoa", nome="Valmir",           descritor="Programador"
"Jorge"                                         → tipo="pessoa", nome="Jorge",            descritor=null
"Abadia Rodrigues"                              → tipo="pessoa", nome="Abadia Rodrigues", descritor=null
"Abelardo Hollanda"                             → tipo="pessoa", nome="Abelardo Hollanda",descritor=null

═══ TIPO "empresa" — Nome de empresa, estabelecimento ou comércio ═══
Use quando o nome_original é claramente um negócio, não uma pessoa.
Retorne o nome da empresa limpo (sem prefixo/código/número antigo), sem descritor.
IMPORTANTE: Nomes de empresas identificam o contato — NÃO vire "Sem Nome".

Exemplos de empresa:
"AB Pinturas"                    → tipo="empresa", nome="AB Pinturas",          descritor=null
"AB Pinturas AB PINTURAS"        → tipo="empresa", nome="AB Pinturas",          descritor=null  (duplicata)
"1GOO MARKETING"                 → tipo="empresa", nome="1Goo Marketing",       descritor=null
"100 Por Cento Churrasco 3746"   → tipo="empresa", nome="100 Por Cento Churrasco", descritor=null
"ABM DISTRIBUIDORA MERCK #"      → tipo="empresa", nome="ABM Distribuidora Merck", descritor=null
"Oficina do José"                → tipo="empresa", nome="Oficina do José",      descritor=null
"Abigayl e Thiago"               → tipo="empresa", nome="Abigayl e Thiago",     descritor=null  (dois sócios)

═══ TIPO "lixo" — Não identifica nenhum contato ═══
Use quando o nome_original é completamente inidentificável.
Retorne nome=null. Esses contatos receberão "Sem Nome".

Exemplos de lixo:
"6"              → tipo="lixo", nome=null
"8"              → tipo="lixo", nome=null
"11"             → tipo="lixo", nome=null
"12"             → tipo="lixo", nome=null
"FRETE"          → tipo="lixo", nome=null  (só a classificação, sem pessoa)
"MOT"            → tipo="lixo", nome=null
"5521964549712"  → tipo="lixo", nome=null  (é telefone)
"021982223599"   → tipo="lixo", nome=null  (é telefone)
"Sem Nome"       → tipo="lixo", nome=null
"AB 7001"        → tipo="lixo", nome=null  (siglas + número, não identifica)
"A. Bertagnoli"  → tipo="lixo", nome=null  (inicial + sobrenome, incompleto)
"A.Gomes 9338"   → tipo="lixo", nome=null  (inicial + sobrenome + número, incompleto)

═══ REGRAS GERAIS ═══
1. O número de 3-6 dígitos no meio do nome é ID do sistema antigo — IGNORE-O COMPLETAMENTE.
2. "Quentinha","Marmita","Sofa","Bau","Frete","Carretinha","Bongo","Carro","Tanque" NÃO são nomes de gente.
3. Aplique Title Case em nome e descritor (primeira letra maiúscula de cada palavra).
4. Se nome_original já for um nome limpo (ex: "Carlos", "Maria Silva"), confirme como pessoa sem alterar.
5. Responda SOMENTE com array JSON puro, sem markdown:
   [{"id": 1, "tipo": "pessoa", "nome": "Vitor Rufino", "descritor": "Bongo Guaratiba"}]
PROMPT;

    public function handle(): int
    {
        $dryRun      = $this->option('dry-run');
        $loteSize    = max(10, min(50, (int) $this->option('lote')));
        $tenantId    = $this->option('tenant');
        $soTelefones = $this->option('so-telefones');

        $apiKey = config('services.openrouter.key') ?: env('OPENROUTER_KEY') ?: env('OPENROUTER_API_KEY');

        if (! $soTelefones && ! $apiKey) {
            $this->error('OPENROUTER_API_KEY não configurada.');
            return Command::FAILURE;
        }

        $this->info($dryRun ? '--- DRY RUN (nada será salvo) ---' : 'Iniciando limpeza de contatos...');

        // ── 1. Normalização de telefones ──────────────────────────────────────
        $this->normalizarTelefones($dryRun, $tenantId);

        if ($soTelefones) {
            return Command::SUCCESS;
        }

        // ── 2. Limpeza de nomes via IA ────────────────────────────────────────
        $this->limparNomesViaIA($dryRun, $loteSize, $apiKey, $tenantId);

        return Command::SUCCESS;
    }

    // ── Normalização de telefones ─────────────────────────────────────────────

    private function normalizarTelefones(bool $dryRun, ?string $tenantId): void
    {
        $this->line('');
        $this->info('Normalizando telefones...');

        $query = Contato::query();
        if ($tenantId) {
            $query->whereHas('vinculos', fn($q) => $q->where('tenant_id', $tenantId));
        }

        $corrigidos  = 0;
        $invalidos   = 0;
        $excluidos   = 0;
        $emAuditoria = 0;

        $query->orderBy('id')->chunk(500, function ($contatos) use ($dryRun, &$corrigidos, &$invalidos, &$excluidos, &$emAuditoria) {
            foreach ($contatos as $contato) {
                $original = $contato->telefone ?? '';
                if (! $original) continue;

                [$normalizado, $sugestao, $motivo] = $this->normalizarTelefoneCompleto($original);

                if ($normalizado === null) {
                    $invalidos++;
                    $digitos = preg_replace('/\D/', '', $original);

                    // Aberração: telefone com menos de 8 dígitos → soft-delete direto
                    if (strlen($digitos) < 8) {
                        if ($this->output->isVerbose()) {
                            $this->warn("  [EXCLUIR] #{$contato->id}: '{$original}' — aberração ({$digitos} dígitos)");
                        }
                        if (! $dryRun) {
                            $contato->delete(); // soft delete
                            $excluidos++;
                        }
                        continue;
                    }

                    // Tem sugestão de correção → auditoria para revisão humana
                    if ($this->output->isVerbose()) {
                        $this->warn("  [AUDITORIA] #{$contato->id}: '{$original}' | {$motivo}" . ($sugestao ? " → sugerido: '{$sugestao}'" : ''));
                    }

                    if (! $dryRun) {
                        AuditoriaContato::firstOrCreate(
                            ['contato_id' => $contato->id, 'campo' => 'telefone', 'status' => 'pendente'],
                            [
                                'tipo'           => 'telefone_invalido',
                                'valor_original' => $original,
                                'valor_sugerido' => $sugestao,
                                'observacao'     => $motivo,
                            ]
                        );
                        $emAuditoria++;
                    }
                    continue;
                }

                $semPlus = ltrim($normalizado, '+');

                if ($semPlus === $original) {
                    continue;
                }

                $corrigidos++;
                if ($this->output->isVerbose()) {
                    $this->line("  [TEL] #{$contato->id}: '{$original}' → '{$semPlus}'");
                }

                if (! $dryRun) {
                    $contato->update(['telefone' => $semPlus]);
                    AuditoriaContato::where('contato_id', $contato->id)
                        ->where('campo', 'telefone')
                        ->where('status', 'pendente')
                        ->delete();
                }
            }
        });

        $this->line("  Telefones corrigidos: {$corrigidos} | Excluídos: {$excluidos} | Na auditoria: {$invalidos}");
    }

    /**
     * Tenta normalizar um telefone para o formato internacional brasileiro.
     * Retorna array [normalizado|null, sugestao|null, motivo].
     *
     * normalizado → string "+55XXXXXXXXXXX" se conseguiu corrigir
     * null        → não conseguiu; sugestao pode ter um palpite para o auditor
     */
    // DDIs internacionais reconhecidos (2 e 3 dígitos)
    private const DDI_2 = [
        '1','7','20','27','30','31','32','33','34','36','39','40','41','43',
        '44','45','46','47','48','49','51','52','53','54','56','57','58',
        '60','61','62','63','64','65','66','81','82','84','86','90','91',
        '92','94','95','98',
    ];
    private const DDI_3 = [
        '351','352','353','354','355','356','357','358','359',
        '370','371','372','373','374','375','376','377','378','380',
        '381','382','385','386','387','389',
        '420','421','423',
        '500','501','502','503','504','505','506','507','509',
        '590','591','592','593','594','595','596','597','598','599',
        '852','853','855','856','880','886',
        '960','961','962','963','964','965','966','967','968',
        '970','971','972','973','974','975','976','977',
        '992','993','994','995','996','998',
    ];

    private function normalizarTelefoneCompleto(string $telefone): array
    {
        $digitos = preg_replace('/\D/', '', $telefone);

        if (strlen($digitos) === 0) {
            return [null, null, 'Telefone vazio ou sem dígitos'];
        }

        $semZero = ltrim($digitos, '0');

        // ── Padrão 1: DDI 55 (Brasil) — 12-13 dígitos ────────────────────────
        if (str_starts_with($semZero, '55') && strlen($semZero) >= 12 && strlen($semZero) <= 13) {
            return ['+' . $semZero, null, ''];
        }

        // ── Padrão 2: DDI internacional reconhecido ───────────────────────────
        // Testa DDI de 3 dígitos primeiro (mais específico), depois 2 dígitos
        $ddiEncontrado = null;
        foreach (self::DDI_3 as $ddi) {
            if (str_starts_with($semZero, $ddi)) {
                $resto = substr($semZero, strlen($ddi));
                if (strlen($resto) >= 6 && strlen($resto) <= 12) {
                    $ddiEncontrado = $ddi;
                    break;
                }
            }
        }
        if (! $ddiEncontrado) {
            foreach (self::DDI_2 as $ddi) {
                if (str_starts_with($semZero, $ddi)) {
                    $resto = substr($semZero, strlen($ddi));
                    if (strlen($resto) >= 6 && strlen($resto) <= 12) {
                        $ddiEncontrado = $ddi;
                        break;
                    }
                }
            }
        }
        if ($ddiEncontrado) {
            return ['+' . $semZero, null, ''];
        }

        // ── Padrão 3: DDD + número brasileiro (10-11 dígitos) ────────────────
        if (strlen($semZero) >= 10 && strlen($semZero) <= 11) {
            return ['+55' . $semZero, null, ''];
        }

        // ── Padrão 4: DDD duplicado — "0+DDD+DDD+numero" ─────────────────────
        if (strlen($semZero) >= 12 && strlen($semZero) <= 14) {
            $ddd   = substr($semZero, 0, 2);
            $resto = substr($semZero, 2);
            if (str_starts_with($resto, $ddd) && strlen($resto) >= 10 && strlen($resto) <= 11) {
                return ['+55' . $resto, null, ''];
            }
        }

        // ── Padrão 5: DDI 55 muito longo — sugere truncar ────────────────────
        if (str_starts_with($semZero, '55') && strlen($semZero) > 13) {
            $sugestao = substr($semZero, 0, 13);
            return [null, $sugestao, "Número muito longo ({$digitos}) — possível erro de digitação"];
        }

        // ── Não reconhecido ───────────────────────────────────────────────────
        return [null, null, "Formato desconhecido — " . strlen($digitos) . " dígitos: {$digitos}"];
    }

    // Mantido por compatibilidade com chamadas anteriores
    private function normalizarTelefone(string $telefone): ?string
    {
        [$normalizado] = $this->normalizarTelefoneCompleto($telefone);
        return $normalizado;
    }

    // ── Limpeza de nomes via IA ───────────────────────────────────────────────

    private function limparNomesViaIA(bool $dryRun, int $loteSize, string $apiKey, ?string $tenantId): void
    {
        $this->line('');
        $this->info('Limpando nomes com IA...');

        // Filtra contatos que provavelmente têm nome problemático
        $query = Contato::query()
            ->where('nome', '!=', 'Sem Nome')
            ->where(function ($q) {
                $q->where('nome', 'REGEXP', '^[-–]')                        // começa com traço
                  ->orWhere('nome', 'REGEXP', '^(FRETE|MOT|PCR|BAU|MOV)')  // classificação legada
                  ->orWhere('nome', 'REGEXP', '^[0-9]')                     // começa com número
                  ->orWhere('nome', 'REGEXP', '[0-9]{3,}')                  // tem sequência de 3+ dígitos
                  ->orWhere('nome', 'REGEXP', '[A-Z]{4,}')                  // tem CAIXA ALTA longa
                  ->orWhere('nome', 'REGEXP', '[.#@]')                      // tem ponto, #, @
                  ->orWhereRaw('LENGTH(nome) <= 3')                         // muito curto (1-3 chars)
                  ->orWhereColumn('nome', 'telefone');                       // nome = telefone
            });

        if ($tenantId) {
            $query->whereHas('vinculos', fn($q) => $q->where('tenant_id', $tenantId));
        }

        $total     = $query->count();
        $alterados = 0;
        $semNome   = 0;
        $falhas    = 0;

        $this->line("  {$total} contatos com nome suspeito para processar");

        if ($total === 0) {
            $this->info('  Nenhum contato suspeito encontrado.');
            return;
        }

        $bar = $this->output->createProgressBar($total);
        $bar->start();

        $query->orderBy('id')->chunk($loteSize, function ($lote) use (
            $dryRun, &$alterados, &$semNome, &$falhas, $apiKey, $bar
        ) {
            $resultados = $this->processarLoteIA($lote->all(), $apiKey);

            if ($resultados === null) {
                $falhas += $lote->count();
                $bar->advance($lote->count());
                usleep(500_000); // Espera 500ms antes de tentar o próximo lote
                return;
            }

            foreach ($resultados as $resultado) {
                $id      = $resultado['id'] ?? null;
                $contato = $lote->firstWhere('id', $id);
                if (! $contato || ! $id) {
                    $bar->advance();
                    continue;
                }

                $tipo        = $resultado['tipo'] ?? null;
                $nomeIA      = $resultado['nome'] ?? null;
                $descritorIA = $resultado['descritor'] ?? null;

                // LIXO: vai para auditoria — nunca apaga sem revisão humana
                if ($tipo === 'lixo' || ! $nomeIA) {
                    if ($this->output->isVerbose()) {
                        $this->newLine();
                        $this->warn("  [AUDITORIA] #{$contato->id}: '{$contato->nome}' → sugerido: 'Sem Nome'");
                    }
                    if (! $dryRun) {
                        AuditoriaContato::firstOrCreate(
                            ['contato_id' => $contato->id, 'campo' => 'nome', 'status' => 'pendente'],
                            [
                                'tipo'           => 'nome_invalido',
                                'valor_original' => $contato->nome,
                                'valor_sugerido' => 'Sem Nome',
                                'observacao'     => 'IA classificou como lixo — sem nome identificável',
                            ]
                        );
                    }
                    $semNome++;
                    $bar->advance();
                    continue;
                }

                // EMPRESA: mantém o nome da empresa limpo, sem descritor
                // PESSOA: nome humano limpo + descritor no sobrenome
                $mudouNome      = $nomeIA !== $contato->nome;
                $mudouDescritor = $tipo === 'pessoa' && $descritorIA !== $contato->sobrenome;

                if (! $mudouNome && ! $mudouDescritor) {
                    $bar->advance();
                    continue;
                }

                $alterados++;

                if ($this->output->isVerbose()) {
                    $this->newLine();
                    $label = $tipo === 'empresa' ? '[EMPRESA]' : '[PESSOA]';
                    $this->line("  {$label} #{$contato->id}: '{$contato->nome}' → nome='{$nomeIA}' | descritor='{$descritorIA}'");
                }

                if (! $dryRun) {
                    $updates = ['nome' => $nomeIA];
                    if ($tipo === 'pessoa' && $descritorIA) {
                        $updates['sobrenome'] = $descritorIA;
                    }
                    $contato->update($updates);
                }

                $bar->advance();
            }

            usleep(300_000); // 300ms entre lotes
        });

        $bar->finish();
        $this->newLine();
        $this->info("  Nomes limpos: {$alterados} | Marcados 'Sem Nome': {$semNome}");

        if ($falhas > 0) {
            $this->warn("  Falhas de IA: {$falhas} — rode novamente para tentar de novo");
        }
        if ($dryRun) {
            $this->warn('  Nada foi salvo. Remova --dry-run para aplicar.');
        }
    }

    private function processarLoteIA(array $contatos, string $apiKey): ?array
    {
        $input = array_map(fn($c) => [
            'id'            => $c->id,
            'nome_original' => $c->nome,
        ], $contatos);

        try {
            $res = Http::withHeaders([
                'Authorization' => "Bearer {$apiKey}",
                'Content-Type'  => 'application/json',
                'HTTP-Referer'  => 'https://app.leadcerto.app.br',
                'X-Title'       => 'LeadCerto Name Cleaner',
            ])->timeout(60)->post('https://openrouter.ai/api/v1/chat/completions', [
                'model'    => env('OPENROUTER_MODELO_SIMPLES', 'openai/gpt-4o-mini'),
                'messages' => [
                    ['role' => 'system', 'content' => $this->promptSistema],
                    ['role' => 'user',   'content' => json_encode($input, JSON_UNESCAPED_UNICODE)],
                ],
                'temperature' => 0.1,
            ]);

            if (! $res->successful()) {
                Log::warning('LimparNomesContatos: falha OpenRouter', [
                    'status' => $res->status(),
                    'body'   => substr($res->body(), 0, 300),
                ]);
                return null;
            }

            $conteudo = $res->json('choices.0.message.content') ?? '';

            // Remove markdown fence se vier com ```json ... ```
            $conteudo = preg_replace('/^```(?:json)?\s*/i', '', trim($conteudo));
            $conteudo = preg_replace('/\s*```$/', '', $conteudo);

            $decoded = json_decode($conteudo, true);

            if (! is_array($decoded)) {
                Log::warning('LimparNomesContatos: JSON inválido da IA', [
                    'conteudo' => substr($conteudo, 0, 500),
                ]);
                return null;
            }

            return $decoded;
        } catch (\Exception $e) {
            Log::error('LimparNomesContatos: exception', ['erro' => $e->getMessage()]);
            return null;
        }
    }
}
