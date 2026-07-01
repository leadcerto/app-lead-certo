<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Remover FKs antes de renomear a tabela pai
        Schema::table('vinculos_consumidor_tenant', function (Blueprint $table) {
            $table->dropForeign(['consumidor_id']);
        });

        Schema::table('tickets_atendimento', function (Blueprint $table) {
            $table->dropForeign(['consumidor_id']);
        });

        // 2. Renomear tabela principal
        Schema::rename('consumidores', 'contatos');

        // 3. Renomear coluna + recriar FK em vinculos
        Schema::table('vinculos_consumidor_tenant', function (Blueprint $table) {
            $table->renameColumn('consumidor_id', 'contato_id');
        });
        Schema::table('vinculos_consumidor_tenant', function (Blueprint $table) {
            $table->foreign('contato_id')->references('id')->on('contatos')->cascadeOnDelete();
        });

        // 4. Renomear a tabela de vinculos
        Schema::rename('vinculos_consumidor_tenant', 'vinculos_contato_tenant');

        // 5. Renomear coluna + recriar FK em tickets
        Schema::table('tickets_atendimento', function (Blueprint $table) {
            $table->renameColumn('consumidor_id', 'contato_id');
        });
        Schema::table('tickets_atendimento', function (Blueprint $table) {
            $table->foreign('contato_id')->references('id')->on('contatos');
        });
    }

    public function down(): void
    {
        Schema::table('vinculos_contato_tenant', function (Blueprint $table) {
            $table->dropForeign(['contato_id']);
        });
        Schema::table('tickets_atendimento', function (Blueprint $table) {
            $table->dropForeign(['contato_id']);
        });

        Schema::rename('contatos', 'consumidores');

        Schema::table('vinculos_contato_tenant', function (Blueprint $table) {
            $table->renameColumn('contato_id', 'consumidor_id');
        });
        Schema::table('vinculos_contato_tenant', function (Blueprint $table) {
            $table->foreign('consumidor_id')->references('id')->on('consumidores')->cascadeOnDelete();
        });

        Schema::rename('vinculos_contato_tenant', 'vinculos_consumidor_tenant');

        Schema::table('tickets_atendimento', function (Blueprint $table) {
            $table->renameColumn('contato_id', 'consumidor_id');
        });
        Schema::table('tickets_atendimento', function (Blueprint $table) {
            $table->foreign('consumidor_id')->references('id')->on('consumidores');
        });
    }
};
