<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vinculos_contato_tenant', function (Blueprint $table) {
            $table->timestamp('google_sincronizado_em')->nullable()->after('google_etag')->index();
        });
    }

    public function down(): void
    {
        Schema::table('vinculos_contato_tenant', function (Blueprint $table) {
            $table->dropColumn('google_sincronizado_em');
        });
    }
};
