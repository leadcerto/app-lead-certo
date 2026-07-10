<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tickets_atendimento', function (Blueprint $table) {
            // Guarda os botões que foram efetivamente enviados ao lead por último,
            // pra validar o clique que volta pelo webhook contra o que foi mandado
            // de verdade — não contra uma config que pode ter mudado desde o envio.
            $table->json('botoes_ativos')->nullable()->after('resumo_ia');
        });
    }

    public function down(): void
    {
        Schema::table('tickets_atendimento', function (Blueprint $table) {
            $table->dropColumn('botoes_ativos');
        });
    }
};
