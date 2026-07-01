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

    public function stats(): JsonResponse
    {
        $total          = Contato::count();
        $pendentes      = VinculoContatoTenant::where('auditoria_pendente', true)->count();
        $conflitos      = ContatoPendente::where('status', 'aguardando')->count();
        $inconsistentes = Contato::where('status_validacao', 'inconsistente')->count();
        $semNome        = Contato::whereNull('nome')->orWhere('nome', '')->count();
        $semTelefone    = Contato::whereNull('telefone')->orWhere('telefone', '')->count();
        $inativos       = Contato::onlyTrashed()->count();

        return response()->json([
            'total'          => $total,
            'pendentes'      => $pendentes,
            'conflitos'      => $conflitos,
            'inconsistentes' => $inconsistentes,
            'sem_nome'       => $semNome,
            'sem_telefone'   => $semTelefone,
            'inativos'       => $inativos,
        ]);
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
            'telefone'            => $this->mascarar($c->telefone ?? '', 'telefone'),
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

    // ── Sugestões de Nome Pendentes ───────────────────────────────────────────

    public function pendentes(Request $request): JsonResponse
    {
        $pendentes = VinculoContatoTenant::with(['contato'])
            ->where('auditoria_pendente', true)
            ->whereNotNull('nome_sugerido')
            ->orderBy('contato_id')
            ->paginate(50);

        $itens = $pendentes->map(function ($v) {
            return [
                'vinculo_id'     => $v->id,
                'contato_id'     => $v->contato_id,
                'tenant_id'      => $v->tenant_id,
                'nome_master'    => $v->contato?->nome,
                'nome_sugerido'  => $v->nome_sugerido,
                'telefone'       => $this->mascarar($v->contato?->telefone ?? '', 'telefone'),
                'origem'         => $v->contato?->origem,
            ];
        });

        return response()->json([
            'data'  => $itens,
            'total' => $pendentes->total(),
        ]);
    }

    // ── Aprovar sugestão de nome (copia para master) ──────────────────────────

    public function aprovarNome(Request $request, VinculoContatoTenant $vinculo): JsonResponse
    {
        if (! $vinculo->auditoria_pendente || ! $vinculo->nome_sugerido) {
            return response()->json(['erro' => 'Nenhuma sugestão pendente neste vínculo.'], 422);
        }

        $nomeMaster  = $vinculo->contato?->nome;
        $nomeSugerido = $vinculo->nome_sugerido;

        $vinculo->contato?->update(['nome' => $nomeSugerido]);

        $vinculo->update(['nome_sugerido' => null, 'auditoria_pendente' => false]);

        AuditLog::registrar(
            tabela:      'contatos',
            registroId:  $vinculo->contato_id,
            acao:        'aprovar_nome',
            campo:       'nome',
            valorAntigo: $nomeMaster,
            valorNovo:   $nomeSugerido,
            contexto:    ['vinculo_id' => $vinculo->id, 'tenant_id' => $vinculo->tenant_id]
        );

        return response()->json(['ok' => true]);
    }

    // ── Rejeitar sugestão de nome (descarta, mantém master) ──────────────────

    public function rejeitarNome(VinculoContatoTenant $vinculo): JsonResponse
    {
        $nomeSugerido = $vinculo->nome_sugerido;

        $vinculo->update(['nome_sugerido' => null, 'auditoria_pendente' => false]);

        AuditLog::registrar(
            tabela:      'vinculos_contato_tenant',
            registroId:  $vinculo->id,
            acao:        'rejeitar_nome',
            campo:       'nome_sugerido',
            valorAntigo: $nomeSugerido,
            valorNovo:   null,
            contexto:    ['contato_id' => $vinculo->contato_id, 'tenant_id' => $vinculo->tenant_id]
        );

        return response()->json(['ok' => true]);
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
            'telefone'         => $this->mascarar($c->telefone ?? '', 'telefone'),
            'email'            => $this->mascarar($c->email ?? '', 'email'),
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
            'telefone' => strlen($valor) >= 8
                ? str_repeat('*', strlen($valor) - 4) . substr($valor, -4)
                : $valor,
            default => $valor,
        };
    }
}
