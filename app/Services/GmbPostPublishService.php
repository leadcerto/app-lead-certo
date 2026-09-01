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

            // 2. Se houver token Google cadastrado para o tenant
            $token = GoogleToken::where('tenant_id', $post->tenant_id)->first();

            if ($token && $locationId) {
                $sucesso = $this->enviarParaGoogleApi($token, $locationId, $payload, $post);
                if ($sucesso) {
                    return true;
                }
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

            // Caso falhe em todos os canais
            $post->update([
                'status'   => 'falha',
                'log_erro' => 'Não foi possível comunicar com a API do Google nem com o Webhook. Verifique a conexão do perfil e credenciais.',
            ]);
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
        $accessToken = $token->access_token;
        $url = "https://mybusiness.googleapis.com/v4/accounts/{$token->account_id}/locations/{$locationId}/localPosts";

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

        Log::warning('Google LocalPost API falhou', [
            'status'   => $res->status(),
            'response' => $res->body(),
        ]);

        return false;
    }
}
