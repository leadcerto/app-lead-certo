<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tickets_atendimento', function (Blueprint $table) {
            $table->timestamp('retorno_agendado_em')->nullable()->after('followup_agendado_em');
            $table->index('retorno_agendado_em');
        });
    }

    public function down(): void
    {
        Schema::table('tickets_atendimento', function (Blueprint $table) {
            $table->dropIndex(['retorno_agendado_em']);
            $table->dropColumn('retorno_agendado_em');
        });
    }
};
