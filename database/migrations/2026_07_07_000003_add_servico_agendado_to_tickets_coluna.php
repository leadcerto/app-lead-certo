<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Sintaxe MODIFY é exclusiva do MySQL/MariaDB; em sqlite (usado nos
        // testes automatizados) este ALTER não existe. Usamos o schema builder nativo
        // do Laravel, que sob sqlite recria a tabela para ampliar a CHECK constraint.
        if (DB::getDriverName() !== 'mysql') {
            Schema::table('tickets_atendimento', function (Blueprint $table) {
                $table->enum('coluna_kanban', [
                    'lead_novo', 'em_atendimento', 'aguardando_orcamento', 'aguardando_lead',
                    'servico_agendado', 'encerrado', 'outros',
                ])->default('lead_novo')->change();
            });

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
            Schema::table('tickets_atendimento', function (Blueprint $table) {
                $table->enum('coluna_kanban', [
                    'lead_novo', 'em_atendimento', 'aguardando_orcamento', 'aguardando_lead',
                    'encerrado', 'outros',
                ])->default('lead_novo')->change();
            });

            return;
        }

        DB::statement("ALTER TABLE tickets_atendimento MODIFY coluna_kanban ENUM(
            'lead_novo','em_atendimento','aguardando_orcamento','aguardando_lead',
            'encerrado','outros'
        ) NOT NULL DEFAULT 'lead_novo'");
    }
};
