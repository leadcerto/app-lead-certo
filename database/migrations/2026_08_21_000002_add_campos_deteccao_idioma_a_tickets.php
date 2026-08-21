<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tickets_atendimento', function (Blueprint $table) {
            $table->string('idioma_pais_ddi', 5)->nullable()->after('idioma_lead');
            $table->enum('idioma_origem', ['ddi', 'botao', 'ia', 'manual'])->nullable()->after('idioma_pais_ddi');
            $table->decimal('idioma_confianca', 3, 2)->nullable()->after('idioma_origem');
            $table->timestamp('idioma_atualizado_em')->nullable()->after('idioma_confianca');
            $table->boolean('idioma_aguardando_escolha')->default(false)->after('idioma_atualizado_em');
        });
    }

    public function down(): void
    {
        Schema::table('tickets_atendimento', function (Blueprint $table) {
            $table->dropColumn(['idioma_pais_ddi', 'idioma_origem', 'idioma_confianca', 'idioma_atualizado_em', 'idioma_aguardando_escolha']);
        });
    }
};
