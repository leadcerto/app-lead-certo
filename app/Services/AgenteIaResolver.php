<?php

namespace App\Services;

use App\Models\Cargo;
use App\Models\User;

class AgenteIaResolver
{
    /**
     * Mapeamento de origens / módulos da plataforma para as funções (Cargos) da equipe.
     */
    public const MAPA_ORIGEM_CARGO = [
        // Comercial & SDR
        'sdr_resposta'                  => 'Gestor Comercial',
        'sdr_duvida'                    => 'Gestor Comercial',
        'followup_conversas'            => 'Gestor Comercial',
        'gestor_kanban'                 => 'Gestor Comercial',
        'gestor_kanban_semanal'         => 'Gestor Comercial',
        'kanban_monitorar'              => 'Gestor Comercial',
        'avaliar_objetivos'             => 'Gestor Comercial',
        'mentor_02'                     => 'Mentor 02 — Comercial 1 (Primeira Venda)',
        'mentor_03'                     => 'Mentor 03 — Comercial 2 (LTV & Recorrência)',
        'mentor_04'                     => 'Mentor 04 — Pós-Venda & Indicações',
        'mentor_05'                     => 'Mentor 05 — Recuperação & Troca',

        // Marketing & Copywriting & Criação
        'sequencia_variacao_individual' => 'Gestor de Copywriting',
        'sequencia_variacao_lote'       => 'Gestor de Copywriting',
        'template_avaliacao'            => 'Diretora de Marketing',
        'gmb_post'                      => 'Diretora de Marketing',
        'gmb_post_imagem'               => 'Gestor de Criação',
        'seo_otimizacao'                => 'Gestor de SEO',

        // Inteligência, Auditoria & Tradução
        'traducao'                      => 'Intérprete Multilíngue IA',
        'transcricao'                   => 'Intérprete Multilíngue IA',
        'qa_auditoria'                  => 'Orquestrador Geral IA',
        'resumo_ticket'                 => 'Orquestrador Geral IA',
        'supervisao_geral'              => 'Orquestrador Geral IA',

        // Auditoria & Gestão de Contatos
        'identificar_nomes'             => 'Auditor & Gestor de Contatos IA',
        'limpar_nomes'                  => 'Auditor & Gestor de Contatos IA',
        'enriquecer_contatos'           => 'Auditor & Gestor de Contatos IA',
        'contatos_auditoria'            => 'Auditor & Gestor de Contatos IA',

        // Suporte
        'suporte_ticket'                => 'Gerente de Suporte',
        'atendimento_suporte'           => 'Gerente de Suporte',
    ];

    /**
     * Resolve o ID do Agente de IA correspondente à funcionalidade executada.
     */
    public static function resolverAgenteId(?string $origem = null, ?int $tenantId = null): ?int
    {
        if ($origem && isset(self::MAPA_ORIGEM_CARGO[$origem])) {
            $nomeCargo = self::MAPA_ORIGEM_CARGO[$origem];
            $cargo = Cargo::where('nome', $nomeCargo)->with('agentes')->first();
            $agente = $cargo?->agentes?->where('is_ia', true)->first();
            if ($agente) {
                return $agente->id;
            }
        }

        // Se a origem contiver pistas no texto
        if ($origem) {
            $origemLower = strtolower($origem);
            if (str_contains($origemLower, 'kanban') || str_contains($origemLower, 'sdr') || str_contains($origemLower, 'comercial')) {
                $nathanel = User::where('email', 'like', '%nathanel%')->where('is_ia', true)->value('id');
                if ($nathanel) return $nathanel;
            }
            if (str_contains($origemLower, 'contato') || str_contains($origemLower, 'nome') || str_contains($origemLower, 'google')) {
                $atlas = User::where('email', 'like', '%atlas%')->where('is_ia', true)->value('id');
                if ($atlas) return $atlas;
            }
            if (str_contains($origemLower, 'traduc') || str_contains($origemLower, 'transcr')) {
                $leo = User::where('email', 'like', '%leo%')->where('is_ia', true)->value('id');
                if ($leo) return $leo;
            }
            if (str_contains($origemLower, 'suporte')) {
                $adriana = User::where('email', 'like', '%adriana%')->where('is_ia', true)->value('id');
                if ($adriana) return $adriana;
            }
        }

        // Fallback para o primeiro agente IA ativo
        return User::where('is_ia', true)->value('id');
    }
}
