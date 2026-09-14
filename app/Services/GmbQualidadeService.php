<?php

namespace App\Services;

use App\Models\GmbPost;
use App\Models\GmbQualidadeScore;
use App\Models\GoogleToken;
use App\Models\PerfilGmb;
use App\Models\Tenant;
use Carbon\Carbon;
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
                'reputacao'        => $location['sucesso']
                    ? $this->avaliarReputacaoComReviews($perfil, $location)
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
            // Fallback: a Lead Certo gerencia o GMB de vários clientes com uma
            // única conta central (Tenant::CENTRAL_ID) — não "qualquer token
            // que exista na tabela". Ver Tenant::CENTRAL_ID para o porquê.
            $token = GoogleToken::withoutGlobalScopes()->where('tenant_id', Tenant::CENTRAL_ID)->first();

            if ($token) {
                Log::warning('GmbQualidadeService: usando token Google da conta central (fallback)', [
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
            return ['sucesso' => true, 'dados' => $res->json(), 'token' => $token, 'location_id' => $locationId];
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

    private function buscarDadosReviews(GoogleToken $token, string $locationId): array
    {
        $accountRes = Http::withToken($token->access_token)
            ->timeout(15)
            ->get('https://mybusinessaccountmanagement.googleapis.com/v1/accounts');

        if ($accountRes->status() === 429 || str_contains($accountRes->body(), 'Quota exceeded')) {
            Log::warning('Reviews API falhou (contas)', ['status' => 429, 'response' => $accountRes->body()]);
            return ['sucesso' => false, 'motivo' => 'Google retornou 429 (Quota excedida) ao buscar a conta para as avaliações. Tente novamente em alguns instantes.'];
        }

        if ($accountRes->status() === 403) {
            $erroGoogle = $accountRes->json('error.message') ?? $accountRes->body();
            $motivo = (str_contains($erroGoogle, 'SERVICE_DISABLED') || str_contains($erroGoogle, 'has not been used in project'))
                ? 'A API "My Business Account Management API" precisa ser ativada no Google Cloud Console: https://console.developers.google.com/apis/api/mybusinessaccountmanagement.googleapis.com/overview?project=159179119828'
                : 'Permissão do Google Meu Negócio pendente para ler as avaliações. Reconecte a conta Google em "Integrações". Detalhes: ' . $erroGoogle;

            Log::warning('Reviews API falhou (contas)', ['status' => 403, 'response' => $accountRes->body()]);
            return ['sucesso' => false, 'motivo' => $motivo];
        }

        if (! $accountRes->successful()) {
            Log::warning('Reviews API falhou (contas)', ['status' => $accountRes->status(), 'response' => $accountRes->body()]);
            return ['sucesso' => false, 'motivo' => "Erro Google ({$accountRes->status()}) ao buscar a conta para as avaliações: " . ($accountRes->json('error.message') ?? $accountRes->body())];
        }

        $accountName = $accountRes->json('accounts.0.name');
        if (! $accountName) {
            return ['sucesso' => false, 'motivo' => 'Nenhuma conta do Google Meu Negócio encontrada para buscar as avaliações.'];
        }

        $res = Http::withToken($token->access_token)
            ->timeout(15)
            ->get("https://mybusiness.googleapis.com/v4/{$accountName}/locations/{$locationId}/reviews", [
                'pageSize' => 20,
                'orderBy'  => 'updateTime desc',
            ]);

        if ($res->successful()) {
            return ['sucesso' => true, 'dados' => $res->json()];
        }

        $status = $res->status();
        $erroGoogle = $res->json('error.message') ?? $res->body();

        Log::warning('Reviews API falhou', ['status' => $status, 'response' => $res->body()]);

        if ($status === 403 && (str_contains($erroGoogle, 'SERVICE_DISABLED') || str_contains($erroGoogle, 'has not been used in project'))) {
            return ['sucesso' => false, 'motivo' => 'A API "Google My Business API" precisa ser ativada no Google Cloud Console para ler as avaliações: https://console.developers.google.com/apis/api/mybusiness.googleapis.com/overview?project=159179119828'];
        }

        if ($status === 403) {
            return ['sucesso' => false, 'motivo' => 'Permissão do Google Meu Negócio pendente para ler as avaliações. Reconecte a conta Google em "Integrações". Detalhes: ' . $erroGoogle];
        }

        if ($status === 404) {
            return ['sucesso' => false, 'motivo' => "Google retornou 404 ao buscar avaliações: localização não encontrada para o ID '{$locationId}'."];
        }

        if ($status === 429 || str_contains($erroGoogle, 'Quota exceeded')) {
            return ['sucesso' => false, 'motivo' => 'Google retornou 429 (Quota excedida) ao buscar as avaliações. Tente novamente em alguns instantes.'];
        }

        return ['sucesso' => false, 'motivo' => "Erro Google ({$status}) ao buscar avaliações: {$erroGoogle}"];
    }

    private function avaliarReputacaoComReviews(PerfilGmb $perfil, array $location): array
    {
        $reviews = $this->buscarDadosReviews($location['token'], $location['location_id']);

        return $reviews['sucesso']
            ? $this->avaliarReputacao($reviews['dados'], $location['dados'], $perfil)
            : $this->categoriaErro(self::CATEGORIAS_LABELS['reputacao'], $reviews['motivo']);
    }

    private function avaliarReputacao(array $dadosReviews, array $dadosLocation, PerfilGmb $perfil): array
    {
        $totalReviews = $dadosReviews['totalReviewCount'] ?? 0;

        if ($totalReviews === 0) {
            return [
                'nota'   => 0,
                'status' => 'calculado',
                'label'  => self::CATEGORIAS_LABELS['reputacao'],
                'diagnosticos' => [[
                    'tipo'       => 'erro',
                    'mensagem'   => 'Nenhuma avaliação registrada nesta ficha ainda. Comece a coletar avaliações de clientes reais.',
                    'acao_label' => 'Ver na Apostila',
                    'acao_url'   => route('admin.gmb-apostila.index') . '#pilares',
                ]],
            ];
        }

        $reviews = $dadosReviews['reviews'] ?? [];
        $acaoManual = ['acao_label' => 'Abrir Google Business Profile Manager', 'acao_url' => 'https://business.google.com/'];
        $pontos = 0;
        $diagnosticos = [];

        // 1. Volume
        if ($totalReviews >= 50) {
            $pontos += 25;
            $diagnosticos[] = ['tipo' => 'ok', 'mensagem' => "{$totalReviews} avaliações no total.", 'acao_label' => null, 'acao_url' => null];
        } elseif ($totalReviews >= 10) {
            $pontos += 15;
            $diagnosticos[] = array_merge(['tipo' => 'aviso', 'mensagem' => "{$totalReviews} avaliações no total; o ideal é pelo menos 50."], $acaoManual);
        } else {
            $pontos += 5;
            $diagnosticos[] = array_merge(['tipo' => 'aviso', 'mensagem' => "Só {$totalReviews} avaliação(ões); o ideal é pelo menos 50."], $acaoManual);
        }

        // 2. Recorrência — max(createTime) entre os reviews retornados (pagina ordenada por updateTime desc,
        // ver spec pra por que nao confiamos em reviews[0] diretamente)
        $datasCriacao = array_filter(array_map(fn ($r) => $r['createTime'] ?? null, $reviews));
        $maisRecente = ! empty($datasCriacao) ? Carbon::parse(max($datasCriacao)) : null;
        $diasDesdeUltima = $maisRecente ? (int) $maisRecente->diffInDays(now()) : null;

        if ($diasDesdeUltima !== null && $diasDesdeUltima <= 7) {
            $pontos += 25;
            $diagnosticos[] = ['tipo' => 'ok', 'mensagem' => "Avaliação mais recente há {$diasDesdeUltima} dia(s) — dentro do ideal (a cada 7 dias).", 'acao_label' => null, 'acao_url' => null];
        } else {
            $textoData = $diasDesdeUltima !== null ? "{$diasDesdeUltima} dias" : 'muito tempo';
            $diagnosticos[] = ['tipo' => 'aviso', 'mensagem' => "Avaliação mais recente há {$textoData}. O Google valoriza recência mais que volume total — priorize pedir novas avaliações.", 'acao_label' => null, 'acao_url' => null];
        }

        // 3a. Nota média
        $notaMedia = (float) ($dadosReviews['averageRating'] ?? 0);
        $notaMediaFormatada = number_format($notaMedia, 1, ',', '');
        if ($notaMedia >= 4.5) {
            $pontos += 15;
            $diagnosticos[] = ['tipo' => 'ok', 'mensagem' => "Nota média {$notaMediaFormatada} — acima de 4.5.", 'acao_label' => null, 'acao_url' => null];
        } elseif ($notaMedia >= 4.0) {
            $pontos += 10;
            $diagnosticos[] = ['tipo' => 'aviso', 'mensagem' => "Nota média {$notaMediaFormatada}; o ideal é 4.5 ou mais.", 'acao_label' => null, 'acao_url' => null];
        } else {
            $diagnosticos[] = ['tipo' => 'erro', 'mensagem' => "Nota média {$notaMediaFormatada} — abaixo de 4.0. Revise o atendimento antes de acelerar o volume de avaliações.", 'acao_label' => null, 'acao_url' => null];
        }

        // Palavras-chave: categoria principal + secundarias + cidade do perfil
        $palavrasChave = array_values(array_filter(array_merge(
            [$dadosLocation['categories']['primaryCategory']['displayName'] ?? null],
            array_column($dadosLocation['categories']['additionalCategories'] ?? [], 'displayName'),
            [$perfil->city]
        )));

        $contemPalavraChave = function (?string $texto) use ($palavrasChave): bool {
            if (empty($texto)) {
                return false;
            }
            foreach ($palavrasChave as $palavra) {
                if (mb_stripos($texto, $palavra) !== false) {
                    return true;
                }
            }
            return false;
        };

        // 3b. Menção a categoria/cidade nos comentários
        $totalAmostra = count($reviews);
        $comMencao = collect($reviews)->filter(fn ($r) => $contemPalavraChave($r['comment'] ?? null))->count();
        $percentualMencao = $totalAmostra > 0 ? (int) round(($comMencao / $totalAmostra) * 100) : 0;

        if ($percentualMencao >= 50) {
            $pontos += 10;
            $diagnosticos[] = ['tipo' => 'ok', 'mensagem' => "{$percentualMencao}% das avaliações recentes citam o serviço ou a cidade.", 'acao_label' => null, 'acao_url' => null];
        } else {
            $diagnosticos[] = ['tipo' => 'aviso', 'mensagem' => "Só {$percentualMencao}% das avaliações recentes citam o serviço ou a cidade; estimule o cliente a mencionar o que foi feito e onde.", 'acao_label' => null, 'acao_url' => null];
        }

        // 4a. Taxa de resposta
        $respondidas = collect($reviews)->filter(fn ($r) => ! empty($r['reviewReply']))->values();
        $percentualRespondido = $totalAmostra > 0 ? (int) round(($respondidas->count() / $totalAmostra) * 100) : 0;

        if ($percentualRespondido >= 80) {
            $pontos += 10;
            $diagnosticos[] = ['tipo' => 'ok', 'mensagem' => "{$percentualRespondido}% das avaliações recentes têm resposta do dono.", 'acao_label' => null, 'acao_url' => null];
        } elseif ($percentualRespondido >= 1) {
            $pontos += 5;
            $diagnosticos[] = ['tipo' => 'aviso', 'mensagem' => "Só {$percentualRespondido}% das avaliações recentes foram respondidas; o ideal é responder 100%.", 'acao_label' => null, 'acao_url' => null];
        } else {
            $diagnosticos[] = ['tipo' => 'erro', 'mensagem' => '0% das avaliações recentes foram respondidas; o ideal é responder 100%.', 'acao_label' => null, 'acao_url' => null];
        }

        // 4b. Prazo médio de resposta — só entre as respondidas
        if ($respondidas->isNotEmpty()) {
            $horasSoma = $respondidas->sum(function ($r) {
                $criada = Carbon::parse($r['createTime']);
                $respondida = Carbon::parse($r['reviewReply']['updateTime']);
                return $criada->diffInHours($respondida);
            });
            $horasMedia = (int) round($horasSoma / $respondidas->count());

            if ($horasMedia <= 48) {
                $pontos += 10;
                $diagnosticos[] = ['tipo' => 'ok', 'mensagem' => "Tempo médio de resposta: {$horasMedia}h — dentro do ideal (até 48h).", 'acao_label' => null, 'acao_url' => null];
            } else {
                $diagnosticos[] = ['tipo' => 'aviso', 'mensagem' => "Tempo médio de resposta: {$horasMedia}h; o ideal é até 48h.", 'acao_label' => null, 'acao_url' => null];
            }
        } else {
            $diagnosticos[] = ['tipo' => 'erro', 'mensagem' => 'Nenhuma avaliação recente foi respondida. Responda o quanto antes — o ideal é em até 48h.', 'acao_label' => null, 'acao_url' => null];
        }

        // 4c. Termos nas respostas
        $respostaComTermo = $respondidas->contains(fn ($r) => $contemPalavraChave($r['reviewReply']['comment'] ?? null));

        if ($respostaComTermo) {
            $pontos += 5;
            $diagnosticos[] = ['tipo' => 'ok', 'mensagem' => 'Pelo menos uma resposta recente cita o serviço ou a cidade.', 'acao_label' => null, 'acao_url' => null];
        } else {
            $diagnosticos[] = ['tipo' => 'aviso', 'mensagem' => 'Nenhuma resposta recente cita o serviço ou a cidade — respostas genéricas perdem força. Cite o serviço e o nome do cliente quando possível.', 'acao_label' => null, 'acao_url' => null];
        }

        return [
            'nota'         => $pontos,
            'status'       => 'calculado',
            'label'        => self::CATEGORIAS_LABELS['reputacao'],
            'diagnosticos' => $diagnosticos,
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
            $diagnosticos[] = ['tipo' => 'ok', 'mensagem' => "Categoria principal definida: {$categoriaPrimaria}.", 'acao_label' => null, 'acao_url' => null];
        } else {
            $diagnosticos[] = array_merge([
                'tipo'     => 'erro',
                'mensagem' => 'Categoria principal não definida na ficha. É o critério de ranqueamento mais importante do Google.',
            ], $acaoManual);
        }

        $secundarias = count($dados['categories']['additionalCategories'] ?? []);
        if ($secundarias >= 3 && $secundarias <= 5) {
            $pontos += 20;
            $diagnosticos[] = ['tipo' => 'ok', 'mensagem' => "{$secundarias} categorias secundárias cadastradas (ideal: 3 a 5).", 'acao_label' => null, 'acao_url' => null];
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
            $diagnosticos[] = ['tipo' => 'ok', 'mensagem' => 'Nome da ficha sem termos extras além do nome do negócio.', 'acao_label' => null, 'acao_url' => null];
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
            $diagnosticos[] = ['tipo' => 'ok', 'mensagem' => "Descrição com {$tamanhoDescricao} caracteres (ideal: 150+).", 'acao_label' => null, 'acao_url' => null];
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
                'addressLines'       => ['Linhas de endereço cadastradas.', 'Linhas de endereço não cadastradas.'],
                'locality'           => ['Cidade cadastrada.', 'Cidade não cadastrada.'],
                'administrativeArea' => ['Estado cadastrado.', 'Estado não cadastrado.'],
                'postalCode'         => ['CEP cadastrado.', 'CEP não cadastrado.'],
                'regionCode'         => ['País cadastrado.', 'País não cadastrado.'],
            ];
            $pontos = 0;
            $diagnosticos = [];
            foreach ($campos as $chave => [$mensagemOk, $mensagemFaltando]) {
                if (! empty($storefront[$chave])) {
                    $pontos += 20;
                    $diagnosticos[] = ['tipo' => 'ok', 'mensagem' => $mensagemOk, 'acao_label' => null, 'acao_url' => null];
                } else {
                    $diagnosticos[] = array_merge(['tipo' => 'aviso', 'mensagem' => $mensagemFaltando], $acaoManual);
                }
            }

            return [
                'nota'         => $pontos,
                'status'       => 'calculado',
                'label'        => self::CATEGORIAS_LABELS['localizacao'],
                'diagnosticos' => $diagnosticos,
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
