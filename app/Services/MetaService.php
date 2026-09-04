<?php

namespace App\Services;

use App\Models\MetaContaInstagram;
use App\Models\MetaPagina;
use App\Models\MetaToken;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class MetaService
{
    private const GRAPH_API_VERSION = 'v20.0';
    private const GRAPH_BASE_URL    = 'https://graph.facebook.com/' . self::GRAPH_API_VERSION;
    private const OAUTH_DIALOG_URL  = 'https://www.facebook.com/' . self::GRAPH_API_VERSION . '/dialog/oauth';

    // Escopos homologados para Páginas do Facebook, Instagram Business e Mensagens
    public const SCOPES = [
        'public_profile',
        'pages_show_list',
        'pages_read_engagement',
        'pages_messaging',
        'instagram_basic',
        'business_management',
    ];

    private string $appId;
    private string $appSecret;
    private string $redirectUri;

    public function __construct()
    {
        $this->appId       = (string) config('services.meta.app_id', '');
        $this->appSecret   = (string) config('services.meta.app_secret', '');
        $this->redirectUri = (string) config('services.meta.redirect_uri', '');
    }

    /**
     * Gera URL oficial do Facebook OAuth Dialog.
     */
    public function urlAutorizacao(string $state): string
    {
        return self::OAUTH_DIALOG_URL . '?' . http_build_query([
            'client_id'     => $this->appId,
            'redirect_uri'  => $this->redirectUri,
            'state'         => $state,
            'scope'         => implode(',', self::SCOPES),
            'response_type' => 'code',
        ]);
    }

    /**
     * Troca o código retornado no callback por um access_token de curta duração.
     */
    public function trocarCodigoPorToken(string $code): ?array
    {
        try {
            $res = Http::get(self::GRAPH_BASE_URL . '/oauth/access_token', [
                'client_id'     => $this->appId,
                'client_secret' => $this->appSecret,
                'redirect_uri'  => $this->redirectUri,
                'code'          => $code,
            ]);

            if ($res->successful()) {
                return $res->json();
            }

            Log::error('MetaService::trocarCodigoPorToken falhou', ['body' => $res->body()]);
            return null;
        } catch (\Exception $e) {
            Log::error('MetaService::trocarCodigoPorToken exceção', ['erro' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Converte o token de curta duração em um token de longa duração (60 dias).
     */
    public function obterTokenLongaDuracao(string $shortLivedToken): ?array
    {
        try {
            $res = Http::get(self::GRAPH_BASE_URL . '/oauth/access_token', [
                'grant_type'        => 'fb_exchange_token',
                'client_id'         => $this->appId,
                'client_secret'     => $this->appSecret,
                'fb_exchange_token' => $shortLivedToken,
            ]);

            if ($res->successful()) {
                return $res->json();
            }

            Log::error('MetaService::obterTokenLongaDuracao falhou', ['body' => $res->body()]);
            return null;
        } catch (\Exception $e) {
            Log::error('MetaService::obterTokenLongaDuracao exceção', ['erro' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Lista (sem gravar nada) todas as Páginas do Facebook que este token pode
     * administrar, via /me/accounts. Uma conta pessoal da Meta costuma
     * administrar páginas de VÁRIOS negócios/tenants diferentes — por isso
     * NUNCA gravamos aqui direto; o operador escolhe manualmente quais
     * páginas pertencem a este tenant em vincularPaginasSelecionadas().
     */
    public function listarPaginasDisponiveis(MetaToken $token): array
    {
        try {
            $url = self::GRAPH_BASE_URL . '/me/accounts';
            $res = Http::withToken($token->access_token)->get($url, [
                'fields' => 'id,name,category,access_token,picture{url},instagram_business_account{id,username,name,profile_picture_url}',
                'limit'  => 50,
            ]);

            if (! $res->successful()) {
                Log::error('MetaService::listarPaginasDisponiveis erro', ['body' => $res->body()]);
                return ['sucesso' => false, 'erro' => $res->body(), 'paginas' => []];
            }

            return ['sucesso' => true, 'paginas' => $res->json('data', [])];
        } catch (\Exception $e) {
            Log::error('MetaService::listarPaginasDisponiveis exceção', ['erro' => $e->getMessage()]);
            return ['sucesso' => false, 'erro' => $e->getMessage(), 'paginas' => []];
        }
    }

    /**
     * Grava, para este tenant, SOMENTE as páginas cujo facebook_page_id está
     * em $facebookPageIdsSelecionados — nunca a lista inteira de /me/accounts.
     * Páginas do tenant que não foram selecionadas desta vez são desativadas
     * (não apagadas), para o operador poder reativar sem reconectar tudo.
     */
    public function vincularPaginasSelecionadas(MetaToken $token, array $facebookPageIdsSelecionados): array
    {
        $listagem = $this->listarPaginasDisponiveis($token);
        if (! $listagem['sucesso']) {
            return $listagem;
        }

        try {
            $totalPaginas = 0;
            $totalInstagram = 0;
            $idsVinculados = [];

            foreach ($listagem['paginas'] as $pData) {
                $pageId = $pData['id'];
                if (! in_array($pageId, $facebookPageIdsSelecionados, true)) {
                    continue;
                }

                $pageAccessToken = $pData['access_token'] ?? $token->access_token;
                $fotoUrl = $pData['picture']['data']['url'] ?? null;

                $pagina = MetaPagina::withoutGlobalScopes()->updateOrCreate(
                    [
                        'tenant_id'        => $token->tenant_id,
                        'facebook_page_id' => $pageId,
                    ],
                    [
                        'meta_token_id'     => $token->id,
                        'nome'              => $pData['name'] ?? 'Página Facebook',
                        'categoria'         => $pData['category'] ?? null,
                        'page_access_token' => $pageAccessToken,
                        'foto_url'          => $fotoUrl,
                        'ativo'             => true,
                    ]
                );
                $idsVinculados[] = $pagina->id;
                $totalPaginas++;

                // Sincroniza Instagram Business vinculado, se houver
                if (! empty($pData['instagram_business_account']['id'])) {
                    $igData = $pData['instagram_business_account'];
                    MetaContaInstagram::withoutGlobalScopes()->updateOrCreate(
                        [
                            'tenant_id'             => $token->tenant_id,
                            'instagram_business_id' => $igData['id'],
                        ],
                        [
                            'meta_pagina_id'   => $pagina->id,
                            'username'         => $igData['username'] ?? '',
                            'nome'             => $igData['name'] ?? $igData['username'] ?? '',
                            'foto_perfil_url'  => $igData['profile_picture_url'] ?? null,
                            'ativo'            => true,
                        ]
                    );
                    $totalInstagram++;
                }
            }

            // Desativa (não apaga) páginas deste tenant que ficaram de fora da seleção atual
            MetaPagina::withoutGlobalScopes()
                ->where('tenant_id', $token->tenant_id)
                ->whereNotIn('id', $idsVinculados)
                ->update(['ativo' => false]);

            return [
                'sucesso'         => true,
                'total_paginas'   => $totalPaginas,
                'total_instagram' => $totalInstagram,
            ];
        } catch (\Exception $e) {
            Log::error('MetaService::vincularPaginasSelecionadas exceção', ['erro' => $e->getMessage()]);
            return ['sucesso' => false, 'erro' => $e->getMessage()];
        }
    }

    /**
     * Responde publicamente a um comentário no post (Instagram ou Facebook).
     */
    public function responderComentarioPublico(string $pageAccessToken, string $commentId, string $texto): bool
    {
        try {
            $url = self::GRAPH_BASE_URL . "/{$commentId}/comments";
            $res = Http::withToken($pageAccessToken)->post($url, [
                'message' => $texto,
            ]);

            return $res->successful();
        } catch (\Exception $e) {
            Log::error('MetaService::responderComentarioPublico erro', ['erro' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * Envia mensagem privada no Direct/Messenger respondendo diretamente a um comentário (Private Replies API).
     */
    public function enviarDirectPorComentario(string $pageAccessToken, string $commentId, string $texto): bool
    {
        try {
            $url = self::GRAPH_BASE_URL . '/me/messages';
            $res = Http::withToken($pageAccessToken)->post($url, [
                'recipient' => [
                    'comment_id' => $commentId,
                ],
                'message' => [
                    'text' => $texto,
                ],
            ]);

            if (! $res->successful()) {
                Log::warning('MetaService::enviarDirectPorComentario falhou', [
                    'status' => $res->status(),
                    'body'   => $res->body(),
                ]);
            }

            return $res->successful();
        } catch (\Exception $e) {
            Log::error('MetaService::enviarDirectPorComentario erro', ['erro' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * Envia mensagem no Direct do Instagram ou Messenger do Facebook (para conversas ativas).
     */
    public function enviarDirectParaUsuario(string $accessToken, string $recipientId, string $texto): bool
    {
        try {
            $url = self::GRAPH_BASE_URL . '/me/messages';
            $res = Http::withToken($accessToken)->post($url, [
                'recipient' => [
                    'id' => $recipientId,
                ],
                'message' => [
                    'text' => $texto,
                ],
            ]);

            return $res->successful();
        } catch (\Exception $e) {
            Log::error('MetaService::enviarDirectParaUsuario erro', ['erro' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * Publica post no Instagram em 2 etapas (Padrão Oficial Container + Publish).
     */
    public function publicarPostInstagram(string $igUserId, string $accessToken, array $dados): ?string
    {
        try {
            // 1. Cria o container de mídia
            $containerParams = [
                'caption'      => $dados['legenda'] ?? '',
                'access_token' => $accessToken,
            ];

            if (! empty($dados['imagem_url'])) {
                $containerParams['image_url'] = $dados['imagem_url'];
            } elseif (! empty($dados['video_url'])) {
                $containerParams['video_url']   = $dados['video_url'];
                $containerParams['media_type']  = 'REELS';
            }

            $containerRes = Http::post(self::GRAPH_BASE_URL . "/{$igUserId}/media", $containerParams);

            if (! $containerRes->successful()) {
                Log::error('Instagram Create Container falhou', ['body' => $containerRes->body()]);
                return null;
            }

            $creationId = $containerRes->json('id');
            if (! $creationId) {
                return null;
            }

            // 2. Dispara a publicação do container criado
            $publishRes = Http::post(self::GRAPH_BASE_URL . "/{$igUserId}/media_publish", [
                'creation_id'  => $creationId,
                'access_token' => $accessToken,
            ]);

            if ($publishRes->successful()) {
                return $publishRes->json('id');
            }

            Log::error('Instagram Publish Container falhou', ['body' => $publishRes->body()]);
            return null;
        } catch (\Exception $e) {
            Log::error('MetaService::publicarPostInstagram erro', ['erro' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Publica post em uma Página do Facebook (Texto, Imagem ou Vídeo).
     */
    public function publicarPostFacebookPage(string $pageId, string $pageAccessToken, array $dados): ?string
    {
        try {
            if (! empty($dados['imagem_url'])) {
                $url = self::GRAPH_BASE_URL . "/{$pageId}/photos";
                $res = Http::post($url, [
                    'url'          => $dados['imagem_url'],
                    'caption'      => $dados['legenda'] ?? $dados['texto'] ?? '',
                    'access_token' => $pageAccessToken,
                ]);
            } elseif (! empty($dados['video_url'])) {
                $url = self::GRAPH_BASE_URL . "/{$pageId}/videos";
                $res = Http::post($url, [
                    'file_url'     => $dados['video_url'],
                    'description'  => $dados['legenda'] ?? $dados['texto'] ?? '',
                    'access_token' => $pageAccessToken,
                ]);
            } else {
                $url = self::GRAPH_BASE_URL . "/{$pageId}/feed";
                $res = Http::post($url, [
                    'message'      => $dados['legenda'] ?? $dados['texto'] ?? '',
                    'link'         => $dados['link'] ?? null,
                    'access_token' => $pageAccessToken,
                ]);
            }

            if ($res->successful()) {
                return $res->json('id') ?? $res->json('post_id');
            }

            Log::error('Facebook Page Publish falhou', ['body' => $res->body()]);
            return null;
        } catch (\Exception $e) {
            Log::error('MetaService::publicarPostFacebookPage erro', ['erro' => $e->getMessage()]);
            return null;
        }
    }
}
