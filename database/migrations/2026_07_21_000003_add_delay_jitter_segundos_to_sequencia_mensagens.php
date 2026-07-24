<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sequencia_mensagens', function (Blueprint $table) {
            $table->unsignedInteger('delay_jitter_segundos')->default(0)->after('delay_segundos');
        });
    }

    public function down(): void
    {
        Schema::table('sequencia_mensagens', function (Blueprint $table) {
            $table->dropColumn('delay_jitter_segundos');
        });
    }
};
