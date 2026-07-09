<?php

namespace App\Http\Controllers\Painel;

use App\Http\Controllers\Controller;
use App\Models\AuditoriaContato;
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

        $busca = trim($request->input('q', ''));

        $contatoIds = VinculoContatoTenant::where('tenant_id', $tenantId)->pluck('contato_id');

        $query = Contato::whereIn('id', $contatoIds);

        if ($busca !== '') {
            // Com busca ativa: mostra qualquer contato que bata, incluindo "Sem Nome"
            $like           = "%{$busca}%";
            $somenteDigitos = preg_replace('/\D/', '', $busca);
            $likeFone       = $somenteDigitos !== '' ? "%{$somenteDigitos}%" : $like;

            $query->where(function ($q) use ($like, $likeFone) {
                $q->where('nome', 'like', $like)
                  ->orWhere('telefone', 'like', $likeFone)
                  ->orWhere('email', 'like', $like)
                  ->orWhere('cidade', 'like', $like)
                  ->orWhere('endereco', 'like', $like);
            });
        } else {
            // Sem busca: oculta "Sem Nome" e nomes que são apenas números
            $query->whereRaw("LOWER(TRIM(COALESCE(nome,''))) NOT IN ('','sem nome','sem_nome')")
                  ->whereRaw('nome != telefone')
                  ->whereRaw("nome NOT REGEXP '^[0-9 +()\\\\-]+$'");
        }

        $contatos = $query->orderByDesc('id')->paginate(100)->withQueryString();

        $googleConectado = GoogleToken::where('tenant_id', $tenantId)->exists();

        return view('contatos.importar', [
            'total'            => $total,
            'ultimo_sync'      => $ultimoVinculo?->created_at?->format('d/m/Y H:i'),
            'contatos'         => $contatos,
            'google_conectado' => $googleConectado,
            'busca'            => $busca,
        ]);
    }

    // ── Auditoria de Contatos ──────────────────────────────────────────────────

    public function auditoriaContatos(Request $request): View
    {
        $tenantId = $request->user()->tenant_id;
        $filtro   = $request->input('filtro', 'pendente');
        $tipoFiltro = $request->input('tipo');

        // IDs de contatos vinculados a este tenant
        $contatoIds = VinculoContatoTenant::where('tenant_id', $tenantId)->pluck('contato_id');

        $registros = AuditoriaContato::with('contato')
            ->whereIn('contato_id', $contatoIds)
            ->when($filtro !== 'todos', fn($q) => $q->where('status', $filtro))
            ->when($tipoFiltro, fn($q) => $q->where('tipo', $tipoFiltro))
            ->orderByRaw("FIELD(status,'pendente','ignorado','resolvido')")
            ->orderBy('id', 'desc')
            ->paginate(50)
            ->withQueryString();

        $contagens = AuditoriaContato::whereIn('contato_id', $contatoIds)
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        // Breakdown por tipo (apenas pendentes) — inclui auditoria_id para grupos com 1 registro
        $breakdown = AuditoriaContato::whereIn('contato_id', $contatoIds)
            ->where('status', 'pendente')
            ->selectRaw('tipo, observacao, count(*) as total, MIN(id) as auditoria_id, MIN(contato_id) as primeiro_contato_id')
            ->groupBy('tipo', 'observacao')
            ->orderByDesc('total')
            ->get();

        // Para grupos com 1 registro, carrega o contato para mostrar "Editar" diretamente
        $breakdown->each(function ($grupo) {
            if ($grupo->total === 1) {
                $auditoria = AuditoriaContato::with('contato')->find($grupo->auditoria_id);
                $grupo->contato        = $auditoria?->contato;
                $grupo->campo          = $auditoria?->campo;
                $grupo->valor_sugerido = $auditoria?->valor_sugerido;
            }
        });

        return view('contatos.auditoria', [
            'registros'  => $registros,
            'filtro'     => $filtro,
            'tipoFiltro' => $tipoFiltro,
            'contagens'  => $contagens,
            'breakdown'  => $breakdown,
        ]);
    }

    public function resolverAuditoria(Request $request, int $id): JsonResponse
    {
        $request->validate([
            'valor_novo' => 'required|string|max:255',
        ]);

        $tenantId   = $request->user()->tenant_id;
        $contatoIds = VinculoContatoTenant::where('tenant_id', $tenantId)->pluck('contato_id');
        $auditoria  = AuditoriaContato::whereIn('contato_id', $contatoIds)->findOrFail($id);
        $contato    = $auditoria->contato;

        if (! $contato) {
            return response()->json(['erro' => 'Contato não encontrado.'], 404);
        }

        $campo = $auditoria->campo;
        $contato->update([$campo => $request->input('valor_novo')]);

        $auditoria->update([
            'status'       => 'resolvido',
            'resolvido_em' => now(),
        ]);

        return response()->json(['ok' => true, 'contato_id' => $contato->id]);
    }

    public function ignorarAuditoria(Request $request, int $id): JsonResponse
    {
        $tenantId   = $request->user()->tenant_id;
        $contatoIds = VinculoContatoTenant::where('tenant_id', $tenantId)->pluck('contato_id');
        $auditoria  = AuditoriaContato::whereIn('contato_id', $contatoIds)->findOrFail($id);
        $auditoria->update(['status' => 'ignorado', 'resolvido_em' => now()]);

        return response()->json(['ok' => true]);
    }

    public function bulkIgnorarAuditoria(Request $request): JsonResponse
    {
        $tenantId   = $request->user()->tenant_id;
        $contatoIds = VinculoContatoTenant::where('tenant_id', $tenantId)->pluck('contato_id');

        $query = AuditoriaContato::whereIn('contato_id', $contatoIds)->where('status', 'pendente');

        if ($request->filled('ids')) {
            $query->whereIn('id', $request->input('ids'));
        } elseif ($request->filled('tipo')) {
            $query->where('tipo', $request->input('tipo'));
            if ($request->filled('observacao')) {
                $query->where('observacao', $request->input('observacao'));
            }
        } else {
            return response()->json(['erro' => 'Informe ids ou tipo.'], 422);
        }

        $count = $query->update(['status' => 'ignorado', 'resolvido_em' => now()]);

        return response()->json(['ok' => true, 'ignorados' => $count]);
    }

    public function bulkResolverAuditoria(Request $request): JsonResponse
    {
        $tenantId   = $request->user()->tenant_id;
        $contatoIds = VinculoContatoTenant::where('tenant_id', $tenantId)->pluck('contato_id');

        $query = AuditoriaContato::with('contato')
            ->whereIn('contato_id', $contatoIds)
            ->where('status', 'pendente');

        if ($request->filled('ids')) {
            $query->whereIn('id', $request->input('ids'));
        } elseif ($request->filled('tipo')) {
            $query->where('tipo', $request->input('tipo'));
            if ($request->filled('observacao')) {
                $query->where('observacao', $request->input('observacao'));
            }
        } else {
            return response()->json(['erro' => 'Informe ids ou tipo.'], 422);
        }

        $count = 0;
        $query->chunk(200, function ($registros) use (&$count) {
            foreach ($registros as $auditoria) {
                // Mantém o valor atual do campo como "resolvido" (número internacional válido)
                $auditoria->update(['status' => 'resolvido', 'resolvido_em' => now()]);
                $count++;
            }
        });

        return response()->json(['ok' => true, 'resolvidos' => $count]);
    }

    // ── Marcadores ─────────────────────────────────────────────────────────────

    public function marcadores(Request $request): View
    {
        $tenantId = $request->user()->tenant_id;
        $token    = GoogleToken::where('tenant_id', $tenantId)->first();

        // Busca grupos do Google via API se houver token
        $grupos = [];
        if ($token) {
            try {
                $google     = app(GoogleService::class);
                $validToken = $google->tokenValido($token);
                if ($validToken) {
                    $res = \Illuminate\Support\Facades\Http::withToken($validToken->access_token)
                        ->get('https://people.googleapis.com/v1/contactGroups', ['pageSize' => 200]);
                    if ($res->successful()) {
                        foreach ($res->json('contactGroups') ?? [] as $g) {
                            if (in_array($g['groupType'] ?? '', ['USER_CONTACT_GROUP'])) {
                                $grupos[] = [
                                    'resourceName' => $g['resourceName'],
                                    'name'         => $g['name'],
                                    'memberCount'  => $g['memberCount'] ?? 0,
                                ];
                            }
                        }
                    }
                }
            } catch (\Throwable) {
                // Continua sem grupos
            }
        }

        return view('contatos.marcadores', [
            'grupos'           => $grupos,
            'google_conectado' => (bool) $token,
        ]);
    }

    public function criarMarcador(Request $request): JsonResponse
    {
        $request->validate(['nome' => 'required|string|max:100']);

        $tenantId = $request->user()->tenant_id;
        $token    = GoogleToken::where('tenant_id', $tenantId)->first();

        if (! $token) {
            return response()->json(['erro' => 'Google não conectado.'], 422);
        }

        $google     = app(GoogleService::class);
        $validToken = $google->tokenValido($token);
        if (! $validToken) {
            return response()->json(['erro' => 'Token Google inválido. Reconecte em Integrações.'], 422);
        }

        $resourceName = $google->criarGrupoContato($validToken, $request->input('nome'));
        if (! $resourceName) {
            return response()->json(['erro' => 'Não foi possível criar o marcador no Google.'], 500);
        }

        return response()->json(['ok' => true, 'resourceName' => $resourceName]);
    }

    public function desativarContato(Request $request, Contato $contato): JsonResponse
    {
        $tenantId = $request->user()->tenant_id;

        $vinculo = VinculoContatoTenant::where('contato_id', $contato->id)
            ->where('tenant_id', $tenantId)
            ->first();

        if (! $vinculo) {
            return response()->json(['erro' => 'Contato não encontrado.'], 404);
        }

        $vinculo->update([
            'ativo'         => false,
            'desativado_em' => now(),
        ]);

        return response()->json(['ok' => true]);
    }

    public function criarContato(Request $request): JsonResponse
    {
        $request->validate([
            'nome'     => 'required|string|max:200',
            'telefone' => 'required|string|max:30',
        ]);

        $tenantId = $request->user()->tenant_id;
        $telefone = preg_replace('/\D/', '', $request->input('telefone'));

        // Normaliza para E.164 brasileiro: sem prefixo 55 → adiciona
        if (strlen($telefone) >= 10 && strlen($telefone) <= 11) {
            $telefone = '55' . $telefone;
        }

        // Celular antigo sem o 9: 55 + DDD + 8 dígitos começando com 6/7/8
        if (strlen($telefone) === 12 && preg_match('/^55\d{2}[678]/', $telefone)) {
            $telefone = substr($telefone, 0, 4) . '9' . substr($telefone, 4);
        }

        if (strlen($telefone) < 12) {
            return response()->json(['erro' => 'Telefone inválido.'], 422);
        }

        // Busca global — mesmo número em outro franqueado não duplica
        $contato = Contato::withoutGlobalScopes()->where('telefone', $telefone)->first();

        if ($contato) {
            // Já existe globalmente — verifica se já está vinculado a este tenant
            $jaVinculado = VinculoContatoTenant::where('tenant_id', $tenantId)
                ->where('contato_id', $contato->id)
                ->exists();

            if ($jaVinculado) {
                return response()->json([
                    'erro'       => 'Este número já está cadastrado na sua lista.',
                    'contato_id' => $contato->id,
                    'existente'  => true,
                ], 409);
            }

            // Existe globalmente mas não vinculado — vincula
            VinculoContatoTenant::create(['tenant_id' => $tenantId, 'contato_id' => $contato->id]);

            return response()->json(['ok' => true, 'contato_id' => $contato->id, 'vinculado' => true]);
        }

        // Não existe — cria globalmente e vincula
        $contato = Contato::create([
            'nome'     => $request->input('nome'),
            'telefone' => $telefone,
            'origem'   => 'manual',
        ]);

        VinculoContatoTenant::create(['tenant_id' => $tenantId, 'contato_id' => $contato->id]);

        return response()->json(['ok' => true, 'contato_id' => $contato->id, 'vinculado' => false]);
    }

    public function excluirContatoDefinitivo(Request $request, Contato $contato): JsonResponse
    {
        $tenantId = $request->user()->tenant_id;

        $vinculoAtual = VinculoContatoTenant::where('contato_id', $contato->id)
            ->where('tenant_id', $tenantId)
            ->exists();

        if (! $vinculoAtual) {
            return response()->json(['erro' => 'Contato não encontrado.'], 404);
        }

        $outrosVinculos = VinculoContatoTenant::where('contato_id', $contato->id)
            ->where('tenant_id', '!=', $tenantId)
            ->exists();

        if ($outrosVinculos) {
            // Contato compartilhado — remove apenas o vínculo deste tenant
            VinculoContatoTenant::where('contato_id', $contato->id)
                ->where('tenant_id', $tenantId)
                ->delete();
        } else {
            // Único tenant — exclui definitivamente
            DB::table('auditoria_contatos')->where('contato_id', $contato->id)->delete();
            DB::table('vinculos_contato_tenant')->where('contato_id', $contato->id)->delete();
            $contato->forceDelete();
        }

        return response()->json(['ok' => true]);
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

        // Processa em background para não atingir timeout do nginx
        dispatch(function () use ($token, $tenantId) {
            app(ContatoSyncService::class)->sincronizar($token, $tenantId);
        })->onQueue('default');

        return response()->json([
            'importados'    => 0,
            'ignorados'     => 0,
            'em_progresso'  => true,
        ]);
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

    public function historicoContato(Request $request, Contato $contato): JsonResponse
    {
        $tenantId = $request->user()->tenant_id;

        $tickets = \App\Models\TicketAtendimento::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('contato_id', $contato->id)
            ->withCount(['mensagens as total_msgs', 'mensagens as msgs_lead' => function ($q) {
                $q->where('remetente', 'lead');
            }])
            ->orderByDesc('aberto_em')
            ->get(['id','coluna_kanban','status','origem','tag_desfecho','aberto_em','encerrado_em','agente_responsavel']);

        $colunaLabel = [
            'lead_novo'           => 'Novo',
            'em_atendimento'      => 'Em Atendimento',
            'aguardando_orcamento'=> 'Ag. Orçamento',
            'aguardando_lead'     => 'Ag. Lead',
            'pagamento'           => 'Pagamento',
            'servico_agendado'    => 'Serv. Agendado',
            'encerrado'           => 'Encerrado',
            'outros'              => 'Outros',
        ];

        $resultado = $tickets->map(fn($t) => [
            'id'           => $t->id,
            'coluna'       => $colunaLabel[$t->coluna_kanban] ?? $t->coluna_kanban,
            'status'       => $t->status,
            'origem'       => $t->origem,
            'tag_desfecho' => $t->tag_desfecho,
            'agente'       => $t->agente_responsavel,
            'total_msgs'   => $t->total_msgs,
            'msgs_lead'    => $t->msgs_lead,
            'aberto_em'    => $t->aberto_em?->format('d/m/Y H:i'),
            'encerrado_em' => $t->encerrado_em?->format('d/m/Y H:i'),
        ]);

        return response()->json($resultado);
    }

    public function showContato(Request $request, Contato $contato): JsonResponse
    {
        return response()->json($contato->makeVisible([
            'cpf','rg','cnpj','razao_social','nome_fantasia','genero','estado_civil',
            'aniversario','endereco','cep','pais','telefone_2','email_2',
            'instagram','facebook','linkedin','twitter','tiktok','website',
            'observacoes','score','tags','origem','tipo_contato','opt_out',
            'status_validacao','created_at','nome_do_meio','sobrenome',
            'departamento','empresa','profissao','cidade','estado',
        ]));
    }

    public function atualizarContato(Request $request, Contato $contato): JsonResponse
    {
        $request->validate([
            'nome'           => 'sometimes|string|max:200',
            'email'          => 'sometimes|nullable|email|max:200',
            'email_2'        => 'sometimes|nullable|email|max:200',
            'telefone_2'     => 'sometimes|nullable|string|max:20',
            'profissao'      => 'sometimes|nullable|string|max:200',
            'empresa'        => 'sometimes|nullable|string|max:200',
            'departamento'   => 'sometimes|nullable|string|max:200',
            'observacoes'    => 'sometimes|nullable|string',
            'endereco'       => 'sometimes|nullable|string|max:300',
            'cidade'         => 'sometimes|nullable|string|max:100',
            'estado'         => 'sometimes|nullable|string|max:50',
            'cep'            => 'sometimes|nullable|string|max:20',
            'pais'           => 'sometimes|nullable|string|max:50',
            'tipo'           => 'sometimes|nullable|string|max:30',
            'tipo_contato'   => 'sometimes|nullable|in:lead,cliente,fornecedor,parceiro,pessoal',
            'score'          => 'sometimes|nullable|integer|min:0|max:100',
            'genero'         => 'sometimes|nullable|string|max:30',
            'estado_civil'   => 'sometimes|nullable|string|max:30',
            'aniversario'    => 'sometimes|nullable|date',
            'cpf'            => 'sometimes|nullable|string|max:14',
            'rg'             => 'sometimes|nullable|string|max:20',
            'instagram'      => 'sometimes|nullable|string|max:200',
            'facebook'       => 'sometimes|nullable|string|max:200',
            'linkedin'       => 'sometimes|nullable|string|max:200',
            'twitter'        => 'sometimes|nullable|string|max:200',
            'website'        => 'sometimes|nullable|string|max:300',
            'opt_out'        => 'sometimes|boolean',
        ]);

        $tenantId = $request->user()->tenant_id;
        $campos   = [
            'nome','email','email_2','telefone_2','profissao','empresa','departamento',
            'observacoes','endereco','cidade','estado','cep','pais','tipo','tipo_contato',
            'score','genero','estado_civil','aniversario','cpf','rg',
            'instagram','facebook','linkedin','twitter','website','opt_out',
        ];
        $dados = $request->only($campos);

        // Regra de governança: nome editado por parceiro/SDR vai para auditoria
        // se o master já tiver um nome diferente. Dono e admin atualizam direto.
        $perfilPrivilegiado = in_array($request->user()->perfil ?? '', ['dono', 'admin']);
        if (
            ! $perfilPrivilegiado &&
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
        return \App\Console\Commands\NormalizarTelefones::normalizar($raw);
    }
}
