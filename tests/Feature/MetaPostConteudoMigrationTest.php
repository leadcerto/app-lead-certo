<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class MetaPostConteudoMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_meta_post_conteudos_table_has_expected_columns(): void
    {
        $this->assertTrue(Schema::hasTable('meta_post_conteudos'));
        $this->assertTrue(Schema::hasColumns('meta_post_conteudos', [
            'id', 'tenant_id', 'categoria', 'titulo', 'texto', 'imagem_url',
            'cta_tipo', 'cta_url', 'modo_gatilho', 'palavras_chave',
            'resposta_publica_comentario', 'mensagem_direct', 'ativo',
            'created_at', 'updated_at',
        ]));
    }
}
