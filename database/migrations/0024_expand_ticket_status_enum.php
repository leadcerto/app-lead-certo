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

        DB::statement("ALTER TABLE tickets_atendimento MODIFY COLUMN status ENUM('aberto','pendente','resolvido','encerrado') NOT NULL DEFAULT 'aberto'");
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        DB::statement("UPDATE tickets_atendimento SET status = 'aberto' WHERE status IN ('pendente','resolvido')");
        DB::statement("ALTER TABLE tickets_atendimento MODIFY COLUMN status ENUM('aberto','encerrado') NOT NULL DEFAULT 'aberto'");
    }
};
