<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('google_tokens', function (Blueprint $table) {
            $table->timestamp('falha_renovacao_em')->nullable()->after('expires_at');
        });
    }

    public function down(): void
    {
        Schema::table('google_tokens', function (Blueprint $table) {
            $table->dropColumn('falha_renovacao_em');
        });
    }
};
