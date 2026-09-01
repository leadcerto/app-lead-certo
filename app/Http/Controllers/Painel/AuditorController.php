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
        $tenantId = $request->user()?->tenant_id;
        $contatoIds = $tenantId ? VinculoContatoTenant::where('tenant_id', $tenantId)->pluck('contato_id') : collect();

        $total          = $contatoIds->count();
        $pendentes      = $tenantId
            ? VinculoContatoTenant::where('tenant_id', $tenantId)->whereNotNull('campos_pendentes_auditoria')->get()
                ->sum(fn ($v) => count($v->campos_pendentes_auditoria ?? []))
            : 0;
        $telefonesErros = $contatoIds->isNotEmpty()
            ? \App\Models\AuditoriaContato::whereIn('contato_id', $contatoIds)->where('status', 'pendente')->count()
            : 0;
        $conflitos      = $tenantId ? ContatoPendente::where('tenant_id', $tenantId)->where('status', 'aguardando')->count() : 0;
        $inconsistentes = $contatoIds->isNotEmpty() ? Contato::whereIn('id', $contatoIds)->where('status_validacao', 'inconsistente')->count() : 0;
        $semNome        = $contatoIds->isNotEmpty() ? Contato::whereIn('id', $contatoIds)->where(fn($q) => $q->whereNull('nome')->orWhere('nome', '')->orWhere('nome', 'Sem Nome'))->count() : 0;
        $semTelefone    = $contatoIds->isNotEmpty() ? Contato::whereIn('id', $contatoIds)->where(fn($q) => $q->whereNull('telefone')->orWhere('telefone', ''))->count() : 0;
        $inativos       = $contatoIds->isNotEmpty() ? Contato::onlyTrashed()->whereIn('id', $contatoIds)->count() : 0;

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
        $tenantId = $request->user()?->tenant_id;
        $contatoIds = $tenantId ? VinculoContatoTenant::where('tenant_id', $tenantId)->pluck('contato_id') : collect();

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
        $conflitos = ContatoPendente::with('contatoExistente')
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
        $vinculos = VinculoContatoTenant::with('contato')
            ->whereNotNull('campos_pendentes_auditoria')
            ->orderBy('contato_id')
            ->get();

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
                    'telefone'          => $infoPais['formatado'],
                    'bandeira'          => $infoPais['bandeira'],
                    'pais_nome'         => $infoPais['nome'],
                    'ddi'               => $infoPais['ddi'],
                    'telefone_exibicao' => $infoPais['exibicao'],
                ];
            }
        }

        return response()->json(['data' => $itens, 'total' => count($itens)]);
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

    public function autoLimparNaoPessoas(Request $request): JsonResponse
    {
        $vinculos = VinculoContatoTenant::with('contato')
            ->whereNotNull('campos_pendentes_auditoria')
            ->get();

        $total = 0;
        $padroesNaoPessoa = [
            '/^frete/i',
            '/^mudan/i',
            '/^transp/i',
            '/^caminh/i',
            '/^bongo/i',
            '/^carga/i',
            '/^lead/i',
            '/^cliente/i',
            '/^contato/i',
            '/^or[cç]amento/i',
            '/^\d+$/', // apenas números
            '/^[\W_]+$/', // apenas símbolos ou emojis
        ];

        foreach ($vinculos as $v) {
            $pendentes = $v->campos_pendentes_auditoria ?? [];
            $alterou = false;

            foreach ($pendentes as $campo => $pendencia) {
                if (!in_array($campo, ['nome', 'sobrenome'])) continue;

                $sugerido = trim((string)($pendencia['sugerido'] ?? ''));
                $isNaoPessoa = false;

                foreach ($padroesNaoPessoa as $padrao) {
                    if (preg_match($padrao, $sugerido)) {
                        $isNaoPessoa = true;
                        break;
                    }
                }

                // Se tem menos de 2 letras ou parece código/número
                if (strlen(preg_replace('/[^a-zA-Z]/', '', $sugerido)) < 2) {
                    $isNaoPessoa = true;
                }

                if ($isNaoPessoa) {
                    $v->contato?->update(['nome' => 'Sem Nome', 'sobrenome' => null]);
                    unset($pendentes['nome']);
                    unset($pendentes['sobrenome']);
                    $humano = $v->campos_editados_humano ?? [];
                    $humano['nome'] = now()->toIso8601String();
                    $v->campos_editados_humano = $humano;
                    $alterou = true;
                    $total++;
                    break;
                }
            }

            if ($alterou) {
                $v->update([
                    'campos_pendentes_auditoria' => $pendentes ?: null,
                    'campos_editados_humano'     => $v->campos_editados_humano,
                ]);
            }
        }

        return response()->json(['ok' => true, 'total' => $total]);
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

    // ── Lista de contatos com filtros ─────────────────────────────────────────

    public function contatos(Request $request): JsonResponse
    {
        $query = Contato::query();

        if ($request->status) {
            $query->where('status_validacao', $request->status);
        }
        if ($request->tipo_pessoa) {
            $query->where('tipo_pessoa', $request->tipo_pessoa);
        }
        if ($request->origem) {
            $query->where('origem', $request->origem);
        }
        if ($request->busca) {
            $query->where(function ($q) use ($request) {
                $q->where('nome', 'like', '%' . $request->busca . '%')
                  ->orWhere('telefone', 'like', '%' . $request->busca . '%')
                  ->orWhere('email', 'like', '%' . $request->busca . '%');
            });
        }

        $contatos = $query->select([
            'id', 'nome', 'sobrenome', 'telefone', 'email', 'cpf', 'cnpj',
            'tipo_pessoa', 'status_validacao', 'origem', 'empresa', 'created_at',
        ])
        ->orderBy('created_at', 'desc')
        ->paginate(100); // máximo 100 por página — sem exportação massiva

        $itens = $contatos->map(fn ($c) => [
            'id'               => $c->id,
            'nome'             => $c->nome,
            'sobrenome'        => $c->sobrenome,
            'telefone'         => $this->formatarTelefoneCompleto($c->telefone ?? ''),
            'email'            => $c->email,
            'cpf'              => $this->mascarar($c->cpf ?? '', 'cpf'),
            'cnpj'             => $this->mascarar($c->cnpj ?? '', 'cnpj'),
            'tipo_pessoa'      => $c->tipo_pessoa,
            'status_validacao' => $c->status_validacao,
            'origem'           => $c->origem,
            'empresa'          => $c->empresa,
            'criado_em'        => $c->created_at?->format('d/m/Y'),
        ]);

        return response()->json([
            'data'         => $itens,
            'total'        => $contatos->total(),
            'pagina_atual' => $contatos->currentPage(),
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
