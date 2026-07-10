<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Sintaxe MODIFY COLUMN é exclusiva do MySQL/MariaDB; em sqlite (usado nos
        // testes automatizados) este ALTER não existe. Usamos o schema builder nativo
        // do Laravel, que sob sqlite recria a tabela para ampliar a CHECK constraint.
        if (DB::getDriverName() !== 'mysql') {
            Schema::table('tickets_atendimento', function (Blueprint $table) {
                $table->enum('status', ['aberto', 'pendente', 'resolvido', 'encerrado'])
                    ->default('aberto')
                    ->change();
            });

            return;
        }

        DB::statement("ALTER TABLE tickets_atendimento MODIFY COLUMN status ENUM('aberto','pendente','resolvido','encerrado') NOT NULL DEFAULT 'aberto'");
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            DB::table('tickets_atendimento')
                ->whereIn('status', ['pendente', 'resolvido'])
                ->update(['status' => 'aberto']);

            Schema::table('tickets_atendimento', function (Blueprint $table) {
                $table->enum('status', ['aberto', 'encerrado'])
                    ->default('aberto')
                    ->change();
            });

            return;
        }

        DB::statement("UPDATE tickets_atendimento SET status = 'aberto' WHERE status IN ('pendente','resolvido')");
        DB::statement("ALTER TABLE tickets_atendimento MODIFY COLUMN status ENUM('aberto','encerrado') NOT NULL DEFAULT 'aberto'");
    }
};
