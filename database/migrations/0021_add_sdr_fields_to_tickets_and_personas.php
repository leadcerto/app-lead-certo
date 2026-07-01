<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sdr_personas', function (Blueprint $table) {
            $table->enum('tier', ['simples', 'complexo'])->default('simples')->after('is_default');
        });

        Schema::table('tickets_atendimento', function (Blueprint $table) {
            $table->foreignId('sdr_persona_id')
                ->nullable()
                ->after('agente_responsavel')
                ->constrained('sdr_personas')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('tickets_atendimento', function (Blueprint $table) {
            $table->dropForeign(['sdr_persona_id']);
            $table->dropColumn('sdr_persona_id');
        });

        Schema::table('sdr_personas', function (Blueprint $table) {
            $table->dropColumn('tier');
        });
    }
};
