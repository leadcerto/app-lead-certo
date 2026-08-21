<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('idioma', 5)->default('pt-BR')->after('perfil');
        });

        Schema::table('tenants', function (Blueprint $table) {
            $table->string('locale', 5)->default('pt-BR')->after('nome');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('idioma');
        });
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn('locale');
        });
    }
};
