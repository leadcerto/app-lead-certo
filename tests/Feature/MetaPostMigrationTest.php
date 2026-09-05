<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class MetaPostMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_meta_posts_table_has_expected_columns(): void
    {
        $this->assertTrue(Schema::hasTable('meta_posts'));
        $this->assertTrue(Schema::hasColumns('meta_posts', [
            'id', 'tenant_id', 'user_id', 'canal_alvo', 'meta_pagina_id',
            'meta_conta_instagram_id', 'texto', 'imagem_url', 'cta_tipo', 'cta_url',
            'modo_gatilho', 'palavras_chave', 'resposta_publica_comentario', 'mensagem_direct',
            'data_agendada', 'publicado_em', 'status', 'facebook_post_id',
            'instagram_media_id', 'log_erro', 'tentativas', 'created_at', 'updated_at',
        ]));
    }

    public function test_meta_campanhas_gatilho_has_meta_post_id_column(): void
    {
        $this->assertTrue(Schema::hasColumn('meta_campanhas_gatilho', 'meta_post_id'));
    }
}
