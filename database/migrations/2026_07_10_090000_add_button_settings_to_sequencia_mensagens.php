<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sequencia_mensagens', function (Blueprint $table) {
            $table->json('button_settings')->nullable()->after('imagem_url');
        });
    }

    public function down(): void
    {
        Schema::table('sequencia_mensagens', function (Blueprint $table) {
            $table->dropColumn('button_settings');
        });
    }
};
