<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE tickets_atendimento MODIFY COLUMN status ENUM('aberto','pendente','resolvido','encerrado') NOT NULL DEFAULT 'aberto'");
    }

    public function down(): void
    {
        DB::statement("UPDATE tickets_atendimento SET status = 'aberto' WHERE status IN ('pendente','resolvido')");
        DB::statement("ALTER TABLE tickets_atendimento MODIFY COLUMN status ENUM('aberto','encerrado') NOT NULL DEFAULT 'aberto'");
    }
};
