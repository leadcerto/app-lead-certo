<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * ID do Perfil da Empresa no Google (Configurações avançadas do GMB) —
     * necessário pra futura integração com a Business Profile API
     * (Campanhas de Ofertas / Posts). Nullable: preenchido aos poucos,
     * perfil por perfil, conforme o admin coleta o ID no painel do Google.
     */
    public function up(): void
    {
        Schema::table('perfis_gmb', function (Blueprint $table) {
            $table->string('google_location_id', 60)->nullable()->after('link_gmb');
        });
    }

    public function down(): void
    {
        Schema::table('perfis_gmb', function (Blueprint $table) {
            $table->dropColumn('google_location_id');
        });
    }
};
