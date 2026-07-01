<?php

namespace App\Http\Controllers\Painel;

use App\Http\Controllers\Controller;
use App\Models\Contato;
use App\Models\GoogleToken;
use App\Models\VinculoContatoTenant;
use App\Services\ContatoSyncService;
use App\Services\GoogleService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ContatosController extends Controller
{
    public function view(Request $request): View
    {
        $tenantId = $request->user()->tenant_id;

        $total = VinculoContatoTenant::where('tenant_id', $tenantId)->count();

        $ultimoVinculo = VinculoContatoTenant::where('tenant_id', $tenantId)
            ->latest('created_at')
            ->first();

        $contatoIds = VinculoContatoTenant::where('tenant_id', $tenantId)->pluck('contato_id');
        $contatos   = Contato::whereIn('id', $contatoIds)
            ->orderBy('nome')
            ->paginate(100);

        $googleConectado = GoogleToken::where('tenant_id', $tenantId)->exists();

        return view('contatos.importar', [
            'total'            => $total,
            'ultimo_sync'      => $ultimoVinculo?->created_at?->format('d/m/Y H:i'),
            'contatos'         => $contatos,
            'google_conectado' => $googleConectado,
        ]);
    }

    public function stats(Request $request): JsonResponse
    {
        $tenantId = $request->user()->tenant_id;
        $total    = VinculoContatoTenant::where('tenant_id', $tenantId)->count();

        return response()->json(['total' => $total]);
    }

    public function sincronizarGoogle(Request $request): JsonResponse
    {
        $tenantId = $request->user()->tenant_id;
        $token    = GoogleToken::where('tenant_id', $tenantId)->first();

        if (! $token) {
            return response()->json(['erro' => 'Google não conectado. Vá em Integrações para conectar.'], 422);
        }

        set_time_limit(600);

        $resultado = app(ContatoSyncService::class)->sincronizar($token, $tenantId);

        return response()->json($resultado);
    }

    // Método legado mantido para compatibilidade — substitua por sincronizarGoogle() acima
    private function sincronizarGoogleLegado(Request $request): JsonResponse
    {
        $tenantId = $request->user()->tenant_id;
        $token    = GoogleToken::where('tenant_id', $tenantId)->first();

        if (! $token) {
            return response()->json(['erro' => 'Google não conectado.'], 422);
        }

        $importados = 0;
        $ignorados  = 0;
        $erros      = [];

        foreach ($pessoas as $pessoa) {
            $nomeRaw = $pessoa['names'][0]['displayName'] ?? null;
            if (! $nomeRaw) { $ignorados++; continue; }

            // Remove sufixo de 4 dígitos SOMENTE quando há 2+ palavras antes
            // "João Silva 1234" → "João Silva"  |  "FRETE 0001" → "FRETE 0001" (mantém)
            $nome = trim(preg_replace('/^(.+\s.+)\s\d{4}$/', '$1', $nomeRaw));
            if (! $nome) { $ignorados++; continue; }

            // ── Nome ──────────────────────────────────────────────────────────
            $nomeData   = $pessoa['names'][0] ?? [];
            $nomeDoMeio = trim($nomeData['middleName'] ?? '') ?: null;
            $sobrenome  = trim($nomeData['familyName'] ?? '') ?: null;
            $prefixo    = trim($nomeData['honorificPrefix'] ?? '') ?: null;
            $sufixo     = trim($nomeData['honorificSuffix'] ?? '') ?: null;
            $apelido    = trim($pessoa['nicknames'][0]['value'] ?? '') ?: null;

            // ── Profissional ───────────────────────────────────────────────
            $org         = $pessoa['organizations'][0] ?? [];
            $profissao   = trim($org['title'] ?? '') ?: null;
            $empresa     = trim($org['name'] ?? '') ?: null;
            $departamento = trim($org['department'] ?? '') ?: null;
            $tipoEmpresa = trim($org['type'] ?? '') ?: null;

            // ── Email ──────────────────────────────────────────────────────
            $emails = $pessoa['emailAddresses'] ?? [];
            $email  = trim($emails[0]['value'] ?? '') ?: null;
            $email2 = trim($emails[1]['value'] ?? '') ?: null;

            // ── Online / Redes Sociais ─────────────────────────────────────
            $website   = null;
            $instagram = null;
            $facebook  = null;
            $linkedin  = null;
            $twitter   = null;
            $tiktok    = null;
            $youtube   = null;
            foreach ($pessoa['urls'] ?? [] as $url) {
                $v = trim($url['value'] ?? '');
                if (! $v) continue;
                if (str_contains($v, 'instagram.com')) { $instagram = $v; }
                elseif (str_contains($v, 'facebook.com')) { $facebook = $v; }
                elseif (str_contains($v, 'linkedin.com')) { $linkedin = $v; }
                elseif (str_contains($v, 'twitter.com') || str_contains($v, 'x.com')) { $twitter = $v; }
                elseif (str_contains($v, 'tiktok.com')) { $tiktok = $v; }
                elseif (str_contains($v, 'youtube.com')) { $youtube = $v; }
                elseif (! $website) { $website = $v; }
            }

            // ── Bio / Foto / Gênero ────────────────────────────────────────
            $observacoes = trim($pessoa['biographies'][0]['value'] ?? '') ?: null;
            $fotoUrl     = $pessoa['photos'][0]['url'] ?? null;
            $genero      = $pessoa['genders'][0]['value'] ?? null;

            // ── Endereço ───────────────────────────────────────────────────
            $end      = $pessoa['addresses'][0] ?? [];
            $endereco = trim($end['streetAddress'] ?? '') ?: null;
            $cidade   = trim($end['city'] ?? '') ?: null;
            $estado   = trim($end['region'] ?? '') ?: null;
            $cep      = trim($end['postalCode'] ?? '') ?: null;
            $pais     = trim($end['country'] ?? '') ?: null;

            // ── Aniversário ────────────────────────────────────────────────
            $aniversario = null;
            if (! empty($pessoa['birthdays'][0]['date'])) {
                $d = $pessoa['birthdays'][0]['date'];
                if (! empty($d['year']) && ! empty($d['month']) && ! empty($d['day'])) {
                    $aniversario = sprintf('%04d-%02d-%02d', $d['year'], $d['month'], $d['day']);
                }
            }

            // ── Telefones ──────────────────────────────────────────────────
            $fones = $pessoa['phoneNumbers'] ?? [];
            $telefones = array_values(array_filter(
                array_map(fn($p) => $this->limparTelefone($p['value'] ?? ''), $fones)
            ));
            $tipoTelefone  = $fones[0]['type'] ?? null;
            $tipoTelefone2 = $fones[1]['type'] ?? null;

            if (empty($telefones)) { $ignorados++; continue; }

            foreach ($telefones as $idx => $telefone) {
                try {
                    DB::transaction(function () use (
                        $telefone, $idx, $fones,
                        $nome, $nomeDoMeio, $sobrenome, $prefixo, $sufixo, $apelido,
                        $profissao, $empresa, $departamento, $tipoEmpresa,
                        $email, $email2,
                        $website, $instagram, $facebook, $linkedin, $twitter, $tiktok, $youtube,
                        $observacoes, $fotoUrl, $genero,
                        $endereco, $cidade, $estado, $cep, $pais,
                        $aniversario, $tipoTelefone, $tipoTelefone2,
                        $tenantId, &$importados, $pessoa
                    ) {
                        // Telefone extra do mesmo contato no Google → guardado no campo telefone_2
                        $tel2 = ($idx === 0 && count($fones) > 1)
                            ? $this->limparTelefone($fones[1]['value'] ?? '')
                            : null;

                        $dados = array_filter([
                            'nome'          => $nome,
                            'nome_do_meio'  => $nomeDoMeio,
                            'sobrenome'     => $sobrenome,
                            'prefixo'       => $prefixo,
                            'sufixo'        => $sufixo,
                            'apelido'       => $apelido,
                            'profissao'     => $profissao,
                            'empresa'       => $empresa,
                            'departamento'  => $departamento,
                            'tipo_empresa'  => $tipoEmpresa,
                            'email'         => $email,
                            'email_2'       => $email2,
                            'telefone_2'    => $tel2,
                            'tipo_telefone' => $tipoTelefone,
                            'tipo_telefone_2' => $tipoTelefone2,
                            'website'       => $website,
                            'instagram'     => $instagram,
                            'facebook'      => $facebook,
                            'linkedin'      => $linkedin,
                            'twitter'       => $twitter,
                            'tiktok'        => $tiktok,
                            'youtube'       => $youtube,
                            'foto_url'      => $fotoUrl,
                            'genero'        => $genero,
                            'observacoes'   => $observacoes,
                            'endereco'      => $endereco,
                            'cidade'        => $cidade,
                            'estado'        => $estado,
                            'cep'           => $cep,
                            'pais'          => $pais,
                            'aniversario'   => $aniversario,
                            'origem'        => 'agenda_google',
                            'opt_out'       => false,
                        ], fn($v) => $v !== null && $v !== '');

                        $contato = Contato::firstOrCreate(['telefone' => $telefone], $dados);

                        // Atualiza campos vazios se o Google trouxer dados novos
                        $atualizar = [];
                        foreach ($dados as $campo => $valor) {
                            if (in_array($campo, ['origem', 'opt_out'])) continue;
                            if (empty($contato->$campo) && $valor) {
                                $atualizar[$campo] = $valor;
                            }
                        }
                        if ($atualizar) $contato->update($atualizar);

                        VinculoContatoTenant::updateOrCreate(
                            ['contato_id' => $contato->id, 'tenant_id' => $tenantId],
                            [
                                'google_resource_name' => $pessoa['resourceName'] ?? null,
                                'google_etag'          => $pessoa['etag'] ?? null,
                                'google_given_name'    => $pessoa['names'][0]['givenName'] ?? null,
                            ]
                        );

                        $importados++;
                    });
                } catch (\Exception $e) {
                    $erros[] = "Tel {$telefone}: " . $e->getMessage();
                }
            }
        }

        return response()->json([
            'importados' => $importados,
            'ignorados'  => $ignorados,
            'erros'      => array_slice($erros, 0, 10),
        ]);
    }

    public function atualizarGoogleSobrenome(Request $request): JsonResponse
    {
        set_time_limit(300);

        $tenantId = $request->user()->tenant_id;
        $token    = GoogleToken::where('tenant_id', $tenantId)->first();

        if (! $token) {
            return response()->json(['erro' => 'Google não conectado.'], 422);
        }

        $google   = app(GoogleService::class);
        $vinculos = VinculoContatoTenant::with('contato')
            ->where('tenant_id', $tenantId)
            ->whereNotNull('google_resource_name')
            ->whereNotNull('google_etag')
            ->get();

        $atualizados = 0;
        $falhas      = 0;

        foreach ($vinculos as $vinculo) {
            if (! $vinculo->contato) continue;

            $givenName  = $vinculo->google_given_name ?? $vinculo->contato->nome;
            $familyName = (string) $vinculo->contato->id;

            $ok = $google->atualizarNomeContato(
                $token,
                $vinculo->google_resource_name,
                $vinculo->google_etag,
                $givenName,
                $familyName
            );

            $ok ? $atualizados++ : $falhas++;

            usleep(50000); // 50ms entre requests
        }

        return response()->json([
            'atualizados' => $atualizados,
            'falhas'      => $falhas,
            'total'       => $vinculos->count(),
        ]);
    }

    public function importar(Request $request): JsonResponse
    {
        $request->validate([
            'arquivo' => 'required|file|mimes:csv,txt|max:5120',
        ]);

        $tenantId = $request->user()->tenant_id;
        $arquivo  = $request->file('arquivo');
        $handle   = fopen($arquivo->getRealPath(), 'r');

        $bom = fread($handle, 3);
        if ($bom !== "\xEF\xBB\xBF") {
            rewind($handle);
        }

        $cabecalho = fgetcsv($handle, 0, ',', '"', '');
        if (! $cabecalho) {
            return response()->json(['erro' => 'Arquivo CSV vazio ou inválido.'], 422);
        }

        $cabecalho = array_map(fn($c) => mb_strtolower(trim($c)), $cabecalho);

        $colNome      = $this->encontrarColuna($cabecalho, ['name', 'nome', 'given name + family name']);
        $colNomeProp  = $this->encontrarColuna($cabecalho, ['given name', 'primeiro nome', 'first name']);
        $colSobrenome = $this->encontrarColuna($cabecalho, ['family name', 'sobrenome', 'last name']);
        $colCargo       = $this->encontrarColuna($cabecalho, ['organization 1 - title', 'cargo', 'title', 'profissao', 'profissão']);
        $colEmpresa     = $this->encontrarColuna($cabecalho, ['organization 1 - name', 'empresa', 'company', 'organization 1 - name']);
        $colEmail       = $this->encontrarColuna($cabecalho, ['e-mail 1 - value', 'email 1 - value', 'email', 'e-mail']);
        $colWebsite     = $this->encontrarColuna($cabecalho, ['website 1 - value', 'website', 'url']);
        $colObs         = $this->encontrarColuna($cabecalho, ['notes', 'notas', 'observacoes', 'observações', 'biography']);
        $colNomeMeio    = $this->encontrarColuna($cabecalho, ['middle name', 'nome do meio', 'nome_do_meio']);
        $colEndereco    = $this->encontrarColuna($cabecalho, ['address 1 - street', 'endereco', 'endereço', 'street']);
        $colCidade      = $this->encontrarColuna($cabecalho, ['address 1 - city', 'cidade', 'city']);
        $colEstado      = $this->encontrarColuna($cabecalho, ['address 1 - region', 'estado', 'region', 'uf']);
        $colCep         = $this->encontrarColuna($cabecalho, ['address 1 - postal code', 'cep', 'postal code', 'zip']);
        $colPais        = $this->encontrarColuna($cabecalho, ['address 1 - country', 'pais', 'país', 'country']);
        $colAniversario = $this->encontrarColuna($cabecalho, ['birthday', 'aniversario', 'aniversário', 'nascimento']);

        $colTelefones = array_values(array_filter(
            array_keys($cabecalho),
            fn($i) => str_contains($cabecalho[$i], 'phone') && str_contains($cabecalho[$i], 'value')
        ));

        if (empty($colTelefones)) {
            $colTelefones = array_values(array_filter(
                array_keys($cabecalho),
                fn($i) => str_contains($cabecalho[$i], 'telefone') || str_contains($cabecalho[$i], 'celular') || $cabecalho[$i] === 'phone'
            ));
        }

        $importados = 0;
        $ignorados  = 0;
        $erros      = [];

        while (($linha = fgetcsv($handle, 0, ',', '"', '')) !== false) {
            if (count($linha) < 2) continue;

            $nomeRaw = null;
            if ($colNome !== null && ! empty(trim($linha[$colNome] ?? ''))) {
                $nomeRaw = trim($linha[$colNome]);
            } elseif ($colNomeProp !== null || $colSobrenome !== null) {
                $partes  = array_filter([
                    trim($linha[$colNomeProp] ?? ''),
                    trim($linha[$colSobrenome] ?? ''),
                ]);
                $nomeRaw = implode(' ', $partes) ?: null;
            }

            if (! $nomeRaw) continue;

            // Remove sufixo de 4 dígitos
            $nome = trim(preg_replace('/\s\d{4}$/', '', $nomeRaw));
            if (! $nome) continue;

            $profissao  = $colCargo     !== null ? (trim($linha[$colCargo] ?? '')     ?: null) : null;
            $empresa    = $colEmpresa   !== null ? (trim($linha[$colEmpresa] ?? '')   ?: null) : null;
            $email      = $colEmail     !== null ? (trim($linha[$colEmail] ?? '')     ?: null) : null;
            $website    = $colWebsite   !== null ? (trim($linha[$colWebsite] ?? '')   ?: null) : null;
            $observacoes = $colObs      !== null ? (trim($linha[$colObs] ?? '')       ?: null) : null;
            $nomeDoMeio = $colNomeMeio  !== null ? (trim($linha[$colNomeMeio] ?? '')  ?: null) : null;
            $endereco   = $colEndereco  !== null ? (trim($linha[$colEndereco] ?? '')  ?: null) : null;
            $cidade     = $colCidade    !== null ? (trim($linha[$colCidade] ?? '')    ?: null) : null;
            $estado     = $colEstado    !== null ? (trim($linha[$colEstado] ?? '')    ?: null) : null;
            $cep        = $colCep       !== null ? (trim($linha[$colCep] ?? '')       ?: null) : null;
            $pais       = $colPais      !== null ? (trim($linha[$colPais] ?? '')      ?: null) : null;

            // Aniversário: aceita "YYYY-MM-DD", "MM/DD/YYYY" e "--MM-DD"
            $aniversario = null;
            if ($colAniversario !== null && ! empty(trim($linha[$colAniversario] ?? ''))) {
                $raw = trim($linha[$colAniversario]);
                if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $raw)) {
                    $aniversario = $raw;
                } elseif (preg_match('/^(\d{2})\/(\d{2})\/(\d{4})$/', $raw, $m)) {
                    $aniversario = "{$m[3]}-{$m[1]}-{$m[2]}";
                }
            }

            $telefones = [];
            foreach ($colTelefones as $idx) {
                $tel = $this->limparTelefone($linha[$idx] ?? '');
                if ($tel) $telefones[] = $tel;
            }

            if (empty($telefones)) { $ignorados++; continue; }

            foreach ($telefones as $telefone) {
                try {
                    DB::transaction(function () use (
                        $telefone, $nome, $nomeDoMeio, $profissao, $empresa, $email,
                        $website, $observacoes, $endereco, $cidade, $estado, $cep, $pais,
                        $aniversario, $tenantId, &$importados
                    ) {
                        $dados = array_filter([
                            'nome'        => $nome,
                            'nome_do_meio' => $nomeDoMeio,
                            'profissao'   => $profissao,
                            'empresa'     => $empresa,
                            'email'       => $email,
                            'website'     => $website,
                            'observacoes' => $observacoes,
                            'endereco'    => $endereco,
                            'cidade'      => $cidade,
                            'estado'      => $estado,
                            'cep'         => $cep,
                            'pais'        => $pais,
                            'aniversario' => $aniversario,
                            'origem'      => 'agenda_google',
                            'opt_out'     => false,
                        ], fn($v) => $v !== null && $v !== '');

                        $contato = Contato::firstOrCreate(['telefone' => $telefone], $dados);

                        // Preenche campos vazios com dados do CSV
                        $atualizar = [];
                        foreach ($dados as $campo => $valor) {
                            if (in_array($campo, ['origem', 'opt_out'])) continue;
                            if (empty($contato->$campo) && $valor) {
                                $atualizar[$campo] = $valor;
                            }
                        }
                        if ($atualizar) $contato->update($atualizar);

                        VinculoContatoTenant::firstOrCreate([
                            'contato_id' => $contato->id,
                            'tenant_id'  => $tenantId,
                        ]);

                        $importados++;
                    });
                } catch (\Exception $e) {
                    $erros[] = "Tel {$telefone}: " . $e->getMessage();
                }
            }
        }

        fclose($handle);

        return response()->json([
            'importados' => $importados,
            'ignorados'  => $ignorados,
            'erros'      => array_slice($erros, 0, 10),
        ]);
    }

    public function atualizarContato(Request $request, Contato $contato): JsonResponse
    {
        $request->validate([
            'nome'        => 'sometimes|string|max:200',
            'email'       => 'sometimes|nullable|email|max:200',
            'profissao'   => 'sometimes|nullable|string|max:200',
            'empresa'     => 'sometimes|nullable|string|max:200',
            'observacoes' => 'sometimes|nullable|string',
            'cidade'      => 'sometimes|nullable|string|max:100',
            'estado'      => 'sometimes|nullable|string|max:50',
            'tipo'        => 'sometimes|nullable|string|max:30',
            'score'       => 'sometimes|nullable|integer|min:0|max:100',
        ]);

        $tenantId = $request->user()->tenant_id;
        $dados    = $request->only(['nome', 'email', 'profissao', 'empresa', 'observacoes', 'cidade', 'estado', 'tipo', 'score']);

        // Regra de governança: nome editado por parceiro/SDR vai para auditoria
        // se o master já tiver um nome diferente
        if (
            isset($dados['nome']) &&
            $contato->nome &&
            strtolower(trim($dados['nome'])) !== strtolower(trim($contato->nome))
        ) {
            VinculoContatoTenant::where('contato_id', $contato->id)
                ->where('tenant_id', $tenantId)
                ->update([
                    'nome_sugerido'      => $dados['nome'],
                    'auditoria_pendente' => true,
                ]);

            $nomeLocal = $dados['nome'];
            unset($dados['nome']); // master intacto

            $contato->update($dados); // aplica outros campos (email, profissao, etc.)

            return response()->json([
                'ok'         => true,
                'auditoria'  => true,
                'nome_local' => $nomeLocal,
                'mensagem'   => 'Nome enviado para auditoria. Os demais dados foram salvos.',
            ]);
        }

        $contato->update($dados);

        return response()->json(['ok' => true, 'auditoria' => false, 'contato' => $contato->fresh()]);
    }

    private function encontrarColuna(array $cabecalho, array $opcoes): ?int
    {
        foreach ($opcoes as $opcao) {
            $idx = array_search($opcao, $cabecalho, true);
            if ($idx !== false) return $idx;
        }
        return null;
    }

    private function limparTelefone(string $raw): ?string
    {
        if (! $raw) return null;

        $digits = preg_replace('/\D/', '', $raw);

        if (strlen($digits) < 8) return null;

        if (str_starts_with($digits, '55') && strlen($digits) >= 12) {
            $digits = substr($digits, 2);
        }

        if (strlen($digits) < 10 || strlen($digits) > 11) return null;

        return $digits;
    }
}
