<?php

namespace App\Services;

use App\Models\GmbPost;
use App\Models\GmbQualidadeScore;
use App\Models\PerfilGmb;

class GmbQualidadeService
{
    /**
     * Ordem em que as categorias aparecem na tela — mantida fixa aqui pra
     * garantir que a UI (Task 3) sempre encontre as 7 chaves, mesmo antes
     * de cada categoria estar implementada de verdade.
     */
    private const CATEGORIAS_LABELS = [
        'atividade'        => 'Atividade',
        'identidade'       => 'Identidade',
        'localizacao'      => 'Localização',
        'conteudo'         => 'Conteúdo',
        'reputacao'        => 'Reputação',
        'presenca_externa' => 'Presença Externa',
        'saude_risco'      => 'Saúde/Risco',
    ];

    public function avaliar(PerfilGmb $perfil): GmbQualidadeScore
    {
        $categorias = [];

        foreach (self::CATEGORIAS_LABELS as $chave => $label) {
            $categorias[$chave] = $chave === 'atividade'
                ? $this->avaliarAtividade($perfil)
                : $this->categoriaPendente($label);
        }

        $notasCalculadas = collect($categorias)
            ->where('status', 'calculado')
            ->pluck('nota');

        $notaGeral = $notasCalculadas->isNotEmpty()
            ? (int) round($notasCalculadas->avg())
            : null;

        return GmbQualidadeScore::withoutGlobalScopes()->updateOrCreate(
            ['perfil_gmb_id' => $perfil->id],
            [
                'tenant_id'   => $perfil->tenant_id,
                'nota_geral'  => $notaGeral,
                'categorias'  => $categorias,
                'avaliado_em' => now(),
            ]
        );
    }

    private function avaliarAtividade(PerfilGmb $perfil): array
    {
        $ultimoPost = GmbPost::withoutGlobalScopes()
            ->where('perfil_gmb_id', $perfil->id)
            ->where('status', 'publicado')
            ->orderByDesc('publicado_em')
            ->first();

        $acaoCriarPost = [
            'acao_label' => 'Criar publicação agora',
            'acao_url'   => route('admin.gmb-posts.create'),
        ];

        if (! $ultimoPost || ! $ultimoPost->publicado_em) {
            return [
                'nota'   => 0,
                'status' => 'calculado',
                'label'  => self::CATEGORIAS_LABELS['atividade'],
                'diagnosticos' => [array_merge([
                    'tipo'     => 'erro',
                    'mensagem' => 'Nenhuma postagem publicada ainda neste perfil. O Google reduz a relevância de fichas sem atividade recente.',
                ], $acaoCriarPost)],
            ];
        }

        $diasSemPost = (int) $ultimoPost->publicado_em->diffInDays(now());

        if ($diasSemPost <= 7) {
            return [
                'nota'   => 100,
                'status' => 'calculado',
                'label'  => self::CATEGORIAS_LABELS['atividade'],
                'diagnosticos' => [[
                    'tipo'        => 'ok',
                    'mensagem'    => "Último post publicado há {$diasSemPost} dia(s) — dentro do ritmo recomendado (ao menos 1x por semana).",
                    'acao_label'  => null,
                    'acao_url'    => null,
                ]],
            ];
        }

        if ($diasSemPost <= 14) {
            return [
                'nota'   => 60,
                'status' => 'calculado',
                'label'  => self::CATEGORIAS_LABELS['atividade'],
                'diagnosticos' => [array_merge([
                    'tipo'     => 'aviso',
                    'mensagem' => "Último post foi há {$diasSemPost} dias. O ideal é postar ao menos 1x por semana.",
                ], $acaoCriarPost)],
            ];
        }

        return [
            'nota'   => 20,
            'status' => 'calculado',
            'label'  => self::CATEGORIAS_LABELS['atividade'],
            'diagnosticos' => [array_merge([
                'tipo'     => 'erro',
                'mensagem' => "Último post foi há {$diasSemPost} dias. Perfis parados perdem relevância no Google.",
            ], $acaoCriarPost)],
        ];
    }

    private function categoriaPendente(string $label): array
    {
        return [
            'nota'   => null,
            'status' => 'pendente',
            'label'  => $label,
            'diagnosticos' => [[
                'tipo'        => 'pendente',
                'mensagem'    => 'Essa categoria ainda não está disponível — chega em uma próxima atualização.',
                'acao_label'  => null,
                'acao_url'    => null,
            ]],
        ];
    }
}
