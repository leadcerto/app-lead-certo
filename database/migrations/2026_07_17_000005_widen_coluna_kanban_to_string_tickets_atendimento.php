<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            Schema::table('tickets_atendimento', function (Blueprint $table) {
                $table->string('coluna_kanban', 50)->change();
            });

            return;
        }

        DB::statement("ALTER TABLE tickets_atendimento MODIFY coluna_kanban VARCHAR(50) NOT NULL DEFAULT 'lead_novo'");
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            Schema::table('tickets_atendimento', function (Blueprint $table) {
                $table->enum('coluna_kanban', [
                    'lead_novo', 'em_atendimento', 'aguardando_orcamento', 'aguardando_lead',
                    'pagamento', 'servico_agendado', 'encerrado', 'outros',
                ])->default('lead_novo')->change();
            });

            return;
        }

        DB::statement("ALTER TABLE tickets_atendimento MODIFY coluna_kanban ENUM(
            'lead_novo','em_atendimento','aguardando_orcamento','aguardando_lead',
            'pagamento','servico_agendado','encerrado','outros'
        ) NOT NULL DEFAULT 'lead_novo'");
    }
};
