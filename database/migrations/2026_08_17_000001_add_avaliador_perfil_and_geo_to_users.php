<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Adiciona campos geográficos (city, state) à tabela users
     * para permitir o match automático avaliador ↔ perfil GMB, e amplia o
     * ENUM/CHECK da coluna 'perfil' (definido em 0018_expand_perfil_users.php)
     * pra aceitar o valor 'avaliador' — sem isso, nenhum usuário conseguiria
     * ser criado com esse perfil (violação de constraint em produção).
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('city', 100)->nullable()->after('perfil');
            $table->char('state', 2)->nullable()->after('city');

            $table->index(['perfil', 'city', 'state'], 'idx_users_avaliador_geo');
        });

        if (DB::getDriverName() !== 'mysql') {
            Schema::table('users', function (Blueprint $table) {
                $table->enum('perfil', [
                    'admin', 'dono', 'diretor', 'gerente', 'gestor', 'vendedor',
                    'auditor', 'growth_manager', 'revops', 'pos_venda', 'avaliador',
                ])->default('vendedor')->change();
            });

            return;
        }

        DB::statement("
            ALTER TABLE users
            MODIFY COLUMN perfil ENUM(
                'admin', 'dono', 'diretor', 'gerente', 'gestor', 'vendedor',
                'auditor', 'growth_manager', 'revops', 'pos_venda', 'avaliador'
            ) NOT NULL DEFAULT 'vendedor'
        ");
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex('idx_users_avaliador_geo');
            $table->dropColumn(['city', 'state']);
        });

        if (DB::getDriverName() !== 'mysql') {
            Schema::table('users', function (Blueprint $table) {
                $table->enum('perfil', [
                    'admin', 'dono', 'diretor', 'gerente', 'gestor', 'vendedor',
                    'auditor', 'growth_manager', 'revops', 'pos_venda',
                ])->default('vendedor')->change();
            });

            return;
        }

        DB::statement("
            ALTER TABLE users
            MODIFY COLUMN perfil ENUM(
                'admin', 'dono', 'diretor', 'gerente', 'gestor', 'vendedor',
                'auditor', 'growth_manager', 'revops', 'pos_venda'
            ) NOT NULL DEFAULT 'vendedor'
        ");
    }
};
