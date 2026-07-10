<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vinculos_contato_tenant', function (Blueprint $table) {
            $table->timestamp('bloqueado_em')->nullable()->after('desativado_em');
        });
    }

    public function down(): void
    {
        Schema::table('vinculos_contato_tenant', function (Blueprint $table) {
            $table->dropColumn('bloqueado_em');
        });
    }
};
