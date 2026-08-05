<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kanbans', function (Blueprint $table) {
            $table->text('conhecimento_geral')->nullable()->after('nome');
        });
    }

    public function down(): void
    {
        Schema::table('kanbans', function (Blueprint $table) {
            $table->dropColumn('conhecimento_geral');
        });
    }
};
