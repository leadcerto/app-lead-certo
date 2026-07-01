<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contatos', function (Blueprint $table) {
            // ── Campos do Google Contacts ──────────────────────────────────
            $table->string('email', 200)->nullable()->after('telefone');
            $table->string('nome_do_meio', 100)->nullable()->after('nome');
            $table->string('empresa', 200)->nullable()->after('profissao');
            $table->date('aniversario')->nullable()->after('empresa');
            $table->string('endereco', 300)->nullable()->after('aniversario');
            $table->string('cidade', 100)->nullable()->after('endereco');
            $table->string('estado', 50)->nullable()->after('cidade');
            $table->string('cep', 20)->nullable()->after('estado');
            $table->string('pais', 50)->nullable()->after('cep');
            $table->string('website', 300)->nullable()->after('pais');
            $table->text('observacoes')->nullable()->after('website');

            // ── Campos proprietários Lead Certo ────────────────────────────
            $table->string('tipo', 30)->nullable()->default('lead')->after('observacoes');
            $table->unsignedTinyInteger('score')->nullable()->after('tipo');
            $table->json('tags')->nullable()->after('score');
        });
    }

    public function down(): void
    {
        Schema::table('contatos', function (Blueprint $table) {
            $table->dropColumn([
                'email', 'nome_do_meio', 'empresa', 'aniversario',
                'endereco', 'cidade', 'estado', 'cep', 'pais',
                'website', 'observacoes', 'tipo', 'score', 'tags',
            ]);
        });
    }
};
