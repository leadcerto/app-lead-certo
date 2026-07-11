<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tickets_atendimento', function (Blueprint $table) {
            // Etiqueta independente ("tenho uma pergunta em aberto com o lead,
            // aguardando resposta") — não mexe em coluna nem em status, não
            // afeta nenhuma automação. Substitui o antigo status='pendente'.
            $table->timestamp('pendente_desde')->nullable()->after('followup_estagio_enviado');
        });
    }

    public function down(): void
    {
        Schema::table('tickets_atendimento', function (Blueprint $table) {
            $table->dropColumn('pendente_desde');
        });
    }
};
