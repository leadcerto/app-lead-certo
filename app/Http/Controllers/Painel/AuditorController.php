<?php

namespace App\Http\Controllers\Painel;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Contato;
use App\Models\ContatoPendente;
use App\Models\VinculoContatoTenant;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuditorController extends Controller
{
    // ── View ─────────────────────────────────────────────────────────────────

    public function view(): View
    {
        return view('auditor.index');
    }

    // ── Dashboard de Saúde dos Dados ─────────────────────────────────────────

    public function stats(Request $request): JsonResponse
    {
        $user = $request->user();
        $tenantId = $user?->tenant_id;

        $vinculoQuery = VinculoContatoTenant::query();
        if ($tenantId && !($user?->isAdmin())) {
            $vinculoQuery->where('tenant_id', $tenantId);
        }
        $contatoIds = $vinculoQuery->pluck('contato_id');

        $total = $contatoIds->count();
        if ($total === 0) {
            $total = Contato::count();
        }

        $pendentesQuery = VinculoContatoTenant::whereNotNull('campos_pendentes_auditoria');
        if ($tenantId && !($user?->isAdmin())) {
            $pendentesQuery->where('tenant_id', $tenantId);
        }
        $pendentes = $pendentesQuery->get()->sum(fn ($v) => count($v->campos_pendentes_auditoria ?? []));

        $telefonesErros = \App\Models\AuditoriaContato::where('status', 'pendente')
            ->when($contatoIds->isNotEmpty() && !($user?->isAdmin()), fn($q) => $q->whereIn('contato_id', $contatoIds))
            ->count();

        $conflitosQuery = ContatoPendente::where('status', 'aguardando');
        if ($tenantId && !($user?->isAdmin())) {
            $conflitosQuery->where('tenant_id', $tenantId);
        }
        $conflitos = $conflitosQuery->count();

        $inconsistentes = Contato::where('status_validacao', 'inconsistente')
            ->when($contatoIds->isNotEmpty() && !($user?->isAdmin()), fn($q) => $q->whereIn('id', $contatoIds))
            ->count();

        $semNome = Contato::where(fn($q) => $q->whereNull('nome')->orWhere('nome', '')->orWhere('nome', 'Sem Nome'))
            ->when($contatoIds->isNotEmpty() && !($user?->isAdmin()), fn($q) => $q->whereIn('id', $contatoIds))
            ->count();

        $semTelefone = Contato::where(fn($q) => $q->whereNull('telefone')->orWhere('telefone', ''))
            ->when($contatoIds->isNotEmpty() && !($user?->isAdmin()), fn($q) => $q->whereIn('id', $contatoIds))
            ->count();

        $inativos = Contato::onlyTrashed()
            ->when($contatoIds->isNotEmpty() && !($user?->isAdmin()), fn($q) => $q->whereIn('id', $contatoIds))
            ->count();

        return response()->json([
            'total'           => $total,
            'pendentes'       => $pendentes,
            'telefones_erros' => $telefonesErros,
            'conflitos'       => $conflitos,
            'inconsistentes'  => $inconsistentes,
            'sem_nome'        => $semNome,
            'sem_telefone'    => $semTelefone,
            'inativos'        => $inativos,
        ]);
    }

    // ── Telefones com Erro / Formato Internacional ───────────────────────────

    public function telefonesInvalidos(Request $request): JsonResponse
    {
        $user = $request->user();
        $tenantId = $user?->tenant_id;
        $contatoIds = ($tenantId && !($user?->isAdmin())) ? VinculoContatoTenant::where('tenant_id', $tenantId)->pluck('contato_id') : collect();

        $registros = \App\Models\AuditoriaContato::with('contato')
            ->when($contatoIds->isNotEmpty(), fn($q) => $q->whereIn('contato_id', $contatoIds))
            ->where('status', 'pendente')
            ->orderBy('id', 'desc')
            ->get();

        $itens = $registros->map(function ($r) {
            $infoPais = \App\Services\PaisTelefoneService::identificarPais($r->valor_original ?? $r->contato?->telefone);
            return [
                'id'             => $r->id,
                'contato_id'     => $r->contato_id,
                'nome'           => $r->contato?->nome ?: 'Sem Nome',
                'tipo'           => $r->tipo,
                'campo'          => $r->campo,
                'observacao'     => $r->observacao,
                'valor_original' => $r->valor_original,
                'valor_sugerido' => $r->valor_sugerido,
                'telefone'       => $infoPais['formatado'],
                'bandeira'       => $infoPais['bandeira'],
                'pais_nome'      => $infoPais['nome'],
                'ddi'            => $infoPais['ddi'],
                'iso'            => $infoPais['iso'],
                'numero_local'   => $infoPais['numero_local'],
            ];
        });

        return response()->json([
            'data'   => $itens,
            'total'  => $registros->count(),
            'paises' => \App\Services\PaisTelefoneService::PAISES,
        ]);
    }

    public function resolverTelefoneInvalido(Request $request, int $id, \App\Services\ContatoMergeService $merge): JsonResponse
    {
        $request->validate(['valor_novo' => 'required|string|max:50']);
        $auditoria = \App\Models\AuditoriaContato::findOrFail($id);
        $contato = $auditoria->contato;
        if (!$contato) return response()->json(['erro' => 'Contato não encontrado.'], 404);

        $valorNovo = preg_replace('/\D/', '', $request->input('valor_novo'));

        try {
            $contato->update(['telefone' => $valorNovo]);
        } catch (\Illuminate\Database\UniqueConstraintViolationException $e) {
            $canonico = Contato::withTrashed()->where('telefone', $valorNovo)->first();
            if ($canonico) {
                $merge->mesclar($contato, $canonico);
            }
        }

        $auditoria->update(['status' => 'resolvido', 'resolvido_em' => now()]);

        return response()->json(['ok' => true]);
    }

    public function ignorarTelefoneInvalido(int $id): JsonResponse
    {
        $auditoria = \App\Models\AuditoriaContato::findOrFail($id);
        $auditoria->update(['status' => 'ignorado', 'resolvido_em' => now()]);
        return response()->json(['ok' => true]);
    }

    // ── Conflitos de Identidade (número possivelmente reciclado) ─────────────

    public function conflitos(Request $request): JsonResponse
    {
        $user = $request->user();
        $tenantId = $user?->tenant_id;

        $conflitos = ContatoPendente::with('contatoExistente')
            ->when($tenantId && !($user?->isAdmin()), fn($q) => $q->where('tenant_id', $tenantId))
            ->where('status', 'aguardando')
            ->orderBy('similaridade_nome')   // os mais diferentes primeiro
            ->paginate(50);

        $itens = $conflitos->map(fn($c) => [
            'id'                  => $c->id,
            'tipo_conflito'       => $c->tipo_conflito,
            'telefone'            => $this->formatarTelefoneCompleto($c->telefone ?? ''),
            'nome_google'         => $c->nome,
            'nome_existente'      => $c->nome_existente,
            'contato_existente_id' => $c->contato_existente_id,
            'similaridade_nome'   => $c->similaridade_nome,
            'criado_em'           => $c->criado_em?->format('d/m/Y H:i'),
        ]);

        return response()->json(['data' => $itens, 'total' => $conflitos->total()]);
    }

    public function fundirConflito(Request $request, ContatoPendente $pendente): JsonResponse
    {
        if ($pendente->status !== 'aguardando') {
            return response()->json(['erro' => 'Conflito já resolvido.'], 422);
        }

        // Mesma pessoa — atualiza campos vazios no contato existente
        if ($pendente->contato_existente_id) {
            $contato = Contato::find($pendente->contato_existente_id);
            if ($contato) {
                $campos = array_filter($pendente->dados_brutos ?? [], fn($v) => $v !== null && $v !== '');
                $atualizar = [];
                foreach ($campos as $campo => $valor) {
                    if (empty($contato->$campo) && $valor) {
                        $atualizar[$campo] = $valor;
                    }
                }
                if ($atualizar) $contato->update($atualizar);
            }
        }

        $pendente->update([
            'status'       => 'fundido',
            'resolvido_por' => auth()->id(),
            'resolvido_em' => now(),
        ]);

        AuditLog::registrar('contatos_pendentes', $pendente->id, 'fundir_conflito',
            contexto: ['contato_existente_id' => $pendente->contato_existente_id]);

        return response()->json(['ok' => true]);
    }

    public function criarNovoConflito(Request $request, ContatoPendente $pendente): JsonResponse
    {
        if ($pendente->status !== 'aguardando') {
            return response()->json(['erro' => 'Conflito já resolvido.'], 422);
        }

        // Número reciclado confirmado — cria um novo contato
        $dados = array_merge($pendente->dados_brutos ?? [], [
            'telefone' => $pendente->telefone,
            'origem'   => 'agenda_google',
            'opt_out'  => false,
        ]);

        $novoContato = Contato::create($dados);

        VinculoContatoTenant::firstOrCreate([
            'contato_id' => $novoContato->id,
            'tenant_id'  => $pendente->tenant_id,
        ]);

        $pendente->update([
            'status'       => 'novo_criado',
            'resolvido_por' => auth()->id(),
            'resolvido_em' => now(),
        ]);

        AuditLog::registrar('contatos_pendentes', $pendente->id, 'criar_novo_conflito',
            valorNovo: "novo contato #{$novoContato->id}",
            contexto:  ['contato_existente_id' => $pendente->contato_existente_id]);

        return response()->json(['ok' => true, 'novo_contato_id' => $novoContato->id]);
    }

    public function descartarConflito(Request $request, ContatoPendente $pendente): JsonResponse
    {
        $pendente->update([
            'status'        => 'descartado',
            'resolvido_por' => auth()->id(),
            'resolvido_em'  => now(),
            'observacoes'   => $request->motivo,
        ]);

        AuditLog::registrar('contatos_pendentes', $pendente->id, 'descartar_conflito');

        return response()->json(['ok' => true]);
    }

    // ── Campos pendentes de auditoria (qualquer origem: google, humano_interno, whatsapp_pushname) ──

    public function pendentesCampos(Request $request): JsonResponse
    {
        $user = $request->user();
        $tenantId = $user?->tenant_id;

        $vinculosQuery = VinculoContatoTenant::with('contato')
            ->whereNotNull('campos_pendentes_auditoria');

        if ($tenantId && !($user?->isAdmin())) {
            $vinculosQuery->where('tenant_id', $tenantId);
        }

        $vinculos = $vinculosQuery->orderBy('contato_id')->get();

        $itens = [];
        foreach ($vinculos as $v) {
            foreach ($v->campos_pendentes_auditoria ?? [] as $campo => $pendencia) {
                $valorAtual    = $v->contato?->$campo;
                $valorSugerido = $pendencia['sugerido'] ?? null;
                $infoPais      = \App\Services\PaisTelefoneService::identificarPais($v->contato?->telefone ?? '');

                $itens[] = [
                    'vinculo_id'        => $v->id,
                    'contato_id'        => $v->contato_id,
                    'tenant_id'         => $v->tenant_id,
                    'campo'             => $campo,
                    'valor_atual'       => $valorAtual,
                    'valor_sugerido'    => $valorSugerido,
                    'origem'            => $pendencia['origem'] ?? null,
                    'nome'              => $v->contato?->nome ?: 'Sem Nome',
                    'sobrenome'         => $v->contato?->sobrenome,
                    'email'             => $v->contato?->email,
                    'telefone_original' => $v->contato?->telefone,
                    'telefone'          => $infoPais['formatado'],
                    'bandeira'          => $infoPais['bandeira'],
                    'pais_nome'         => $infoPais['nome'],
                    'ddi'               => $infoPais['ddi'],
                    'numero_local'      => $infoPais['numero_local'] ?: preg_replace('/^' . $infoPais['ddi'] . '/', '', preg_replace('/\D/', '', $v->contato?->telefone ?? '')),
                    'telefone_exibicao' => $infoPais['exibicao'],
                ];
            }
        }

        return response()->json([
            'data'   => $itens,
            'total'  => count($itens),
            'paises' => \App\Services\PaisTelefoneService::PAISES,
        ]);
    }

    public function aprovarCampo(Request $request, VinculoContatoTenant $vinculo, string $campo): JsonResponse
    {
        $pendencia = $vinculo->campos_pendentes_auditoria[$campo] ?? null;
        if (! $pendencia) {
            return response()->json(['erro' => 'Nenhuma sugestão pendente pra este campo.'], 422);
        }

        $valorAntigo = $vinculo->contato?->$campo;
        $valorNovo   = $pendencia['sugerido'];

        $vinculo->contato?->update([$campo => $valorNovo]);

        $pendentes = $vinculo->campos_pendentes_auditoria;
        unset($pendentes[$campo]);

        $humano = $vinculo->campos_editados_humano ?? [];
        $humano[$campo] = now()->toIso8601String(); // aprovar é uma decisão humana

        $vinculo->update([
            'campos_pendentes_auditoria' => $pendentes ?: null,
            'campos_editados_humano'     => $humano,
        ]);

        AuditLog::registrar(
            tabela:      'contatos',
            registroId:  $vinculo->contato_id,
            acao:        'aprovar_campo',
            campo:       $campo,
            valorAntigo: $valorAntigo,
            valorNovo:   $valorNovo,
            contexto:    ['vinculo_id' => $vinculo->id, 'tenant_id' => $vinculo->tenant_id, 'origem' => $pendencia['origem'] ?? null]
        );

        return response()->json(['ok' => true]);
    }

    public function salvarValorCampo(Request $request, VinculoContatoTenant $vinculo, string $campo): JsonResponse
    {
        $novoValor = trim((string)$request->input('valor', ''));
        $valorAntigo = $vinculo->contato?->$campo;

        $vinculo->contato?->update([$campo => $novoValor]);

        $pendentes = $vinculo->campos_pendentes_auditoria ?? [];
        unset($pendentes[$campo]);

        $humano = $vinculo->campos_editados_humano ?? [];
        $humano[$campo] = now()->toIso8601String();

        $vinculo->update([
            'campos_pendentes_auditoria' => $pendentes ?: null,
            'campos_editados_humano'     => $humano,
        ]);

        AuditLog::registrar(
            tabela:      'contatos',
            registroId:  $vinculo->contato_id,
            acao:        'editar_salvar_campo',
            campo:       $campo,
            valorAntigo: $valorAntigo,
            valorNovo:   $novoValor,
            contexto:    ['vinculo_id' => $vinculo->id, 'tenant_id' => $vinculo->tenant_id]
        );

        return response()->json(['ok' => true]);
    }

    public function rejeitarCampo(VinculoContatoTenant $vinculo, string $campo): JsonResponse
    {
        $pendencia = $vinculo->campos_pendentes_auditoria[$campo] ?? null;
        if (! $pendencia) {
            return response()->json(['erro' => 'Nenhuma sugestão pendente pra este campo.'], 422);
        }

        $pendentes = $vinculo->campos_pendentes_auditoria;
        unset($pendentes[$campo]);
        $vinculo->update(['campos_pendentes_auditoria' => $pendentes ?: null]);

        AuditLog::registrar(
            tabela:      'vinculos_contato_tenant',
            registroId:  $vinculo->id,
            acao:        'rejeitar_campo',
            campo:       $campo,
            valorAntigo: $pendencia['sugerido'] ?? null,
            valorNovo:   null,
            contexto:    ['contato_id' => $vinculo->contato_id, 'tenant_id' => $vinculo->tenant_id]
        );

        return response()->json(['ok' => true]);
    }

    public function salvarContatoCompleto(Request $request, $id): JsonResponse
    {
        $vinculo = VinculoContatoTenant::with(['contato' => fn($q) => $q->withTrashed()])->find($id);
        $contato = null;

        if ($vinculo) {
            $contato = $vinculo->contato;
        } else {
            $contato = Contato::withTrashed()->find($id);
            if ($contato) {
                $user = $request->user();
                $tenantId = $user?->tenant_id;
                $vinculo = VinculoContatoTenant::where('contato_id', $contato->id)
                    ->when($tenantId && !($user?->isAdmin()), fn($q) => $q->where('tenant_id', $tenantId))
                    ->first();
            }
        }

        if (!$contato) {
            return response()->json(['erro' => 'Contato não encontrado.'], 404);
        }

        // Se o contato estava inativo (soft deleted) e foi editado/salvo, restaura-o automaticamente
        if ($contato->trashed()) {
            $contato->restore();
        }

        $nome = trim((string)$request->input('nome', ''));
        $sobrenome = trim((string)$request->input('sobrenome', ''));
        $email = trim((string)$request->input('email', ''));
        $ddi = preg_replace('/\D/', '', (string)$request->input('ddi', '55'));
        $numeroLocal = preg_replace('/\D/', '', (string)$request->input('numero_local', ''));

        if (empty($nome)) {
            $nome = 'Sem Nome';
        }

        $dadosAtualizar = [
            'nome'      => $nome,
            'sobrenome' => $sobrenome ?: null,
            'email'     => $email ?: null,
        ];

        $novoTelefone = null;
        if (!empty($numeroLocal)) {
            $novoTelefone = (str_starts_with($numeroLocal, $ddi) && strlen($numeroLocal) > 10)
                ? $numeroLocal
                : ($ddi . $numeroLocal);
        }

        if ($novoTelefone && $novoTelefone !== $contato->telefone) {
            $outroContato = Contato::where('telefone', $novoTelefone)->where('id', '!=', $contato->id)->first();
            if ($outroContato) {
                // Se já existe outro registro com este mesmo telefone, atualiza o outro e funde o vínculo
                $outroContato->update([
                    'nome'      => $nome,
                    'sobrenome' => $sobrenome ?: null,
                    'email'     => $email ?: $outroContato->email,
                ]);

                if ($vinculo) {
                    $vinculoExistente = VinculoContatoTenant::where('tenant_id', $vinculo->tenant_id)
                        ->where('contato_id', $outroContato->id)
                        ->where('id', '!=', $vinculo->id)
                        ->first();

                    if ($vinculoExistente) {
                        $vinculoExistente->update([
                            'campos_pendentes_auditoria' => null,
                        ]);
                        $vinculo->delete();
                    } else {
                        $vinculo->update([
                            'contato_id'                 => $outroContato->id,
                            'campos_pendentes_auditoria' => null,
                        ]);
                    }
                }

                $contato = $outroContato;
            } else {
                $dadosAtualizar['telefone'] = $novoTelefone;
                $contato->update($dadosAtualizar);
            }
        } else {
            $contato->update($dadosAtualizar);
        }

        if ($vinculo) {
            $pendentes = $vinculo->campos_pendentes_auditoria ?? [];
            unset($pendentes['nome']);
            unset($pendentes['sobrenome']);
            unset($pendentes['telefone']);

            $humano = $vinculo->campos_editados_humano ?? [];
            $humano['nome'] = now()->toIso8601String();
            $humano['completo'] = now()->toIso8601String();

            $vinculo->update([
                'campos_pendentes_auditoria' => $pendentes ?: null,
                'campos_editados_humano'     => $humano,
            ]);
        }

        ContatoPendente::where('contato_existente_id', $contato->id)
            ->where('status', 'aguardando')
            ->update([
                'status'        => 'fundido',
                'resolvido_por' => auth()->id(),
                'resolvido_em'  => now(),
            ]);

        AuditLog::registrar(
            tabela:      'contatos',
            registroId:  $contato->id,
            acao:        'edicao_completa_auditor',
            contexto:    ['dados' => $dadosAtualizar, 'vinculo_id' => $vinculo?->id]
        );

        return response()->json([
            'ok'      => true,
            'contato' => $contato->fresh(),
        ]);
    }

    public function aprovarLote(Request $request): JsonResponse
    {
        $itens = $request->input('itens', []);
        $total = 0;

        foreach ($itens as $item) {
            $vinculoId = $item['vinculo_id'] ?? null;
            $campo     = $item['campo'] ?? null;
            if (!$vinculoId || !$campo) continue;

            $vinculo = VinculoContatoTenant::with('contato')->find($vinculoId);
            if (!$vinculo) continue;

            $pendencia = $vinculo->campos_pendentes_auditoria[$campo] ?? null;
            if (!$pendencia) continue;

            $valorAntigo = $vinculo->contato?->$campo;
            $valorNovo   = $pendencia['sugerido'];

            $vinculo->contato?->update([$campo => $valorNovo]);

            $pendentes = $vinculo->campos_pendentes_auditoria ?? [];
            unset($pendentes[$campo]);

            $humano = $vinculo->campos_editados_humano ?? [];
            $humano[$campo] = now()->toIso8601String();

            $vinculo->update([
                'campos_pendentes_auditoria' => $pendentes ?: null,
                'campos_editados_humano'     => $humano,
            ]);

            AuditLog::registrar(
                tabela:      'contatos',
                registroId:  $vinculo->contato_id,
                acao:        'aprovar_campo_lote',
                campo:       $campo,
                valorAntigo: $valorAntigo,
                valorNovo:   $valorNovo,
                contexto:    ['vinculo_id' => $vinculo->id]
            );
            $total++;
        }

        return response()->json(['ok' => true, 'total' => $total]);
    }

    public function rejeitarLote(Request $request): JsonResponse
    {
        $itens = $request->input('itens', []);
        $total = 0;

        foreach ($itens as $item) {
            $vinculoId = $item['vinculo_id'] ?? null;
            $campo     = $item['campo'] ?? null;
            if (!$vinculoId || !$campo) continue;

            $vinculo = VinculoContatoTenant::find($vinculoId);
            if (!$vinculo) continue;

            $pendentes = $vinculo->campos_pendentes_auditoria ?? [];
            if (!isset($pendentes[$campo])) continue;

            unset($pendentes[$campo]);
            $vinculo->update(['campos_pendentes_auditoria' => $pendentes ?: null]);
            $total++;
        }

        return response()->json(['ok' => true, 'total' => $total]);
    }

    public function marcarSemNomeLote(Request $request): JsonResponse
    {
        $itens = $request->input('itens', []);
        $total = 0;

        foreach ($itens as $item) {
            $vinculoId = $item['vinculo_id'] ?? null;
            if (!$vinculoId) continue;

            $vinculo = VinculoContatoTenant::with('contato')->find($vinculoId);
            if (!$vinculo) continue;

            $vinculo->contato?->update(['nome' => 'Sem Nome', 'sobrenome' => null]);

            $pendentes = $vinculo->campos_pendentes_auditoria ?? [];
            unset($pendentes['nome']);
            unset($pendentes['sobrenome']);

            $humano = $vinculo->campos_editados_humano ?? [];
            $humano['nome'] = now()->toIso8601String();

            $vinculo->update([
                'campos_pendentes_auditoria' => $pendentes ?: null,
                'campos_editados_humano'     => $humano,
            ]);

            AuditLog::registrar(
                tabela:      'contatos',
                registroId:  $vinculo->contato_id,
                acao:        'marcar_sem_nome',
                campo:       'nome',
                valorAntigo: $vinculo->contato?->nome,
                valorNovo:   'Sem Nome',
                contexto:    ['vinculo_id' => $vinculo->id]
            );
            $total++;
        }

        return response()->json(['ok' => true, 'total' => $total]);
    }

    public static function extrairNomeProprio(?string $nome, ?string $sobrenome = null): array
    {
        $tagsLixo = [
            'frete\w*', 'frt\w*', 'mudan\w*', 'transp\w*', 'caminh\w*', 'bongo', 'carreto\w*', 'carga\w*',
            'lead\w*', 'cliente\w*', 'contato\w*', 'or[cç]amento\w*', 'disk\w*', 'motorista\w*', 'ajudante\w*',
            'entregador\w*', 'entrega\w*', 'zap\w*', 'whatsapp\w*', 'teste\w*', 'pedreiro\w*', 'pintor\w*',
            'marceneiro\w*', 'eletricista\w*', 'diarista\w*', 'faxineira\w*', 'loja\w*', 'oficina\w*',
            'vendas?', 'atendimento\w*', 'sac', 'suporte\w*', 'comercial\w*', 'adm\w*', 'estofador\w*',
            'sofa\w*', 'camorim\w*', 'urologia\w*', 'advocacia\w*', 'vidra[cç]aria\w*', 'engemedic\w*',
            'box', 'pizza\w*', 'mdm\w*', 'ajd\w*', 'dr\w*', 'dra\w*', 'adv\w*', 'moveis\w*', 'marcenaria\w*',
            'fiorino\w*', 'sprinter\w*', 'iveco\w*', 'vuc\w*', 'van\w*', 'refritec\w*'
        ];
        $patternLixo = '/\b(' . implode('|', $tagsLixo) . ')\b/iu';

        $candidatos = [];
        if (!empty($nome)) $candidatos[] = $nome;
        if (!empty($sobrenome)) $candidatos[] = $sobrenome;
        $junto = trim($nome . ' ' . $sobrenome);
        if (!empty($junto) && !in_array($junto, $candidatos)) $candidatos[] = $junto;

        foreach ($candidatos as $cand) {
            // 1. Limpar emojis, símbolos especiais, pontuações e dígitos
            $limpo = preg_replace('/[\d\x{1F600}-\x{1F64F}\x{1F300}-\x{1F5FF}\x{1F680}-\x{1F6FF}\x{2600}-\x{26FF}\x{2700}-\x{27BF}\x{1F900}-\x{1F9FF}\x{1F1E0}-\x{1F1FF}⚡★☆✓✔*#@+\-_.,:;\/\\|()]/u', ' ', $cand);
            // 2. Remover tags comerciais/lixo
            $limpo = preg_replace($patternLixo, ' ', $limpo);
            // 3. Normalizar espaços
            $limpo = trim(preg_replace('/\s+/', ' ', $limpo));

            // Se sobrou um nome válido com pelo menos 2 letras
            if (strlen(preg_replace('/[^a-zA-ZáéíóúÁÉÍÓÚãõÃÕâêîôûÂÊÎÔÛçÇ]/', '', $limpo)) >= 2 && !preg_match('/^sem nome$/i', $limpo)) {
                $palavras = explode(' ', mb_strtolower($limpo, 'UTF-8'));
                $nomeFinal = implode(' ', array_map(function($w) {
                    if (in_array($w, ['de', 'da', 'do', 'dos', 'das', 'e'])) return $w;
                    return mb_convert_case($w, MB_CASE_TITLE, 'UTF-8');
                }, $palavras));
                return ['nome' => $nomeFinal, 'is_pessoa' => true];
            }
        }

        return ['nome' => 'Sem Nome', 'is_pessoa' => false];
    }

    public static function isNaoPessoa(?string $str): bool
    {
        $info = self::extrairNomeProprio($str);
        return !$info['is_pessoa'];
    }

    public function autoLimparNaoPessoas(Request $request): JsonResponse
    {
        $user = $request->user();
        $tenantId = $user?->tenant_id;

        $vinculosQuery = VinculoContatoTenant::with('contato')
            ->whereNotNull('campos_pendentes_auditoria');

        if ($tenantId && !($user?->isAdmin())) {
            $vinculosQuery->where('tenant_id', $tenantId);
        }

        $vinculos = $vinculosQuery->get();
        $total = 0;

        foreach ($vinculos as $v) {
            $pendentes = $v->campos_pendentes_auditoria ?? [];
            $nomeAtual = $v->contato?->nome;
            $sobrenomeAtual = $v->contato?->sobrenome;
            $nomeSugerido = $pendencia['nome']['sugerido'] ?? null;
            $sobrenomeSugerido = $pendencia['sobrenome']['sugerido'] ?? null;

            $candNome = $nomeSugerido ?: $nomeAtual;
            $candSobrenome = $sobrenomeSugerido ?: $sobrenomeAtual;

            $resultado = self::extrairNomeProprio($candNome, $candSobrenome);

            if ($resultado['is_pessoa']) {
                $v->contato?->update(['nome' => $resultado['nome'], 'sobrenome' => null]);
            } else {
                $v->contato?->update(['nome' => 'Sem Nome', 'sobrenome' => null]);
            }

            unset($pendentes['nome']);
            unset($pendentes['sobrenome']);
            $humano = $v->campos_editados_humano ?? [];
            $humano['nome'] = now()->toIso8601String();

            $v->update([
                'campos_pendentes_auditoria' => $pendentes ?: null,
                'campos_editados_humano'     => $humano,
            ]);
            $total++;
        }

        // Também varre a base de contatos vinculados para limpar nomes que sejam telefone ou termos não-pessoa
        $contatoIds = ($tenantId && !($user?->isAdmin()))
            ? VinculoContatoTenant::where('tenant_id', $tenantId)->pluck('contato_id')
            : Contato::pluck('id');

        $contatosParaLimpar = Contato::whereIn('id', $contatoIds)
            ->where('nome', '!=', 'Sem Nome')
            ->get();

        foreach ($contatosParaLimpar as $c) {
            $res = self::extrairNomeProprio($c->nome, $c->sobrenome);
            if ($res['is_pessoa']) {
                if ($c->nome !== $res['nome']) {
                    $c->update(['nome' => $res['nome'], 'sobrenome' => null]);
                    $total++;
                }
            } else {
                $c->update(['nome' => 'Sem Nome', 'sobrenome' => null]);
                $total++;
            }
        }

        return response()->json(['ok' => true, 'total' => $total]);
    }

    public function autoResolverConflitos(Request $request): JsonResponse
    {
        $user = $request->user();
        $tenantId = $user?->tenant_id;

        $conflitosQuery = ContatoPendente::where('status', 'aguardando');
        if ($tenantId && !($user?->isAdmin())) {
            $conflitosQuery->where('tenant_id', $tenantId);
        }

        $conflitos = $conflitosQuery->get();
        $totalResolvidos = 0;

        foreach ($conflitos as $c) {
            $res = self::extrairNomeProprio($c->nome, $c->nome_existente);
            $nomeFinal = $res['is_pessoa'] ? $res['nome'] : 'Sem Nome';

            if ($c->contato_existente_id) {
                $contato = Contato::find($c->contato_existente_id);
                if ($contato) {
                    $contato->update(['nome' => $nomeFinal, 'sobrenome' => null]);
                }
            }

            $c->update([
                'status'        => 'fundido',
                'resolvido_por' => auth()->id(),
                'resolvido_em'  => now(),
            ]);

            AuditLog::registrar('contatos_pendentes', $c->id, 'auto_resolver_conflito',
                contexto: ['nome_atribuido' => $nomeFinal, 'contato_existente_id' => $c->contato_existente_id]);

            $totalResolvidos++;
        }

        return response()->json(['ok' => true, 'total' => $totalResolvidos]);
    }

    // ── Sinalizar contato como inconsistente ──────────────────────────────────

    public function sinalizar(Request $request, Contato $contato): JsonResponse
    {
        $request->validate(['motivo' => 'required|string|max:500']);

        $statusAnterior = $contato->status_validacao;
        $contato->update(['status_validacao' => 'inconsistente']);

        AuditLog::registrar(
            tabela:      'contatos',
            registroId:  $contato->id,
            acao:        'sinalizar',
            campo:       'status_validacao',
            valorAntigo: $statusAnterior,
            valorNovo:   'inconsistente',
            contexto:    ['motivo' => $request->motivo]
        );

        return response()->json(['ok' => true]);
    }

    // ── Aprovar cadastro (status_validacao = aprovado) ────────────────────────

    public function aprovarCadastro(Contato $contato): JsonResponse
    {
        $statusAnterior = $contato->status_validacao;
        $contato->update(['status_validacao' => 'aprovado']);

        AuditLog::registrar(
            tabela:      'contatos',
            registroId:  $contato->id,
            acao:        'aprovar_cadastro',
            campo:       'status_validacao',
            valorAntigo: $statusAnterior,
            valorNovo:   'aprovado'
        );

        return response()->json(['ok' => true]);
    }

    // ── Inativar contato (Soft Delete) ────────────────────────────────────────

    public function inativar(Request $request, Contato $contato): JsonResponse
    {
        $request->validate(['motivo' => 'required|string|max:500']);

        $contato->delete(); // SoftDelete — grava deleted_at, mantém o registro

        AuditLog::registrar(
            tabela:      'contatos',
            registroId:  $contato->id,
            acao:        'inativar',
            contexto:    ['motivo' => $request->motivo]
        );

        return response()->json(['ok' => true]);
    }

    public function excluirContato(Request $request, $id): JsonResponse
    {
        $contato = Contato::withTrashed()->find($id);
        if (!$contato) {
            $vinculo = VinculoContatoTenant::find($id);
            $contato = $vinculo?->contato()->withTrashed()->first();
        }

        if (!$contato) {
            return response()->json(['erro' => 'Contato não encontrado.'], 404);
        }

        $definitivo = $request->boolean('definitivo', false);

        if ($definitivo) {
            VinculoContatoTenant::where('contato_id', $contato->id)->delete();
            ContatoPendente::where('contato_existente_id', $contato->id)->delete();
            \App\Models\AuditoriaContato::where('contato_id', $contato->id)->delete();
            
            $contatoId = $contato->id;
            $contato->forceDelete();

            AuditLog::registrar(
                tabela:     'contatos',
                registroId: $contatoId,
                acao:       'excluir_definitivo',
                contexto:   ['motivo' => $request->input('motivo', 'Exclusão definitiva pelo auditor')]
            );
        } else {
            $contato->delete();

            AuditLog::registrar(
                tabela:     'contatos',
                registroId: $contato->id,
                acao:       'inativar',
                contexto:   ['motivo' => $request->input('motivo', 'Inativação pelo auditor')]
            );
        }

        return response()->json(['ok' => true]);
    }

    public function reativarContato(Request $request, $id): JsonResponse
    {
        $contato = Contato::withTrashed()->find($id);
        if (!$contato) {
            $vinculo = VinculoContatoTenant::find($id);
            $contato = $vinculo?->contato()->withTrashed()->first();
        }

        if (!$contato) {
            return response()->json(['erro' => 'Contato não encontrado.'], 404);
        }

        $contato->restore();
        $contato->update(['status_validacao' => 'aprovado']);

        AuditLog::registrar(
            tabela:     'contatos',
            registroId: $contato->id,
            acao:       'reativar',
            contexto:   ['motivo' => 'Reativado pelo auditor']
        );

        return response()->json(['ok' => true]);
    }

    public function contatos(Request $request): JsonResponse
    {
        $user = $request->user();
        $tenantId = $user?->tenant_id;

        $vinculoQuery = VinculoContatoTenant::query();
        if ($tenantId && !($user?->isAdmin())) {
            $vinculoQuery->where('tenant_id', $tenantId);
        }
        $contatoIds = $vinculoQuery->pluck('contato_id');

        $status = $request->input('status');
        $busca = $request->input('busca');

        $query = Contato::query();

        if ($status === 'inativo' || $status === 'inativos') {
            $query->onlyTrashed();
        } else {
            $query->withoutTrashed();
            if ($status && in_array($status, ['aprovado', 'inconsistente', 'pendente'])) {
                $query->where('status_validacao', $status);
            }
        }

        if ($contatoIds->isNotEmpty() && !($user?->isAdmin())) {
            $query->whereIn('id', $contatoIds);
        }

        if ($request->tipo_pessoa) {
            $query->where('tipo_pessoa', $request->tipo_pessoa);
        }
        if ($request->origem) {
            $query->where('origem', $request->origem);
        }

        if ($busca) {
            if (strcasecmp($busca, 'Sem Nome') === 0) {
                $query->where(function ($q) {
                    $q->whereNull('nome')->orWhere('nome', '')->orWhere('nome', 'Sem Nome');
                });
            } else {
                $query->where(function ($q) use ($busca) {
                    $q->where('nome', 'like', '%' . $busca . '%')
                      ->orWhere('sobrenome', 'like', '%' . $busca . '%')
                      ->orWhere('telefone', 'like', '%' . $busca . '%')
                      ->orWhere('email', 'like', '%' . $busca . '%');
                });
            }
        }

        $contatos = $query->select([
            'id', 'nome', 'sobrenome', 'telefone', 'email', 'cpf', 'cnpj',
            'tipo_pessoa', 'status_validacao', 'origem', 'empresa', 'created_at', 'deleted_at',
        ])
        ->orderBy('created_at', 'desc')
        ->paginate(100);

        $itens = $contatos->map(function ($c) {
            $infoPais = \App\Services\PaisTelefoneService::identificarPais($c->telefone ?? '');
            return [
                'id'                => $c->id,
                'nome'              => $c->nome ?: 'Sem Nome',
                'sobrenome'         => $c->sobrenome,
                'telefone'          => $infoPais['formatado'],
                'telefone_original' => $c->telefone,
                'bandeira'          => $infoPais['bandeira'],
                'ddi'               => $infoPais['ddi'],
                'numero_local'      => $infoPais['numero_local'],
                'telefone_exibicao' => $infoPais['exibicao'],
                'email'             => $c->email,
                'tipo_pessoa'       => $c->tipo_pessoa,
                'status_validacao'  => $c->trashed() ? 'inativo' : ($c->status_validacao ?: 'pendente'),
                'origem'            => $c->origem,
                'empresa'           => $c->empresa,
                'criado_em'         => $c->created_at?->format('d/m/Y'),
            ];
        });

        return response()->json([
            'data'          => $itens,
            'total'         => $contatos->total(),
            'pagina_atual'  => $contatos->currentPage(),
            'ultima_pagina' => $contatos->lastPage(),
        ]);
    }

    // ── Histórico de auditoria ────────────────────────────────────────────────

    public function logs(Request $request): JsonResponse
    {
        $logs = AuditLog::with('usuario')
            ->when($request->tabela, fn ($q) => $q->where('tabela', $request->tabela))
            ->when($request->acao,   fn ($q) => $q->where('acao',   $request->acao))
            ->orderByDesc('criado_em')
            ->paginate(100);

        return response()->json([
            'data'  => $logs->map(fn ($l) => [
                'id'           => $l->id,
                'auditor'      => $l->usuario_nome ?? 'Sistema',
                'tabela'       => $l->tabela,
                'registro_id'  => $l->registro_id,
                'acao'         => $l->acao,
                'campo'        => $l->campo,
                'valor_antigo' => $l->valor_antigo,
                'valor_novo'   => $l->valor_novo,
                'criado_em'    => $l->criado_em?->format('d/m/Y H:i'),
            ]),
            'total' => $logs->total(),
        ]);
    }

    // ── Formatação de Telefone Completo ───────────────────────────────────────

    private function formatarTelefoneCompleto(?string $telefone): string
    {
        if (!$telefone) return '—';
        $nums = preg_replace('/\D/', '', $telefone);
        if (str_starts_with($nums, '55') && strlen($nums) >= 12) {
            $nums = substr($nums, 2);
        }
        if (strlen($nums) === 11) {
            return '(' . substr($nums, 0, 2) . ') ' . substr($nums, 2, 5) . '-' . substr($nums, 7);
        }
        if (strlen($nums) === 10) {
            return '(' . substr($nums, 0, 2) . ') ' . substr($nums, 2, 4) . '-' . substr($nums, 6);
        }
        return $telefone;
    }

    // ── Mascaramento de dados (LGPD) ──────────────────────────────────────────

    private function mascarar(string $valor, string $tipo): ?string
    {
        if (! $valor) return null;

        return match ($tipo) {
            'cpf'  => preg_replace(
                '/^(\d{3})(\d{3})(\d{3})(\d{2})$/',
                '***.$2.$3-**',
                preg_replace('/\D/', '', $valor)
            ),
            'cnpj' => preg_replace(
                '/^(\d{2})(\d{3})(\d{3})(\d{4})(\d{2})$/',
                '**.$2.$3/$4-**',
                preg_replace('/\D/', '', $valor)
            ),
            'email' => preg_replace('/(?<=.).(?=.*@)/', '*', $valor),
            'telefone' => $this->formatarTelefoneCompleto($valor),
            default => $valor,
        };
    }
}
