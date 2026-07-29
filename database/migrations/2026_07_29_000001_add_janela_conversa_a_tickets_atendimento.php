<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tickets_atendimento', function (Blueprint $table) {
            $table->timestamp('janela_expira_em')->nullable()->after('whatsapp_canal_id');
            $table->boolean('janela_origem_anuncio')->default(false)->after('janela_expira_em');
        });
    }

    public function down(): void
    {
        Schema::table('tickets_atendimento', function (Blueprint $table) {
            $table->dropColumn(['janela_expira_em', 'janela_origem_anuncio']);
        });
    }
};
