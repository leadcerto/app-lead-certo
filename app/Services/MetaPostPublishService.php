<?php

namespace App\Services;

use App\Models\MetaCampanhaGatilho;
use App\Models\MetaPost;
use Illuminate\Support\Facades\Log;

class MetaPostPublishService
{
    public function __construct(private MetaService $meta) {}

    public function publicar(MetaPost $post): bool
    {
        $post->update(['tentativas' => $post->tentativas + 1]);

        $sucessoFacebook = $post->facebook_post_id ? true : null;
        $sucessoInstagram = $post->instagram_media_id ? true : null;

        try {
            if (in_array($post->canal_alvo, ['facebook', 'ambos'], true) && ! $post->facebook_post_id) {
                $pagina = $post->pagina;
                if (! $pagina || ! $pagina->page_access_token) {
                    $sucessoFacebook = false;
                    Log::warning('MetaPostPublishService: Página do Facebook não configurada', ['post_id' => $post->id]);
                } else {
                    $id = $this->meta->publicarPostFacebookPage($pagina->facebook_page_id, $pagina->page_access_token, [
                        'legenda'    => $post->texto,
                        'imagem_url' => $post->imagem_url,
                        'link'       => $post->cta_url,
                    ]);
                    $sucessoFacebook = $id !== null;
                    if ($sucessoFacebook) {
                        $post->facebook_post_id = $id;
                    }
                }
            }

            if (in_array($post->canal_alvo, ['instagram', 'ambos'], true) && ! $post->instagram_media_id) {
                $conta = $post->contaInstagram;
                $pageAccessToken = $conta?->pagina?->page_access_token;
                if (! $conta || ! $pageAccessToken) {
                    $sucessoInstagram = false;
                    Log::warning('MetaPostPublishService: Conta do Instagram ou token não configurado', ['post_id' => $post->id]);
                } else {
                    $id = $this->meta->publicarPostInstagram($conta->instagram_business_id, $pageAccessToken, [
                        'legenda'    => $post->texto,
                        'imagem_url' => $post->imagem_url,
                    ]);
                    $sucessoInstagram = $id !== null;
                    if ($sucessoInstagram) {
                        $post->instagram_media_id = $id;
                    }
                }
            }
        } catch (\Exception $e) {
            Log::error('MetaPostPublishService exceção', ['post_id' => $post->id, 'erro' => $e->getMessage()]);
            $post->status = 'falha';
            $post->log_erro = 'Exceção: ' . $e->getMessage();
            $post->save();
            return false;
        }

        $falhouAlgumCanalSolicitado =
            ($post->canal_alvo !== 'instagram' && $sucessoFacebook === false) ||
            ($post->canal_alvo !== 'facebook' && $sucessoInstagram === false);

        if ($falhouAlgumCanalSolicitado) {
            $post->status = 'falha';
            $post->log_erro = $this->montarLogErro($sucessoFacebook, $sucessoInstagram);
            $post->save();
            return false;
        }

        $post->status = 'publicado';
        $post->publicado_em = now();
        $post->log_erro = null;
        $post->save();

        $this->criarGatilhosComentario($post);

        return true;
    }

    private function montarLogErro(?bool $sucessoFacebook, ?bool $sucessoInstagram): string
    {
        $partes = [];
        if ($sucessoFacebook === false) {
            $partes[] = 'Falha ao publicar no Facebook (verifique conexão da página e permissões).';
        }
        if ($sucessoInstagram === false) {
            $partes[] = 'Falha ao publicar no Instagram (verifique conta Business vinculada e URL de imagem válida).';
        }
        return implode(' ', $partes) ?: 'Falha desconhecida ao publicar.';
    }

    private function criarGatilhosComentario(MetaPost $post): void
    {
        if ($post->modo_gatilho === 'nenhum') {
            return;
        }

        $base = [
            'tenant_id'                   => $post->tenant_id,
            'nome'                        => "Auto — Post #{$post->id}",
            'modo_gatilho'                => $post->modo_gatilho,
            'palavras_chave'              => $post->palavras_chave,
            'resposta_publica_comentario' => $post->resposta_publica_comentario,
            'mensagem_direct'             => $post->mensagem_direct ?? '',
            'meta_post_id'                => $post->id,
            'ativo'                       => true,
        ];

        if ($post->facebook_post_id) {
            MetaCampanhaGatilho::create($base + [
                'canal_alvo'         => 'facebook',
                'facebook_pagina_id' => $post->meta_pagina_id,
                'post_id_especifico' => $post->facebook_post_id,
            ]);
        }

        if ($post->instagram_media_id) {
            MetaCampanhaGatilho::create($base + [
                'canal_alvo'         => 'instagram',
                'instagram_conta_id' => $post->meta_conta_instagram_id,
                'post_id_especifico' => $post->instagram_media_id,
            ]);
        }
    }
}
