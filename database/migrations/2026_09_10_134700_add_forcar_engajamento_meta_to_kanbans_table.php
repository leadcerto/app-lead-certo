<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kanbans', function (Blueprint $table) {
            $table->boolean('forcar_engajamento_meta')->default(true)->after('conhecimento_geral');
        });
    }

    public function down(): void
    {
        Schema::table('kanbans', function (Blueprint $table) {
            $table->dropColumn('forcar_engajamento_meta');
        });
    }
};
