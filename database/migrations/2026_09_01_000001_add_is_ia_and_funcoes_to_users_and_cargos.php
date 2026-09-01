<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Campos de IA e Gemini em users
        Schema::table('users', function (Blueprint $table) {
            if (!Schema::hasColumn('users', 'is_ia')) {
                $table->boolean('is_ia')->default(false)->after('ativo');
            }
            if (!Schema::hasColumn('users', 'gemini_email')) {
                $table->string('gemini_email', 255)->nullable()->after('is_ia');
            }
            if (!Schema::hasColumn('users', 'gemini_instrucoes')) {
                $table->text('gemini_instrucoes')->nullable()->after('gemini_email');
            }
        });

        // 2. Amplia enum perfil em users
        if (DB::getDriverName() === 'mysql') {
            DB::statement("
                ALTER TABLE users
                MODIFY COLUMN perfil ENUM(
                    'admin', 'dono', 'diretor', 'gerente', 'coordenador', 'gestor', 'vendedor', 'sdr',
                    'auditor', 'growth_manager', 'revops', 'pos_venda', 'avaliador',
                    'diretor_marketing', 'gestor_trafego', 'gestor_criacao',
                    'gestor_copywriting', 'gestor_seo'
                ) NOT NULL DEFAULT 'vendedor'
            ");
        }

        // 3. Campos tipo e icone em cargos
        Schema::table('cargos', function (Blueprint $table) {
            if (!Schema::hasColumn('cargos', 'tipo')) {
                $table->string('tipo', 50)->default('operacional')->after('nome');
            }
            if (!Schema::hasColumn('cargos', 'icone')) {
                $table->string('icone', 50)->nullable()->after('tipo');
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'gemini_instrucoes')) {
                $table->dropColumn('gemini_instrucoes');
            }
            if (Schema::hasColumn('users', 'gemini_email')) {
                $table->dropColumn('gemini_email');
            }
            if (Schema::hasColumn('users', 'is_ia')) {
                $table->dropColumn('is_ia');
            }
        });

        Schema::table('cargos', function (Blueprint $table) {
            if (Schema::hasColumn('cargos', 'icone')) {
                $table->dropColumn('icone');
            }
            if (Schema::hasColumn('cargos', 'tipo')) {
                $table->dropColumn('tipo');
            }
        });
    }
};
