<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vinculos_contato_tenant', function (Blueprint $table) {
            $table->string('google_resource_name', 100)->nullable()->after('tenant_id');
            $table->text('google_etag')->nullable()->after('google_resource_name');
            $table->string('google_given_name', 200)->nullable()->after('google_etag');
        });
    }

    public function down(): void
    {
        Schema::table('vinculos_contato_tenant', function (Blueprint $table) {
            $table->dropColumn(['google_resource_name', 'google_etag', 'google_given_name']);
        });
    }
};
