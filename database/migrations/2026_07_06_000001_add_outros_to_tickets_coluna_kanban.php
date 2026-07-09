<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("
            ALTER TABLE tickets_atendimento
            MODIFY coluna_kanban ENUM(
                'lead_novo','em_atendimento','aguardando_orcamento','aguardando_lead','encerrado','outros'
            ) NOT NULL DEFAULT 'lead_novo'
        ");
    }

    public function down(): void
    {
        DB::statement("
            ALTER TABLE tickets_atendimento
            MODIFY coluna_kanban ENUM(
                'lead_novo','em_atendimento','aguardando_orcamento','aguardando_lead','encerrado'
            ) NOT NULL DEFAULT 'lead_novo'
        ");
    }
};
