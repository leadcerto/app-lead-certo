<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Sintaxe MODIFY COLUMN é exclusiva do MySQL/MariaDB; em sqlite (usado nos
        // testes automatizados) este ALTER não existe e quebraria toda a suite.
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        // MariaDB/MySQL: ALTER ENUM para incluir todos os perfis da arquitetura híbrida
        DB::statement("
            ALTER TABLE users
            MODIFY COLUMN perfil ENUM(
                'admin',           -- Lead Certo system admin
                'dono',            -- Dono da empresa parceira (acesso total ao tenant)
                'diretor',         -- Diretor (coordena gerentes)
                'gerente',         -- Gerente (coordena vendedores)
                'gestor',          -- Legado → equivale a gerente
                'vendedor',        -- Vendedor humano
                'auditor',         -- Auditor híbrido (humano aprova o que a IA não resolve)
                'growth_manager',  -- Define estratégia das campanhas de mineração
                'revops',          -- Analista de métricas (somente leitura)
                'pos_venda'        -- Equipe pós-venda (pesquisa NPS, indicações)
            ) NOT NULL DEFAULT 'vendedor'
        ");
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        DB::statement("
            ALTER TABLE users
            MODIFY COLUMN perfil ENUM('admin', 'gestor', 'vendedor') NOT NULL DEFAULT 'vendedor'
        ");
    }
};
