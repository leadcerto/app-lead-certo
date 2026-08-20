<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Página de perfil do agente, pedida pelo Leonardo em 2026-08-20 — identidade
 * (nome/e-mail/foto/whatsapp, já existentes em `users` menos a foto),
 * vínculo com os cargos da estrutura organizacional (um agente pode ocupar
 * vários cargos ao mesmo tempo, ex.: Nathanel = Diretora de Marketing +
 * Gestor Comercial), e o histórico de serviços executados por ele.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('avatar_url', 500)->nullable()->after('whatsapp');
        });

        Schema::create('cargos', function (Blueprint $table) {
            $table->id();
            $table->string('nome', 100);
            $table->text('descricao');
            // Hierarquia simples pro organograma (ex.: Gestor de Tráfego
            // reporta pra Diretora de Marketing) — opcional, null = topo.
            $table->foreignId('cargo_pai_id')->nullable()->constrained('cargos')->nullOnDelete();
            $table->unsignedSmallInteger('ordem')->default(1);
            $table->boolean('ativo')->default(true);
            $table->timestamps();
        });

        Schema::create('agente_cargo', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('cargo_id')->constrained('cargos')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['user_id', 'cargo_id']);
        });

        Schema::create('servicos_executados', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('descricao', 500);
            $table->string('motivo', 500)->nullable();
            $table->enum('grau_dificuldade', ['baixo', 'medio', 'alto'])->default('medio');
            $table->unsignedInteger('tempo_gasto_minutos')->nullable();
            $table->timestamp('executado_em');
            $table->timestamps();

            $table->index(['user_id', 'executado_em']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('servicos_executados');
        Schema::dropIfExists('agente_cargo');
        Schema::dropIfExists('cargos');

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('avatar_url');
        });
    }
};
