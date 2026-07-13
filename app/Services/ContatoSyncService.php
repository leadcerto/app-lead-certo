<?php

namespace App\Services;

use App\Models\Contato;
use App\Models\ContatoPendente;
use App\Models\EtiquetaGoogleGrupo;
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

        $nome = $this->limparNome($nomeRaw);
        if (! $nome) { $resultado['ignorados']++; return; }

        $dados = $this->extrairDados($pessoa, $nome);

        // Detecta tipo_contato a partir dos grupos do Google (fornecedor, parceiro, etc.)
        $tipoDetectado = $this->detectarTipoContato($pessoa['memberships'] ?? [], $tenantId);
        if ($tipoDetectado) {
            $dados['tipo_contato'] = $tipoDetectado;
        }

        $telefones = array_values(array_filter(
            array_map(fn($p) => $this->limparTelefone($p['value'] ?? ''), $pessoa['phoneNumbers'] ?? [])
        ));

        if (empty($telefones)) { $resultado['ignorados']++; return; }

        foreach ($telefones as $idx => $telefone) {
            DB::transaction(function () use (
                $telefone, $idx, $nome, $dados, $tenantId, $pessoa, $tipoDetectado, &$resultado
            ) {
                $existente = Contato::where('telefone', $telefone)->first();

                if (! $existente) {
                    // ── Contato novo ─────────────────────────────────────────
                    // O Google as vezes lista o mesmo contato duas vezes (cartoes
                    // duplicados na propria agenda) ou o webhook do WhatsApp pode
                    // estar criando esse telefone no mesmo instante — tolera a
                    // corrida em vez de deixar a excecao de chave duplicada subir
                    // e abortar o sync inteiro daquele contato.
                    try {
                        $contato = Contato::create(array_merge($dados, [
                            'telefone' => $telefone,
                            'origem'   => 'agenda_google',
                            'opt_out'  => false,
                        ]));
                    } catch (\Illuminate\Database\UniqueConstraintViolationException $e) {
                        $contato = Contato::where('telefone', $telefone)->first();
                        if (! $contato) {
                            throw $e;
                        }
                    }

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
                        // Tipo detectado do Google sempre sobrepõe 'lead' (categoria padrão)
                        if ($tipoDetectado && ($existente->tipo_contato === 'lead' || ! $existente->tipo_contato)) {
                            $atualizar['tipo_contato'] = $tipoDetectado;
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

    private function limparNome(string $nomeRaw): string
    {
        // 1. Remove números de 3-6 dígitos isolados que aparecem após letras
        //    (índices de agenda como " 7631" — não afeta siglas como "3M" no início)
        $nome = preg_replace('/(?<=[^\d\s])\s+\d{3,6}(?=\s|$)/u', '', $nomeRaw);

        // 2. Remove palavras duplicadas consecutivas (ex: "Kamily Kamily" → "Kamily")
        $nome = preg_replace('/\b(\w+)\s+\1\b/iu', '$1', $nome ?? $nomeRaw);

        // 3. Title case: primeira letra de cada palavra em maiúsculo
        $nome = mb_convert_case(trim($nome ?? $nomeRaw), MB_CASE_TITLE, 'UTF-8');

        // 4. Remove espaços múltiplos
        $nome = trim(preg_replace('/\s{2,}/', ' ', $nome));

        return $nome ?: $nomeRaw;
    }

    private function limparTelefone(string $telefone): string
    {
        $limpo = preg_replace('/\D/', '', $telefone);

        // Números BR: 55 + 2 DDD + 9 dígitos = 13 dígitos (formato correto)
        // Remove 55 apenas se resultar em > 13 dígitos (improvável, mas previne overflow)
        if (strlen($limpo) > 13 && str_starts_with($limpo, '55')) {
            $limpo = substr($limpo, 2);
        }

        // 11 dígitos sem prefixo: 2 DDD + 9 celular brasileiro → adiciona 55
        if (strlen($limpo) === 11 && ! str_starts_with($limpo, '55')) {
            $limpo = '55' . $limpo;
        }

        // Rejeita números com menos de 10 dígitos (inválidos — só DDI, campo vazio, etc.)
        if (strlen($limpo) < 10) {
            return '';
        }

        return $limpo;
    }

    /**
     * Detecta tipo_contato a partir dos grupos Google do contato.
     * Busca os grupos na tabela etiqueta_google_grupos para o tenant e retorna o slug da etiqueta.
     * Retorna null quando o contato não pertence a nenhum grupo mapeado (será tratado como 'lead').
     */
    private function detectarTipoContato(array $memberships, int $tenantId): ?string
    {
        if (empty($memberships)) return null;

        $tiposValidos = ['fornecedor', 'parceiro', 'colaborador', 'pessoal', 'cliente'];

        $groupResourceNames = array_filter(array_map(
            fn($m) => $m['contactGroupMembership']['contactGroupResourceName'] ?? null,
            $memberships
        ));

        if (empty($groupResourceNames)) return null;

        $etiqueta = EtiquetaGoogleGrupo::with('etiqueta')
            ->where('tenant_id', $tenantId)
            ->whereIn('google_group_resource_name', array_values($groupResourceNames))
            ->whereHas('etiqueta', fn($q) => $q->whereIn('slug', $tiposValidos)->where('ativo', true))
            ->first();

        return $etiqueta?->etiqueta?->slug;
    }
}
