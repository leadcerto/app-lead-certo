<?php

namespace App\Services;

use App\Models\Contato;
use App\Models\ContatoPendente;
use App\Models\GoogleToken;
use App\Models\VinculoContatoTenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ContatoSyncService
{
    // Limiar de similaridade: abaixo disso → possível número reciclado → auditoria
    private const LIMIAR_SIMILARIDADE = 75.0;

    public function __construct(private GoogleService $google) {}

    /**
     * Executa o sync para um tenant.
     * Usa delta (syncToken) se disponível; full sync na primeira vez.
     */
    public function sincronizar(GoogleToken $token, int $tenantId): array
    {
        $resultado = ['importados' => 0, 'atualizados' => 0, 'conflitos' => 0, 'ignorados' => 0, 'erros' => []];

        $usouDelta = false;

        // ── Delta sync se houver sync token ──────────────────────────────────
        if ($token->sync_token) {
            $res = $this->google->listarContatosDelta($token, $token->sync_token);

            if (! empty($res['token_expirado'])) {
                // Token expirado → cai no full sync abaixo
                $token->update(['sync_token' => null]);
            } else {
                $usouDelta = true;
                $pessoas   = $res['contatos'];
                $nextToken = $res['nextSyncToken'];
            }
        }

        // ── Full sync (primeira vez ou token expirado) ────────────────────────
        if (! $usouDelta) {
            $res       = $this->google->listarContatos($token);
            $pessoas   = $res['contatos'];
            $nextToken = $res['nextSyncToken'];
        }

        // ── Processar cada contato ────────────────────────────────────────────
        foreach ($pessoas as $pessoa) {
            try {
                $this->processarPessoa($pessoa, $tenantId, $resultado);
            } catch (\Exception $e) {
                $resultado['erros'][] = $e->getMessage();
                Log::error('Erro no sync Google', ['erro' => $e->getMessage()]);
            }
        }

        // ── Salvar sync token para o próximo delta ────────────────────────────
        if ($nextToken) {
            $token->update(['sync_token' => $nextToken, 'ultima_sync_em' => now()]);
        }

        $resultado['erros'] = array_slice($resultado['erros'], 0, 10);
        return $resultado;
    }

    // ── Processar um contato do Google ────────────────────────────────────────

    private function processarPessoa(array $pessoa, int $tenantId, array &$resultado): void
    {
        $nomeRaw = $pessoa['names'][0]['displayName'] ?? null;

        if (! $nomeRaw) {
            $resultado['ignorados']++;
            return;
        }

        // Limpa o sufixo de 4 dígitos (localizador antigo) apenas em nomes compostos
        $nome = trim(preg_replace('/^(.+\s.+)\s\d{4}$/', '$1', $nomeRaw));
        if (! $nome) { $resultado['ignorados']++; return; }

        $dados = $this->extrairDados($pessoa, $nome);

        $telefones = array_values(array_filter(
            array_map(fn($p) => $this->limparTelefone($p['value'] ?? ''), $pessoa['phoneNumbers'] ?? [])
        ));

        if (empty($telefones)) { $resultado['ignorados']++; return; }

        foreach ($telefones as $idx => $telefone) {
            DB::transaction(function () use (
                $telefone, $idx, $nome, $dados, $tenantId, $pessoa, &$resultado
            ) {
                $existente = Contato::where('telefone', $telefone)->first();

                if (! $existente) {
                    // ── Contato novo ─────────────────────────────────────────
                    $contato = Contato::create(array_merge($dados, [
                        'telefone' => $telefone,
                        'origem'   => 'agenda_google',
                        'opt_out'  => false,
                    ]));

                    VinculoContatoTenant::updateOrCreate(
                        ['contato_id' => $contato->id, 'tenant_id' => $tenantId],
                        $this->dadosVinculo($pessoa)
                    );

                    $resultado['importados']++;
                } else {
                    // ── Telefone já existe — Identity Resolution ──────────────
                    $similaridade = $this->similaridadeNome($nome, $existente->nome ?? '');

                    if ($similaridade >= self::LIMIAR_SIMILARIDADE || ! $existente->nome) {
                        // Mesma pessoa → atualiza campos vazios e garante vínculo
                        $atualizar = [];
                        foreach ($dados as $campo => $valor) {
                            if (! in_array($campo, ['origem', 'opt_out']) && empty($existente->$campo) && $valor) {
                                $atualizar[$campo] = $valor;
                            }
                        }
                        if ($atualizar) $existente->update($atualizar);

                        VinculoContatoTenant::updateOrCreate(
                            ['contato_id' => $existente->id, 'tenant_id' => $tenantId],
                            $this->dadosVinculo($pessoa)
                        );

                        $resultado['atualizados']++;
                    } else {
                        // Similaridade baixa → possível chip reciclado → auditoria
                        ContatoPendente::create([
                            'tenant_id'           => $tenantId,
                            'telefone'            => $telefone,
                            'nome'                => $nome,
                            'dados_brutos'        => $dados,
                            'tipo_conflito'       => 'numero_possivelmente_reciclado',
                            'contato_existente_id' => $existente->id,
                            'nome_existente'      => $existente->nome,
                            'similaridade_nome'   => $similaridade,
                            'status'              => 'aguardando',
                            'criado_em'           => now(),
                        ]);

                        $resultado['conflitos']++;
                    }
                }
            });
        }
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function extrairDados(array $pessoa, string $nome): array
    {
        $nomeData    = $pessoa['names'][0] ?? [];
        $org         = $pessoa['organizations'][0] ?? [];
        $emails      = $pessoa['emailAddresses'] ?? [];
        $fones       = $pessoa['phoneNumbers'] ?? [];
        $end         = $pessoa['addresses'][0] ?? [];

        $website = $instagram = $facebook = $linkedin = $twitter = $tiktok = $youtube = null;
        foreach ($pessoa['urls'] ?? [] as $url) {
            $v = trim($url['value'] ?? '');
            if (! $v) continue;
            if (str_contains($v, 'instagram.com'))                          { $instagram = $v; }
            elseif (str_contains($v, 'facebook.com'))                       { $facebook  = $v; }
            elseif (str_contains($v, 'linkedin.com'))                       { $linkedin  = $v; }
            elseif (str_contains($v, 'twitter.com') || str_contains($v, 'x.com')) { $twitter = $v; }
            elseif (str_contains($v, 'tiktok.com'))                        { $tiktok   = $v; }
            elseif (str_contains($v, 'youtube.com'))                       { $youtube  = $v; }
            elseif (! $website)                                             { $website  = $v; }
        }

        $aniversario = null;
        if (! empty($pessoa['birthdays'][0]['date'])) {
            $d = $pessoa['birthdays'][0]['date'];
            if (! empty($d['year']) && ! empty($d['month']) && ! empty($d['day'])) {
                $aniversario = sprintf('%04d-%02d-%02d', $d['year'], $d['month'], $d['day']);
            }
        }

        $tel2 = ! empty($fones[1]) ? $this->limparTelefone($fones[1]['value'] ?? '') : null;

        return array_filter([
            'nome'           => $nome,
            'nome_do_meio'   => trim($nomeData['middleName'] ?? '') ?: null,
            'sobrenome'      => trim($nomeData['familyName'] ?? '') ?: null,
            'prefixo'        => trim($nomeData['honorificPrefix'] ?? '') ?: null,
            'sufixo'         => trim($nomeData['honorificSuffix'] ?? '') ?: null,
            'apelido'        => trim($pessoa['nicknames'][0]['value'] ?? '') ?: null,
            'profissao'      => trim($org['title'] ?? '') ?: null,
            'empresa'        => trim($org['name'] ?? '') ?: null,
            'departamento'   => trim($org['department'] ?? '') ?: null,
            'tipo_empresa'   => trim($org['type'] ?? '') ?: null,
            'email'          => trim($emails[0]['value'] ?? '') ?: null,
            'email_2'        => trim($emails[1]['value'] ?? '') ?: null,
            'telefone_2'     => $tel2 ?: null,
            'tipo_telefone'  => $fones[0]['type'] ?? null,
            'tipo_telefone_2' => $fones[1]['type'] ?? null,
            'website'        => $website,
            'instagram'      => $instagram,
            'facebook'       => $facebook,
            'linkedin'       => $linkedin,
            'twitter'        => $twitter,
            'tiktok'         => $tiktok,
            'youtube'        => $youtube,
            'foto_url'       => $pessoa['photos'][0]['url'] ?? null,
            'genero'         => $pessoa['genders'][0]['value'] ?? null,
            'observacoes'    => trim($pessoa['biographies'][0]['value'] ?? '') ?: null,
            'endereco'       => trim($end['streetAddress'] ?? '') ?: null,
            'cidade'         => trim($end['city'] ?? '') ?: null,
            'estado'         => trim($end['region'] ?? '') ?: null,
            'cep'            => trim($end['postalCode'] ?? '') ?: null,
            'pais'           => trim($end['country'] ?? '') ?: null,
            'aniversario'    => $aniversario,
        ], fn($v) => $v !== null && $v !== '');
    }

    private function dadosVinculo(array $pessoa): array
    {
        return [
            'google_resource_name' => $pessoa['resourceName'] ?? null,
            'google_etag'          => $pessoa['etag'] ?? null,
            'google_given_name'    => $pessoa['names'][0]['givenName'] ?? null,
        ];
    }

    /**
     * Similaridade entre nomes usando similar_text (built-in PHP, sem dependências).
     * Normaliza antes: lowercase + remove acentos + remove caracteres especiais.
     */
    private function similaridadeNome(string $a, string $b): float
    {
        if (! $a || ! $b) return 0.0;

        $a = $this->normalizarNome($a);
        $b = $this->normalizarNome($b);

        similar_text($a, $b, $percent);
        return round($percent, 2);
    }

    private function normalizarNome(string $nome): string
    {
        $nome = mb_strtolower(trim($nome));
        $nome = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $nome) ?: $nome;
        $nome = preg_replace('/[^a-z0-9 ]/', '', $nome);
        return trim($nome);
    }

    private function limparTelefone(string $telefone): string
    {
        $limpo = preg_replace('/\D/', '', $telefone);

        // Remove DDI 55 se o número tiver mais de 12 dígitos
        if (strlen($limpo) > 12 && str_starts_with($limpo, '55')) {
            $limpo = substr($limpo, 2);
        }

        return $limpo;
    }
}
