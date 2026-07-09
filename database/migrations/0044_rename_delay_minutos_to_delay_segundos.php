<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sequencia_mensagens', function (Blueprint $table) {
            $table->renameColumn('delay_minutos', 'delay_segundos');
        });
    }

    public function down(): void
    {
        Schema::table('sequencia_mensagens', function (Blueprint $table) {
            $table->renameColumn('delay_segundos', 'delay_minutos');
        });
    }
};
