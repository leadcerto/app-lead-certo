<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (!Schema::hasColumn('users', 'openrouter_modelo')) {
                $table->string('openrouter_modelo')->nullable()->default('openai/gpt-4o-mini')->after('provedor_ia');
            }
            if (!Schema::hasColumn('users', 'openrouter_modelos_permitidos')) {
                $table->json('openrouter_modelos_permitidos')->nullable()->after('openrouter_modelo');
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['openrouter_modelo', 'openrouter_modelos_permitidos']);
        });
    }
};
