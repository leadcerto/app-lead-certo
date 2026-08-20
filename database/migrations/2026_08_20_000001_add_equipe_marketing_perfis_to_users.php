<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Amplia o ENUM/CHECK da coluna 'perfil' (definido em
     * 2026_08_17_000001_add_avaliador_perfil_and_geo_to_users.php) pra
     * aceitar os perfis da "equipe de marketing compartilhada" (ver
     * TAREFAS.md, "Equipe Lead Certo com acesso universal a todas as
     * empresas", 2026-08-20): 'diretor_marketing' (já usado pela Nathanel)
     * e 4 sub-perfis ainda dormentes (nenhum usuário tem hoje), criados
     * de antemão pra ativação futura sob demanda, um de cada vez.
     */
    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            Schema::table('users', function (Blueprint $table) {
                $table->enum('perfil', [
                    'admin', 'dono', 'diretor', 'gerente', 'gestor', 'vendedor',
                    'auditor', 'growth_manager', 'revops', 'pos_venda', 'avaliador',
                    'diretor_marketing', 'gestor_trafego', 'gestor_criacao',
                    'gestor_copywriting', 'gestor_seo',
                ])->default('vendedor')->change();
            });

            return;
        }

        DB::statement("
            ALTER TABLE users
            MODIFY COLUMN perfil ENUM(
                'admin', 'dono', 'diretor', 'gerente', 'gestor', 'vendedor',
                'auditor', 'growth_manager', 'revops', 'pos_venda', 'avaliador',
                'diretor_marketing', 'gestor_trafego', 'gestor_criacao',
                'gestor_copywriting', 'gestor_seo'
            ) NOT NULL DEFAULT 'vendedor'
        ");
    }

    public function down(): void
    {
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
};
