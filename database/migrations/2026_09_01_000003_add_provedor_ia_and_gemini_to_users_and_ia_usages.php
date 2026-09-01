<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (!Schema::hasColumn('users', 'provedor_ia')) {
                $table->string('provedor_ia', 50)->default('openrouter')->after('is_ia');
            }
            if (!Schema::hasColumn('users', 'gemini_api_key')) {
                $table->text('gemini_api_key')->nullable()->after('gemini_email');
            }
            if (!Schema::hasColumn('users', 'gemini_modelo')) {
                $table->string('gemini_modelo', 100)->default('gemini-1.5-pro')->nullable()->after('gemini_api_key');
            }
        });

        Schema::table('ia_usages', function (Blueprint $table) {
            if (!Schema::hasColumn('ia_usages', 'agente_id')) {
                $table->unsignedBigInteger('agente_id')->nullable()->index()->after('tenant_id');
            }
            if (!Schema::hasColumn('ia_usages', 'provedor')) {
                $table->string('provedor', 50)->default('openrouter')->after('modelo');
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['provedor_ia', 'gemini_api_key', 'gemini_modelo']);
        });

        Schema::table('ia_usages', function (Blueprint $table) {
            $table->dropColumn(['agente_id', 'provedor']);
        });
    }
};
