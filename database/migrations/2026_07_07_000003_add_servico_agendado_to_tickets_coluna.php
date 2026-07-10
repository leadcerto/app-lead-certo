<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Sintaxe MODIFY é exclusiva do MySQL/MariaDB; em sqlite (usado nos
        // testes automatizados) este ALTER não existe e quebraria toda a suite.
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        DB::statement("ALTER TABLE tickets_atendimento MODIFY coluna_kanban ENUM(
            'lead_novo','em_atendimento','aguardando_orcamento','aguardando_lead',
            'servico_agendado','encerrado','outros'
        ) NOT NULL DEFAULT 'lead_novo'");
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        DB::statement("ALTER TABLE tickets_atendimento MODIFY coluna_kanban ENUM(
            'lead_novo','em_atendimento','aguardando_orcamento','aguardando_lead',
            'encerrado','outros'
        ) NOT NULL DEFAULT 'lead_novo'");
    }
};
