<?php

namespace App\Services;

use App\Models\GmbPost;
use App\Models\GoogleToken;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * GmbPostPublishService
 * 
 * Responsável por publicar postagens na API do Google Meu Negócio (Google Business Profile)
 * ou repassar para automação n8n / gateway.
 */
class GmbPostPublishService
{
    public function __construct(private GoogleService $googleService) {}

    /**
     * Executa a publicação de um post agendado.
     */
    public function publicar(GmbPost $post): bool
    {
        $post->update([
            'status'     => 'publicando',
            'tentativas' => $post->tentativas + 1,
        ]);

        try {
            // Garante renomeação SEO da imagem com palavras-chave e data/hora antes do envio
            app(GmbImageSeoService::class)->prepararImagemParaPost($post);

            $perfil = $post->perfil;
            $locationId = $perfil?->google_location_id;

            // 1. Monta o Payload do Google Local Post
            $payload = $this->montarPayloadGoogle($post);

            // 2. Busca token Google cadastrado para o tenant (ou token central compartilhado)
            $token = GoogleToken::withoutGlobalScopes()->where('tenant_id', $post->tenant_id)->first()
                ?? GoogleToken::withoutGlobalScopes()->first();

            if ($token && $locationId) {
                $sucesso = $this->enviarParaGoogleApi($token, $locationId, $payload, $post);
                if ($sucesso) {
                    return true;
                }
            } elseif (!$token) {
                $post->update([
                    'status'   => 'falha',
                    'log_erro' => 'Nenhuma conta Google conectada encontrada. Acesse o menu "Integrações" e conecte a conta Google que gerencia os perfis GMB.',
                ]);
            } elseif (!$locationId) {
                $post->update([
                    'status'   => 'falha',
                    'log_erro' => "O perfil GMB '{$perfil?->nome}' não possui o 'ID do Perfil no Google' cadastrado. Acesse GMB → Perfis GMB, edite este perfil e preencha o ID da empresa no Google.",
                ]);
            }

            // 3. Fallback / Webhook de automação n8n se configurado
            $webhookUrl = config('services.gmb.webhook_post_url');
            if ($webhookUrl) {
                $res = Http::timeout(15)->post($webhookUrl, [
                    'post_id'       => $post->id,
                    'tenant_id'     => $post->tenant_id,
                    'perfil_nome'   => $perfil?->nome,
                    'location_id'   => $locationId,
                    'google_payload'=> $payload,
                ]);

                if ($res->successful()) {
                    $json = $res->json();
                    $post->update([
                        'status'          => 'publicado',
                        'publicado_em'    => now(),
                        'google_post_id'  => $json['google_post_id'] ?? 'N8N-PUB-' . time(),
                        'google_post_url' => $json['google_post_url'] ?? null,
                        'log_erro'        => null,
                    ]);
                    return true;
                }
            }

            // Se for teste local ou sem API conectada ainda, simula com sucesso e marca como publicado
            if (app()->environment('local', 'testing') && !$locationId) {
                $post->update([
                    'status'          => 'publicado',
                    'publicado_em'    => now(),
                    'google_post_id'  => 'LOCAL-SIMULADO-' . uniqid(),
                    'google_post_url' => $perfil?->link_gmb,
                    'log_erro'        => 'Publicado em modo simulação local (sem location_id configurado)',
                ]);
                return true;
            }

            // Caso falhe em todos os canais e não tenha erro específico gravado
            $postAtual = $post->fresh();
            if ($postAtual->status !== 'falha' || empty($postAtual->log_erro)) {
                $post->update([
                    'status'   => 'falha',
                    'log_erro' => 'Não foi possível comunicar com a API do Google nem com o Webhook. Verifique a conexão do perfil e credenciais.',
                ]);
            }
            return false;

        } catch (\Exception $e) {
            Log::error('GmbPostPublishService erro ao publicar', [
                'post_id' => $post->id,
                'erro'    => $e->getMessage()
            ]);

            $post->update([
                'status'   => 'falha',
                'log_erro' => 'Exceção: ' . $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Formata o payload padrão exigido pela Google My Business API v4.
     */
    private function montarPayloadGoogle(GmbPost $post): array
    {
        $payload = [
            'languageCode' => 'pt-BR',
            'summary'      => $post->texto,
            'topicType'    => match ($post->tipo) {
                'oferta' => 'OFFER',
                'evento' => 'EVENT',
                default  => 'STANDARD',
            },
        ];

        // Mídia (Imagem)
        if ($post->imagem_url) {
            $payload['media'] = [
                [
                    'mediaFormat' => 'PHOTO',
                    'sourceUrl'   => $post->imagem_url,
                ]
            ];
        }

        // Chamada para Ação (CTA)
        if ($post->cta_tipo && $post->cta_tipo !== 'NENHUM' && $post->cta_url) {
            $payload['callToAction'] = [
                'actionType' => $post->cta_tipo,
                'url'        => $post->cta_url,
            ];
        }

        // Dados de Oferta
        if ($post->tipo === 'oferta') {
            $payload['offer'] = [
                'couponCode'        => $post->codigo_cupom,
                'redeemOnlineUrl'   => $post->link_resgate ?: $post->cta_url,
                'termsConditions'   => $post->termos_condicoes,
            ];

            if ($post->data_inicio_evento && $post->data_fim_evento) {
                $payload['event'] = [
                    'title'    => $post->titulo ?: 'Oferta Especial',
                    'schedule' => [
                        'startDate' => [
                            'year'  => (int)$post->data_inicio_evento->format('Y'),
                            'month' => (int)$post->data_inicio_evento->format('m'),
                            'day'   => (int)$post->data_inicio_evento->format('d'),
                        ],
                        'endDate' => [
                            'year'  => (int)$post->data_fim_evento->format('Y'),
                            'month' => (int)$post->data_fim_evento->format('m'),
                            'day'   => (int)$post->data_fim_evento->format('d'),
                        ],
                    ]
                ];
            }
        }

        // Dados de Evento
        if ($post->tipo === 'evento') {
            $payload['event'] = [
                'title'    => $post->titulo ?: 'Evento Especial',
                'schedule' => [
                    'startDate' => [
                        'year'  => (int)($post->data_inicio_evento?->format('Y') ?? now()->format('Y')),
                        'month' => (int)($post->data_inicio_evento?->format('m') ?? now()->format('m')),
                        'day'   => (int)($post->data_inicio_evento?->format('d') ?? now()->format('d')),
                    ],
                    'endDate' => [
                        'year'  => (int)($post->data_fim_evento?->format('Y') ?? now()->format('Y')),
                        'month' => (int)($post->data_fim_evento?->format('m') ?? now()->format('m')),
                        'day'   => (int)($post->data_fim_evento?->format('d') ?? now()->format('d')),
                    ],
                ]
            ];
        }

        return $payload;
    }

    /**
     * Envia o post diretamente via Google Business Profile REST API.
     */
    private function enviarParaGoogleApi(GoogleToken $token, string $locationId, array $payload, GmbPost $post): bool
    {
        // 1. Garante que o access_token está válido / renova se expirado
        if ($token->expires_at && $token->expires_at->isPast()) {
            app(GoogleService::class)->renovarToken($token);
            $token->refresh();
        }

        $accessToken = $token->access_token;

        // 2. Busca lista de contas do Google Meu Negócio
        $accountRes = Http::withToken($accessToken)
            ->timeout(15)
            ->get('https://mybusinessaccountmanagement.googleapis.com/v1/accounts');

        if ($accountRes->status() === 403) {
            $erroGoogle = $accountRes->json('error.message') ?? $accountRes->body();
            $motivo = (str_contains($erroGoogle, 'SERVICE_DISABLED') || str_contains($erroGoogle, 'has not been used in project'))
                ? 'A API "My Business Account Management API" precisa ser ativada no Google Cloud Console: https://console.developers.google.com/apis/api/mybusinessaccountmanagement.googleapis.com/overview?project=159179119828'
                : 'Permissão do Google Meu Negócio pendente. Reconecte a conta Google em "Integrações". Detalhes: ' . $erroGoogle;

            $post->update([
                'status'   => 'falha',
                'log_erro' => $motivo,
            ]);
            return false;
        }

        $accountsData = $accountRes->json();
        $accountName = $accountsData['accounts'][0]['name'] ?? null;

        if (!$accountName) {
            $post->update([
                'status'   => 'falha',
                'log_erro' => 'Nenhuma conta do Google Meu Negócio encontrada vinculada ao e-mail ' . ($token->google_email ?? 'Google') . '. Verifique se este e-mail é Administrador ou Proprietário do perfil.',
            ]);
            return false;
        }

        // Limpa qualquer prefixo duplicado (ex: "locations/")
        $cleanLocationId = preg_replace('#^locations/#', '', trim($locationId));
        $accName = str_starts_with($accountName, 'accounts/') ? $accountName : "accounts/{$accountName}";

        $url = "https://mybusiness.googleapis.com/v4/{$accName}/locations/{$cleanLocationId}/localPosts";

        $res = Http::withToken($accessToken)
            ->timeout(20)
            ->post($url, $payload);

        if ($res->successful()) {
            $data = $res->json();
            $post->update([
                'status'          => 'publicado',
                'publicado_em'    => now(),
                'google_post_id'  => $data['name'] ?? null,
                'google_post_url' => $data['searchUrl'] ?? null,
                'log_erro'        => null,
            ]);
            return true;
        }

        $status = $res->status();
        $erroGoogle = $res->json('error.message') ?? $res->body();
        Log::warning('Google LocalPost API falhou', [
            'status'   => $status,
            'response' => $res->body(),
            'url'      => $url,
        ]);

        $explicacao = match ($status) {
            403 => "Google retornou 403: A API de Postagens do Google Business Profile ainda aguarda liberação para o projeto Google Cloud (Protocolo 9-4101000041625) ou permissão insuficiente neste local. Detalhes: {$erroGoogle}",
            404 => "Google retornou 404: Localização não encontrada no Google para o ID '{$cleanLocationId}'. Verifique se o ID do Perfil da Empresa está correto em GMB → Perfis GMB. Detalhes: {$erroGoogle}",
            400 => "Google retornou 400 (Dado inválido): {$erroGoogle}",
            default => "Erro Google ({$status}): {$erroGoogle}",
        };

        $post->update([
            'status'   => 'falha',
            'log_erro' => $explicacao,
        ]);

        return false;
    }
}
