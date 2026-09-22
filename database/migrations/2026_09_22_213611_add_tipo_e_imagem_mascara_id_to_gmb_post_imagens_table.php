<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('gmb_post_imagens', function (Blueprint $table) {
            // 'fundo' = foto crua (enviada ou gerada por IA), pronta pra virar
            // material de fundo; 'pronta' = já passou pela máscara, pronta pra postar.
            $table->enum('tipo', ['fundo', 'pronta'])->default('fundo')->after('tenant_id');
            $table->foreignId('imagem_mascara_id')->nullable()->after('tipo')
                ->constrained('imagem_mascaras')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('gmb_post_imagens', function (Blueprint $table) {
            $table->dropConstrainedForeignId('imagem_mascara_id');
            $table->dropColumn('tipo');
        });
    }
};
