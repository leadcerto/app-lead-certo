<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->string('email', 150)->nullable()->after('nome');
            $table->string('dominio', 100)->nullable()->after('email');
            $table->string('telefone', 30)->nullable()->after('dominio');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn(['email', 'dominio', 'telefone']);
        });
    }
};
