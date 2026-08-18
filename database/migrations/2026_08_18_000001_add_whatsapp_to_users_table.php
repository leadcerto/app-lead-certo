<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * WhatsApp do usuário — usado hoje pelo cadastro de avaliadores (a
     * empresa terceirizada precisa de um jeito de contatar cada um fora
     * do e-mail).
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('whatsapp', 30)->nullable()->after('state');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('whatsapp');
        });
    }
};
