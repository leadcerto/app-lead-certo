<?php

namespace App\Services;

use App\Models\GmbPost;
use App\Models\GmbQualidadeScore;
use App\Models\GoogleToken;
use App\Models\PerfilGmb;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

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
        $location = $this->buscarDadosLocation($perfil);

        foreach (self::CATEGORIAS_LABELS as $chave => $label) {
            $categorias[$chave] = match ($chave) {
                'atividade'        => $this->avaliarAtividade($perfil),
                'identidade'       => $location['sucesso']
                    ? $this->avaliarIdentidade($location['dados'])
                    : $this->categoriaErro($label, $location['motivo']),
                'localizacao'      => $location['sucesso']
                    ? $this->avaliarLocalizacao($location['dados'])
                    : $this->categoriaErro($label, $location['motivo']),
                'presenca_externa' => $location['sucesso']
                    ? $this->avaliarPresencaExterna($location['dados'])
                    : $this->categoriaErro($label, $location['motivo']),
                'saude_risco'      => $location['sucesso']
                    ? $this->avaliarSaudeRisco($location['dados'])
                    : $this->categoriaErro($label, $location['motivo']),
                default => $this->categoriaPendente($label),
            };
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

    private function buscarDadosLocation(PerfilGmb $perfil): array
    {
        if (empty($perfil->google_location_id)) {
            return [
                'sucesso' => false,
                'motivo'  => "O perfil '{$perfil->nome}' não possui o 'ID do Perfil no Google' cadastrado. Acesse GMB → Perfis GMB, edite este perfil e preencha o ID da empresa no Google.",
            ];
        }

        $token = GoogleToken::withoutGlobalScopes()->where('tenant_id', $perfil->tenant_id)->first();

        if (! $token) {
            $token = GoogleToken::withoutGlobalScopes()->orderBy('id')->first();

            if ($token) {
                Log::warning('GmbQualidadeService: usando token Google de outro tenant (fallback)', [
                    'perfil_id'        => $perfil->id,
                    'perfil_tenant_id' => $perfil->tenant_id,
                    'token_tenant_id'  => $token->tenant_id,
                ]);
            }
        }

        if (! $token) {
            return [
                'sucesso' => false,
                'motivo'  => 'Nenhuma conta Google conectada encontrada. Acesse o menu "Integrações" e conecte a conta Google que gerencia os perfis GMB.',
            ];
        }

        if ($token->expires_at && $token->expires_at->isPast()) {
            if (! app(GoogleService::class)->renovarToken($token)) {
                return [
                    'sucesso' => false,
                    'motivo'  => 'Não foi possível renovar o acesso à conta Google (autorização expirada ou revogada). Reconecte a conta Google em "Integrações".',
                ];
            }
            $token->refresh();
        }

        $locationId = preg_replace('#^locations/#', '', trim($perfil->google_location_id));

        $res = Http::withToken($token->access_token)
            ->timeout(15)
            ->get("https://mybusinessbusinessinformation.googleapis.com/v1/locations/" . rawurlencode($locationId), [
                'readMask' => 'categories,title,profile,storefrontAddress,serviceArea,websiteUri,regularHours,specialHours,phoneNumbers',
            ]);

        if ($res->successful()) {
            return ['sucesso' => true, 'dados' => $res->json()];
        }

        $status = $res->status();
        $erroGoogle = $res->json('error.message') ?? $res->body();

        Log::warning('Business Information API falhou', [
            'perfil_id' => $perfil->id,
            'status'    => $status,
            'response'  => $res->body(),
        ]);

        if ($status === 403 && (str_contains($erroGoogle, 'SERVICE_DISABLED') || str_contains($erroGoogle, 'has not been used in project'))) {
            return [
                'sucesso' => false,
                'motivo'  => 'A API "Business Information API" precisa ser ativada no Google Cloud Console: https://console.developers.google.com/apis/api/mybusinessbusinessinformation.googleapis.com/overview?project=159179119828',
            ];
        }

        if ($status === 429 || str_contains($erroGoogle, 'Quota exceeded') || str_contains($erroGoogle, 'rateLimitExceeded') || str_contains($erroGoogle, 'RESOURCE_EXHAUSTED')) {
            return [
                'sucesso' => false,
                'motivo'  => 'Google retornou 429 (Quota excedida). Tente novamente em alguns instantes.',
            ];
        }

        if ($status === 403) {
            return [
                'sucesso' => false,
                'motivo'  => 'Permissão do Google Meu Negócio pendente para ler dados da ficha. Reconecte a conta Google em "Integrações". Detalhes: ' . $erroGoogle,
            ];
        }

        if ($status === 404) {
            return [
                'sucesso' => false,
                'motivo'  => "Google retornou 404: Localização não encontrada para o ID '{$locationId}'. Verifique o ID do Perfil da Empresa em GMB → Perfis GMB.",
            ];
        }

        return [
            'sucesso' => false,
            'motivo'  => "Erro Google ({$status}): {$erroGoogle}",
        ];
    }

    private function avaliarIdentidade(array $dados): array
    {
        $pontos = 0;
        $diagnosticos = [];
        $acaoManual = ['acao_label' => 'Abrir Google Business Profile Manager', 'acao_url' => 'https://business.google.com/'];

        $categoriaPrimaria = $dados['categories']['primaryCategory']['displayName'] ?? null;
        if (! empty($categoriaPrimaria)) {
            $pontos += 40;
        } else {
            $diagnosticos[] = array_merge([
                'tipo'     => 'erro',
                'mensagem' => 'Categoria principal não definida na ficha. É o critério de ranqueamento mais importante do Google.',
            ], $acaoManual);
        }

        $secundarias = count($dados['categories']['additionalCategories'] ?? []);
        if ($secundarias >= 3 && $secundarias <= 5) {
            $pontos += 20;
        } elseif ($secundarias >= 1 && $secundarias <= 2) {
            $pontos += 10;
            $diagnosticos[] = array_merge([
                'tipo'     => 'aviso',
                'mensagem' => "Você tem {$secundarias} categoria(s) secundária(s); o ideal é entre 3 e 5.",
            ], $acaoManual);
        } elseif ($secundarias >= 6) {
            $pontos += 15;
            $diagnosticos[] = array_merge([
                'tipo'     => 'aviso',
                'mensagem' => "Você tem {$secundarias} categorias secundárias; o ideal é entre 3 e 5.",
            ], $acaoManual);
        } else {
            $diagnosticos[] = array_merge([
                'tipo'     => 'aviso',
                'mensagem' => 'Nenhuma categoria secundária cadastrada; o ideal é entre 3 e 5.',
            ], $acaoManual);
        }

        $titulo = $dados['title'] ?? '';
        $temSeparadorSuspeito = str_contains($titulo, '|') || str_contains($titulo, '•')
            || str_contains($titulo, ':') || str_contains($titulo, ' - ');
        if (! $temSeparadorSuspeito) {
            $pontos += 20;
        } else {
            $diagnosticos[] = array_merge([
                'tipo'     => 'aviso',
                'mensagem' => "O nome da ficha parece conter termos extras além do nome real do negócio (ex: separadores como '|', '•', ':' ou ' - '). O Google pode suspender fichas com nome fora do padrão.",
            ], $acaoManual);
        }

        $descricao = $dados['profile']['description'] ?? '';
        $tamanhoDescricao = mb_strlen($descricao);
        if ($tamanhoDescricao >= 150) {
            $pontos += 20;
        } elseif ($tamanhoDescricao >= 1) {
            $pontos += 10;
            $diagnosticos[] = array_merge([
                'tipo'     => 'aviso',
                'mensagem' => "Descrição com apenas {$tamanhoDescricao} caractere(s); o ideal é pelo menos 150.",
            ], $acaoManual);
        } else {
            $diagnosticos[] = array_merge([
                'tipo'     => 'erro',
                'mensagem' => 'Descrição ausente. Escreva uma descrição de pelo menos 150 caracteres direto no Google Business Profile Manager.',
            ], $acaoManual);
        }

        if (empty($diagnosticos)) {
            $diagnosticos[] = ['tipo' => 'ok', 'mensagem' => 'Categoria, categorias secundárias, nome e descrição bem preenchidos.', 'acao_label' => null, 'acao_url' => null];
        }

        return [
            'nota'         => $pontos,
            'status'       => 'calculado',
            'label'        => self::CATEGORIAS_LABELS['identidade'],
            'diagnosticos' => $diagnosticos,
        ];
    }

    private function avaliarLocalizacao(array $dados): array
    {
        $storefront = $dados['storefrontAddress'] ?? [];
        $serviceArea = $dados['serviceArea'] ?? [];
        $acaoManual = ['acao_label' => 'Abrir Google Business Profile Manager', 'acao_url' => 'https://business.google.com/'];

        $temStorefront = ! empty($storefront['addressLines']) || ! empty($storefront['locality']);
        $temServiceArea = ! empty($serviceArea);

        if (! $temStorefront && ! $temServiceArea) {
            return [
                'nota'   => 0,
                'status' => 'calculado',
                'label'  => self::CATEGORIAS_LABELS['localizacao'],
                'diagnosticos' => [array_merge([
                    'tipo'     => 'erro',
                    'mensagem' => 'Nenhum endereço público nem área de atendimento configurados nesta ficha.',
                ], $acaoManual)],
            ];
        }

        if ($temStorefront) {
            $campos = [
                'addressLines'       => 'linhas de endereço',
                'locality'           => 'cidade',
                'administrativeArea' => 'estado',
                'postalCode'         => 'CEP',
                'regionCode'         => 'país',
            ];
            $pontos = 0;
            $faltando = [];
            foreach ($campos as $chave => $rotulo) {
                if (! empty($storefront[$chave])) {
                    $pontos += 20;
                } else {
                    $faltando[] = $rotulo;
                }
            }

            if (empty($faltando)) {
                return [
                    'nota'   => 100,
                    'status' => 'calculado',
                    'label'  => self::CATEGORIAS_LABELS['localizacao'],
                    'diagnosticos' => [['tipo' => 'ok', 'mensagem' => 'Endereço completo.', 'acao_label' => null, 'acao_url' => null]],
                ];
            }

            return [
                'nota'   => $pontos,
                'status' => 'calculado',
                'label'  => self::CATEGORIAS_LABELS['localizacao'],
                'diagnosticos' => [array_merge([
                    'tipo'     => 'aviso',
                    'mensagem' => 'Endereço incompleto — faltam: ' . implode(', ', $faltando) . '.',
                ], $acaoManual)],
            ];
        }

        $pontos = 0;
        $faltando = [];
        if (! empty($serviceArea['businessType'])) {
            $pontos += 50;
        } else {
            $faltando[] = 'tipo de negócio';
        }
        if (! empty($serviceArea['places']['placeInfos'])) {
            $pontos += 50;
        } else {
            $faltando[] = 'lista de áreas atendidas';
        }

        if (empty($faltando)) {
            return [
                'nota'   => 100,
                'status' => 'calculado',
                'label'  => self::CATEGORIAS_LABELS['localizacao'],
                'diagnosticos' => [['tipo' => 'ok', 'mensagem' => 'Área de atendimento completa.', 'acao_label' => null, 'acao_url' => null]],
            ];
        }

        return [
            'nota'   => $pontos,
            'status' => 'calculado',
            'label'  => self::CATEGORIAS_LABELS['localizacao'],
            'diagnosticos' => [array_merge([
                'tipo'     => $pontos === 0 ? 'erro' : 'aviso',
                'mensagem' => 'Área de atendimento incompleta — faltam: ' . implode(', ', $faltando) . '.',
            ], $acaoManual)],
        ];
    }

    private function avaliarPresencaExterna(array $dados): array
    {
        $website = $dados['websiteUri'] ?? '';
        $diagnosticos = [];

        if (! empty($website)) {
            $nota = 100;
            $diagnosticos[] = ['tipo' => 'ok', 'mensagem' => 'Site vinculado à ficha.', 'acao_label' => null, 'acao_url' => null];
        } else {
            $nota = 0;
            $diagnosticos[] = [
                'tipo'       => 'erro',
                'mensagem'   => 'Nenhum site cadastrado na ficha.',
                'acao_label' => 'Adicionar site no Google Business Profile Manager',
                'acao_url'   => 'https://business.google.com/',
            ];
        }

        $diagnosticos[] = [
            'tipo'       => 'info',
            'mensagem'   => 'Lembrete: adicione o Schema.org (LocalBusiness) no site vinculado para reforçar a ficha para o Google.',
            'acao_label' => 'Ver exemplo na Apostila',
            'acao_url'   => route('admin.gmb-apostila.index'),
        ];

        return [
            'nota'         => $nota,
            'status'       => 'calculado',
            'label'        => self::CATEGORIAS_LABELS['presenca_externa'],
            'diagnosticos' => $diagnosticos,
        ];
    }

    private function avaliarSaudeRisco(array $dados): array
    {
        $periods = $dados['regularHours']['periods'] ?? [];
        $diagnosticos = [];

        if (! empty($periods)) {
            $nota = 100;
            $diagnosticos[] = ['tipo' => 'ok', 'mensagem' => 'Horário de funcionamento cadastrado.', 'acao_label' => null, 'acao_url' => null];
        } else {
            $nota = 0;
            $diagnosticos[] = [
                'tipo'       => 'erro',
                'mensagem'   => 'Horário de funcionamento não cadastrado na ficha.',
                'acao_label' => 'Abrir Google Business Profile Manager',
                'acao_url'   => 'https://business.google.com/',
            ];
        }

        $diagnosticos[] = [
            'tipo'       => 'info',
            'mensagem'   => 'Verifique periodicamente se há edições sugeridas por terceiros pendentes de revisão no Google Business Profile Manager.',
            'acao_label' => 'Abrir Google Business Profile Manager',
            'acao_url'   => 'https://business.google.com/',
        ];

        return [
            'nota'         => $nota,
            'status'       => 'calculado',
            'label'        => self::CATEGORIAS_LABELS['saude_risco'],
            'diagnosticos' => $diagnosticos,
        ];
    }

    private function categoriaErro(string $label, string $motivo): array
    {
        return [
            'nota'   => null,
            'status' => 'erro',
            'label'  => $label,
            'diagnosticos' => [[
                'tipo'       => 'erro',
                'mensagem'   => $motivo,
                'acao_label' => null,
                'acao_url'   => null,
            ]],
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
