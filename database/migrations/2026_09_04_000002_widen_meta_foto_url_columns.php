<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * As URLs de foto/avatar retornadas pelo Graph API da Meta (CDN do Facebook/
 * Instagram) frequentemente ultrapassam 500 caracteres por causa dos
 * parâmetros de assinatura (_nc_ohc, oh, oe, etc). O varchar(500) original
 * causava "SQLSTATE[22001]: String data, right truncated" e derrubava
 * silenciosamente a sincronização de páginas/contas na conexão Meta.
 * Usamos SQL puro (sem doctrine/dbal) para o MODIFY COLUMN.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE meta_paginas MODIFY foto_url TEXT NULL');
        DB::statement('ALTER TABLE meta_contas_instagram MODIFY foto_perfil_url TEXT NULL');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE meta_paginas MODIFY foto_url VARCHAR(500) NULL');
        DB::statement('ALTER TABLE meta_contas_instagram MODIFY foto_perfil_url VARCHAR(500) NULL');
    }
};
