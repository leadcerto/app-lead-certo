<?php

namespace App\Http\Controllers\Internal;

use App\Http\Controllers\Controller;
use App\Models\AgenteMinerador;
use App\Models\CampanhaMineracao;
use App\Models\Contato;
use App\Models\ContatoPendente;
use App\Models\VinculoContatoTenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MineradorController extends Controller
{
    private const LIMIAR_SIMILARIDADE = 75.0;

    /**
     * Endpoint chamado pelos agentes mineradores para gravar contatos.
     * Autenticado via X-Minerador-Key (middleware EnsureMineradorKey).
     */
    public function gravarContato(Request $request): JsonResponse
    {
        $request->validate([
            'telefone'   => 'required|string|max:20',
            'nome'       => 'nullable|string|max:200',
            'email'      => 'nullable|email|max:200',
            'empresa'    => 'nullable|string|max:200',
            'instagram'  => 'nullable|string|max:200',
            'facebook'   => 'nullable|string|max:200',
            'linkedin'   => 'nullable|string|max:200',
            'cidade'     => 'nullable|string|max:150',
            'estado'     => 'nullable|string|max:100',
            'dados_extras' => 'nullable|array',
        ]);

        $tenantId   = $request->_agente_tenant;
        $agenteId   = $request->_agente_id;
        $campanhaId = $request->_campanha_id;
        $tipo       = $request->_agente_tipo;

        $telefone = preg_replace('/\D/', '', $request->telefone);
        $nome     = $this->limparNome($request->nome ?? '');
        $origem   = 'minerador_' . ($tipo ?? 'custom');

        $contatoExistente = Contato::where('telefone', $telefone)->first();

        if (! $contatoExistente) {
            // Número novo → cria direto
            $contato = Contato::create(array_filter([
                'telefone'  => $telefone,
                'nome'      => $nome ?: null,
                'email'     => $request->email,
                'empresa'   => $request->empresa,
                'instagram' => $request->instagram,
                'facebook'  => $request->facebook,
                'linkedin'  => $request->linkedin,
                'cidade'    => $request->cidade,
                'estado'    => $request->estado,
                'origem'    => $origem,
                'opt_out'   => false,
            ]));

            $this->garantirVinculo($contato->id, $tenantId);
            $this->incrementarCampanha($campanhaId, $agenteId);

            return response()->json([
                'ok'          => true,
                'contato_id'  => $contato->id,
                'acao'        => 'criado',
            ], 201);
        }

        // Número já existe → verifica se é a mesma pessoa
        if ($nome && $contatoExistente->nome) {
            $similaridade = $this->similaridade($nome, $contatoExistente->nome);

            if ($similaridade < self::LIMIAR_SIMILARIDADE) {
                // Nomes muito diferentes → provável chip reciclado → fila de auditoria
                ContatoPendente::create([
                    'tenant_id'           => $tenantId,
                    'telefone'            => $telefone,
                    'nome'                => $nome,
                    'tipo_conflito'       => 'numero_reciclado',
                    'contato_existente_id' => $contatoExistente->id,
                    'nome_existente'      => $contatoExistente->nome,
                    'similaridade_nome'   => $similaridade,
                    'dados_brutos'        => $request->only([
                        'nome', 'email', 'empresa', 'instagram', 'facebook', 'linkedin', 'cidade', 'estado',
                    ]),
                    'status'              => 'aguardando',
                ]);

                return response()->json([
                    'ok'        => false,
                    'acao'      => 'conflito_auditoria',
                    'mensagem'  => 'Nome divergente. Enviado para auditoria humana.',
                ], 202);
            }
        }

        // Mesma pessoa → enriquece campos vazios
        $atualizar = [];
        foreach (['email', 'empresa', 'instagram', 'facebook', 'linkedin', 'cidade', 'estado'] as $campo) {
            if ($request->filled($campo) && empty($contatoExistente->$campo)) {
                $atualizar[$campo] = $request->$campo;
            }
        }
        if ($atualizar) {
            $contatoExistente->update($atualizar);
        }

        $this->garantirVinculo($contatoExistente->id, $tenantId);
        $this->incrementarCampanha($campanhaId, $agenteId);

        return response()->json([
            'ok'         => true,
            'contato_id' => $contatoExistente->id,
            'acao'       => 'enriquecido',
        ]);
    }

    /**
     * Permite ao agente consultar os alvos/configurações da sua campanha.
     */
    public function consultarCampanha(Request $request): JsonResponse
    {
        $campanhaId = $request->_campanha_id;

        if (! $campanhaId) {
            return response()->json(['erro' => 'Este agente não está associado a nenhuma campanha.'], 404);
        }

        $campanha = CampanhaMineracao::find($campanhaId);

        if (! $campanha || $campanha->status !== 'ativa') {
            return response()->json(['erro' => 'Campanha não encontrada ou inativa.'], 404);
        }

        return response()->json([
            'id'              => $campanha->id,
            'nome'            => $campanha->nome,
            'nicho'           => $campanha->nicho,
            'regiao_alvo'     => $campanha->regiao_alvo,
            'palavras_chave'  => $campanha->palavras_chave,
            'configuracoes'   => $campanha->configuracoes,
            'meta_contatos'   => $campanha->meta_contatos,
            'contatos_ja_importados' => $campanha->contatos_importados,
        ]);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function limparNome(string $nome): string
    {
        $nome = trim($nome);
        // Remove sufixo numérico apenas se houver 2+ palavras antes (ex: "João Silva 1234")
        $nome = preg_replace('/^(.+\s.+)\s\d{4}$/', '$1', $nome);
        return $nome;
    }

    private function similaridade(string $a, string $b): float
    {
        $norm = fn(string $s) => preg_replace('/[^a-z0-9]/', '', mb_strtolower(
            iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s) ?: $s
        ));

        similar_text($norm($a), $norm($b), $percent);
        return round($percent, 2);
    }

    private function garantirVinculo(int $contatoId, int $tenantId): void
    {
        VinculoContatoTenant::firstOrCreate([
            'contato_id' => $contatoId,
            'tenant_id'  => $tenantId,
        ]);
    }

    private function incrementarCampanha(?int $campanhaId, int $agenteId): void
    {
        if ($campanhaId) {
            CampanhaMineracao::where('id', $campanhaId)->increment('contatos_importados');
        }
        AgenteMinerador::where('id', $agenteId)->increment('contatos_importados');
    }
}
