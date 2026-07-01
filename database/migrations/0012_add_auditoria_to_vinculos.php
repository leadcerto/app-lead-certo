<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vinculos_contato_tenant', function (Blueprint $table) {
            // Nome sugerido pelo parceiro — aguarda aprovação do Auditor
            $table->string('nome_sugerido', 200)->nullable()->after('google_given_name');
            // Flag: há uma edição pendente de auditoria neste vínculo
            $table->boolean('auditoria_pendente')->default(false)->after('nome_sugerido');
        });
    }

    public function down(): void
    {
        Schema::table('vinculos_contato_tenant', function (Blueprint $table) {
            $table->dropColumn(['nome_sugerido', 'auditoria_pendente']);
        });
    }
};
